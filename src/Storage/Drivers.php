<?php
declare(strict_types=1);

namespace LogLens\Storage;

use LogLens\Config;

/**
 * Resolves the active {@see Driver} from the configured database engine
 * (C-1). The engine is a single, global deployment choice (every application
 * shares it), so a service-locator is the right shape — `Database` is the
 * only caller. Defaults to SQLite; nothing here is enforced.
 *
 * Not safe under a persistent-worker runtime (Octane/Swoole/RoadRunner) as-is:
 * `$active` is memoized process-wide, so the first
 * request's resolved driver/dialect would outlive that request and serve
 * every later one on the same worker — normally harmless (the configured
 * engine doesn't change at runtime) but wrong the instant it does (e.g. a
 * test harness or a host switching `database.driver` between requests). Call
 * {@see reset()} at the start of each request if that deployment model is
 * ever supported; fine as-is under the request-per-process model this app
 * assumes (PHP-FPM, CLI, standalone dev server).
 */
final class Drivers
{
    private static ?Driver $active = null;

    public static function active(): Driver
    {
        return self::$active ??= match (Config::string('database.driver', 'sqlite')) {
            'pgsql' => new PostgresDriver(),
            'mysql' => new MysqlDriver(),
            default => new SqliteDriver(),
        };
    }

    /** Forget the memoized driver (config changes / test isolation). */
    public static function reset(): void
    {
        self::$active = null;
    }
}
