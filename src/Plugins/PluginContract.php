<?php
declare(strict_types=1);

namespace LogLens\Plugins;

use LogLens\Http\LogLensRequest;
use LogLens\Http\LogLensResponse;
use PDO;

/**
 * The registration contract every optional feature implements (M-7).
 *
 * Built-in features implement it as classes under Plugins/Builtin; a third-party
 * package ships its own implementation and registers it either programmatically
 * (`PluginRegistry::register(new MyPlugin())` at bootstrap) or declaratively by
 * listing the class under `plugins.register` in config.php. The rest of the
 * system only ever sees this contract, so the catalog is no longer a closed
 * hardcoded list.
 */
interface PluginContract
{
    /** Stable machine id (route gating + stored enable state key). */
    public function id(): string;

    /** Human name for Settings → Plugins. */
    public function name(): string;

    public function description(): string;

    /** Grouping label in the UI (e.g. "Integration", "Insights"). */
    public function category(): string;

    /** 'active' (toggleable + implemented) or 'planned' (roadmap only). */
    public function status(): string;

    /** Whether a workspace has this on before anyone toggles it. */
    public function defaultEnabled(): bool;

    /**
     * Post-ingestion hook, run after each import when the plugin is enabled.
     * No-op by default. The manager isolates failures, but implementations
     * should still avoid throwing.
     */
    public function onIngest(PDO $db): void;

    /**
     * The `?api=` HTTP endpoints this plugin owns. A catalog entry alone did
     * not make a plugin a real extension point while every route still lived
     * in a hardcoded `ApiController` match arm, which is what this closes. {@see PluginManager::dispatch()} looks an
     * action up here, confirms the plugin is enabled for the application,
     * and invokes the handler — `ApiController` never needs to know this
     * plugin, or any of its endpoints, exist. Empty by default; a plugin
     * with only an `onIngest()` hook needs no routes.
     *
     * The third argument each handler receives is the current application's
     * id (the same value `Kernel` already resolved and passed to
     * `ApiController`'s constructor) — for the rare handler that needs it in
     * its response (e.g. an ingest URL naming the application), rather than
     * every plugin re-deriving it from the request query, which is not
     * reliably the same value (`Kernel` applies its own resolution/fallback).
     *
     * @return array<string, callable(PDO,LogLensRequest,string):LogLensResponse>
     */
    public function routes(): array;

    /**
     * Let an enabled plugin transform a captured stack trace before it's
     * stored — the Releases plugin de-minifies a browser
     * stack via source maps here. Return null to decline (the "not my
     * concern, try the next plugin, then leave it as-is" default every
     * plugin except Releases wants). No-op (returns null) by default via
     * {@see AbstractPlugin}.
     *
     * Kept as a named hook on the contract rather than a generic pub/sub bus:
     * with five built-in plugins and exactly one real cross-cutting concern
     * each, an explicit method is more traceable (go-to-definition finds
     * every implementor) than an event name that's only a string until
     * something dispatches it. Revisit if a handful more of these appear.
     */
    public function onResolveStack(PDO $db, string $stack, ?string $release): ?string;

    /**
     * Let an enabled plugin react to an issue's workflow status changing
     * — Linear mirrors the change back to its own issue
     * here. Return null to decline (nothing to report); a non-null array is
     * merged into the status-change response under this plugin's id (e.g.
     * `$result['linear'] = [...]`), exactly as the old inline call did. A
     * plugin's write-back failing must never fail the local status change —
     * implementations should catch their own exceptions and return null
     * rather than let one escape. No-op by default via {@see AbstractPlugin}.
     *
     * @return array<string,mixed>|null
     */
    public function onIssueStatusChanged(PDO $db, int $groupId, string $status, string $note): ?array;
}
