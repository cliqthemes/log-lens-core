<?php
declare(strict_types=1);

namespace LogLens\Plugins;

use LogLens\Http\LogLensRequest;
use LogLens\Http\LogLensResponse;
use LogLens\Storage\Connection;
use LogLens\Storage\Dialects;
use LogLens\Storage\PdoConnection;

use InvalidArgumentException;
use PDO;

/**
 * The optional-feature registry that keeps Log Lens as simple or as complex as
 * each workspace wants.
 *
 * Every non-core capability is a "plugin": it appears in Settings → Plugins and
 * can be toggled per application. When a plugin is disabled its routes 404 and
 * its UI is hidden, so a fresh install ships lean and teams opt into complexity.
 *
 * The catalog itself comes from {@see PluginRegistry}, so built-in and
 * third-party plugins are handled uniformly (M-7). This class owns the per
 * application enable state, stored in `app_settings` under one JSON key, layered
 * over each plugin's built-in default so upgrades that add plugins do not
 * silently turn features on.
 */
final class PluginManager
{
    private const SETTING_KEY = 'plugins.state';

    /** Where the last failure per plugin is recorded; see {@see failures()}. */
    private const FAILURE_KEY = 'plugins.failures';

    /** How much of a failure message is kept for display. */
    private const FAILURE_MESSAGE_LIMIT = 300;

    private readonly Connection $connection;

    /**
     * The plugin SPI (PluginContract's hooks and route handlers) is typed to
     * a raw PDO, not the internal Connection seam — third-party plugin
     * authors get a stable, well-known type rather than an app-internal
     * abstraction. So this class keeps its own $pdo (unwrapped from whatever
     * it was constructed with) purely to hand down to plugins, while its own
     * two queries below (state/setEnabled) go through $connection like every
     * other migrated service.
     */
    private readonly PDO $pdo;

    public function __construct(Connection|PDO $db)
    {
        $this->connection = $db instanceof Connection ? $db : new PdoConnection($db, Dialects::active());
        $this->pdo = $this->connection->pdo();
    }

    /**
     * Fire enabled plugins' post-ingestion hooks. Called after each import;
     * failures are logged and recorded, never propagated, so a plugin can never
     * break ingestion.
     */
    public function onIngest(): void
    {
        foreach (PluginRegistry::all() as $plugin) {
            if (!$this->isEnabled($plugin->id())) {
                continue;
            }
            try {
                $plugin->onIngest($this->pdo);
                $this->clearFailure($plugin->id());
            } catch (\Throwable $exception) {
                $this->recordFailure($plugin->id(), 'onIngest', $exception);
            }
        }
    }

    /**
     * Dispatch a plugin-owned `?api=` action (F1), if any plugin's
     * {@see PluginContract::routes()} claims it. Null means no plugin owns
     * this action at all, so `ApiController` should fall through to its own
     * "unknown endpoint" 404 — it never has to know which actions belong to
     * which plugin, or that a plugin exists at all. When a plugin does claim
     * the action but is disabled for this application, this itself returns
     * the 404 (the same contract the old per-arm `requirePlugin()` enforced).
     */
    public function dispatch(string $action, PDO $db, LogLensRequest $request, string $applicationId): ?LogLensResponse
    {
        foreach (PluginRegistry::all() as $plugin) {
            $routes = $plugin->routes();
            if (!array_key_exists($action, $routes)) {
                continue;
            }
            if (!$this->isEnabled($plugin->id())) {
                return LogLensResponse::json(['error' => 'This plugin is disabled for the selected application.'], 404);
            }
            return $routes[$action]($db, $request, $applicationId);
        }
        return null;
    }

    /**
     * Ask enabled plugins to transform a captured stack trace, first one to
     * accept wins (decouples HTTP ingest from reaching
     * directly into the Releases plugin). Returns $stack unmodified if no
     * enabled plugin claims it.
     */
    public function resolveStack(string $stack, ?string $release): string
    {
        foreach (PluginRegistry::all() as $plugin) {
            if (!$this->isEnabled($plugin->id())) {
                continue;
            }
            $resolved = $plugin->onResolveStack($this->pdo, $stack, $release);
            if ($resolved !== null) {
                return $resolved;
            }
        }
        return $stack;
    }

    /**
     * Notify enabled plugins that an issue's status changed (decouples the
     * core issue-status endpoint from reaching
     * directly into the Linear plugin). Failures are isolated per plugin,
     * same guarantee as {@see onIngest()}: one plugin's write-back can never
     * fail the status change that triggered it.
     *
     * @return array<string,array<string,mixed>> keyed by the reacting plugin's id
     */
    public function onIssueStatusChanged(int $groupId, string $status, string $note): array
    {
        $results = [];
        foreach (PluginRegistry::all() as $plugin) {
            if (!$this->isEnabled($plugin->id())) {
                continue;
            }
            try {
                $result = $plugin->onIssueStatusChanged($this->pdo, $groupId, $status, $note);
                $this->clearFailure($plugin->id());
            } catch (\Throwable $exception) {
                $this->recordFailure($plugin->id(), 'onIssueStatusChanged', $exception);
                continue;
            }
            if ($result !== null) {
                $results[$plugin->id()] = $result;
            }
        }
        return $results;
    }

    /** All known plugin ids, active or planned. @return list<string> */
    public static function knownIds(): array
    {
        return array_map(static fn (PluginContract $plugin): string => $plugin->id(), PluginRegistry::all());
    }

