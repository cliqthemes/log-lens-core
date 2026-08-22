<?php
declare(strict_types=1);

namespace LogLens\Plugins;

use LogLens\Config;
use LogLens\Plugins\Builtin\AccessAnalyticsPlugin;
use LogLens\Plugins\Builtin\AlertsPlugin;
use LogLens\Plugins\Builtin\HttpIngestPlugin;
use LogLens\Plugins\Builtin\LinearPlugin;
use LogLens\Plugins\Builtin\ReleasesPlugin;

/**
 * The discovery point for plugins (M-7). It composes three sources into one
 * catalog, keyed by id (later sources override earlier, so a package can
 * replace a built-in):
 *
 *   1. the built-in feature plugins;
 *   2. anything registered programmatically at bootstrap
 *      (`PluginRegistry::register(new MyPlugin())`);
 *   3. classes listed under `plugins.register` in config.php (declarative
 *      discovery for host apps that cannot run bootstrap code).
 *
 * PluginManager reads exclusively from here, so third-party plugins are
 * first-class rather than a closed hardcoded list.
 *
 * Same persistent-worker caveat as {@see \LogLens\Config} and
 * {@see \LogLens\Storage\Drivers}: `$registered`/
 * `$configLoaded` are process-global. Harmless in practice — which plugins a
 * deployment registers is a deployment-time decision, not a per-request one —
 * but not Octane-safe if that ever stops being true; {@see reset()} exists
 * for exactly that (and for test isolation, its current use).
 */
final class PluginRegistry
{
    /** @var array<string,PluginContract> */
    private static array $registered = [];

    private static bool $configLoaded = false;

    /** Register (or override, by id) a plugin at runtime. */
    public static function register(PluginContract $plugin): void
    {
        self::$registered[$plugin->id()] = $plugin;
    }

    /** Drop all programmatic registrations and re-arm config discovery (tests). */
    public static function reset(): void
    {
        self::$registered = [];
        self::$configLoaded = false;
    }

    /**
     * The full catalog: built-ins, then programmatic, then config-declared,
     * deduplicated by id with later sources winning.
     *
     * @return list<PluginContract>
     */
    public static function all(): array
    {
        self::loadConfigDeclared();
        $byId = [];
        foreach (self::builtins() as $plugin) {
            $byId[$plugin->id()] = $plugin;
        }
        foreach (self::$registered as $plugin) {
            $byId[$plugin->id()] = $plugin;
        }
        return array_values($byId);
    }

    public static function find(string $id): ?PluginContract
    {
        foreach (self::all() as $plugin) {
            if ($plugin->id() === $id) {
                return $plugin;
            }
        }
        return null;
    }

    /** @return list<PluginContract> */
    private static function builtins(): array
    {
        return [
            new LinearPlugin(),
            new AlertsPlugin(),
            new HttpIngestPlugin(),
            new ReleasesPlugin(),
            new AccessAnalyticsPlugin(),
        ];
    }

    private static function loadConfigDeclared(): void
    {
        if (self::$configLoaded) {
            return;
        }
        self::$configLoaded = true;
        $declared = Config::get('plugins.register', []);
        if (!is_array($declared)) {
            return;
        }
        foreach ($declared as $class) {
            if (!is_string($class) || !class_exists($class)) {
                continue;
            }
            $instance = new $class();
            if ($instance instanceof PluginContract) {
                self::$registered[$instance->id()] = $instance;
            }
        }
    }
}