    /** Whether an active plugin is enabled for this application. */
    public function isEnabled(string $id): bool
    {
        $plugin = PluginRegistry::find($id);
        if ($plugin === null || $plugin->status() !== 'active') {
            return false;
        }
        return $this->state()[$id] ?? $plugin->defaultEnabled();
    }

    /**
     * The catalog with each plugin's resolved enabled flag, for the UI.
     *
     * `last_error` carries the most recent isolated hook failure (null when the
     * plugin is healthy) so Settings → Plugins can show that a plugin is quietly
     * failing. See {@see recordFailure()} for why that matters.
     */
    public function catalog(): array
    {
        $state = $this->state();
        $failures = $this->failures();
        return array_map(function (PluginContract $plugin) use ($state, $failures): array {
            return [
                'id' => $plugin->id(),
                'name' => $plugin->name(),
                'description' => $plugin->description(),
                'category' => $plugin->category(),
                'status' => $plugin->status(),
                'enabled' => $plugin->status() === 'active'
                    ? ($state[$plugin->id()] ?? $plugin->defaultEnabled())
                    : false,
                'last_error' => $failures[$plugin->id()] ?? null,
            ];
        }, PluginRegistry::all());
    }

    /**
     * The last isolated hook failure per plugin id.
     *
     * Isolating a plugin's failure so it cannot break ingestion or a status
     * change is right; making that failure invisible is not. A misconfigured
     * Linear token or an alert webhook that started 500ing used to show up only
     * as a line in the PHP error log, which on most hosts nobody reads — the
     * feature simply appeared to stop working. Recording it here puts it in
     * front of the operator who can fix it, next to the toggle that turned it on.
     *
     * @return array<string,array{hook:string,message:string,at:string}>
     */
    public function failures(): array
    {
        $stored = $this->connection->selectValue(Dialects::active()->keyValueLookup(), [self::FAILURE_KEY]);
        $decoded = is_string($stored) ? json_decode($stored, true) : null;
        if (!is_array($decoded)) {
            return [];
        }
        $failures = [];
        foreach ($decoded as $id => $failure) {
            if (!is_array($failure)) {
                continue;
            }
            $failures[(string) $id] = [
                'hook' => (string) ($failure['hook'] ?? ''),
                'message' => (string) ($failure['message'] ?? ''),
                'at' => (string) ($failure['at'] ?? ''),
            ];
        }
        return $failures;
    }

    private function recordFailure(string $pluginId, string $hook, \Throwable $exception): void
    {
        // The log keeps the whole thing, including the trace; the stored copy is
        // a short summary meant for a settings panel.
        error_log('[log-lens][' . $pluginId . '][' . $hook . '] ' . $exception);

        $message = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $exception->getMessage()));
        if ($message === '') {
            $message = get_debug_type($exception);
        }
        if (mb_strlen($message) > self::FAILURE_MESSAGE_LIMIT) {
            $message = mb_substr($message, 0, self::FAILURE_MESSAGE_LIMIT) . '…';
        }
        $this->writeFailures([$pluginId => [
            'hook' => $hook,
            'message' => $message,
            'at' => gmdate('Y-m-d H:i:s'),
        ]] + $this->failures());
    }

    /**
     * Drop a plugin's recorded failure after the same hook succeeds, so a fault
     * that has been fixed stops being reported. Writes only when there is
     * something to clear — the common path is a no-op.
     */
    private function clearFailure(string $pluginId): void
    {
        $failures = $this->failures();
        if (!isset($failures[$pluginId])) {
            return;
        }
        unset($failures[$pluginId]);
        $this->writeFailures($failures);
    }

    /** @param array<string,array{hook:string,message:string,at:string}> $failures */
    private function writeFailures(array $failures): void
    {
        try {
            $this->connection->execute(
                Dialects::active()->keyValueUpsert(),
                [self::FAILURE_KEY, json_encode($failures, JSON_THROW_ON_ERROR)],
            );
        } catch (\Throwable $exception) {
            // Recording a failure must never itself become one: this runs inside
            // a catch block whose whole purpose is that the caller survives.
            error_log('[log-lens] could not record a plugin failure: ' . $exception->getMessage());
        }
    }

    /** Enable or disable an active plugin and return the refreshed catalog. */
    public function setEnabled(string $id, bool $enabled): array
    {
        $plugin = PluginRegistry::find($id);
        if ($plugin === null) {
            throw new InvalidArgumentException("Unknown plugin: {$id}.");
        }
        if ($plugin->status() !== 'active') {
            throw new InvalidArgumentException("The {$plugin->name()} plugin is not available yet.");
        }
        $state = $this->state();
        $state[$id] = $enabled;
        $this->connection->execute(
            Dialects::active()->keyValueUpsert(),
            [self::SETTING_KEY, json_encode($state, JSON_THROW_ON_ERROR)],
        );

        return ['data' => $this->catalog()];
    }

    /** @return array<string,bool> */
    private function state(): array
    {
        $stored = $this->connection->selectValue(Dialects::active()->keyValueLookup(), [self::SETTING_KEY]);
        $decoded = is_string($stored) ? json_decode($stored, true) : null;
        if (!is_array($decoded)) {
            return [];
        }
        $state = [];
        foreach ($decoded as $id => $enabled) {
            $state[(string) $id] = (bool) $enabled;
        }
        return $state;
    }
}
