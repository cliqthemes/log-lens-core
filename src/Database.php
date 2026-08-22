<?php
declare(strict_types=1);

namespace LogLens;

use LogLens\Storage\Connection;
use LogLens\Storage\Driver;
use LogLens\Storage\Drivers;
use LogLens\Storage\MysqlDriver;
use LogLens\Storage\PdoConnection;
use LogLens\Storage\PostgresDriver;
use LogLens\Storage\SqliteDriver;
use PDO;
use RuntimeException;

/**
 * Opens one application's database, on whichever engine is configured (C-1):
 * a thin orchestrator over the active {@see Driver}, which does the actual
 * connecting, migrating, and per-application tenancy. SQLite (the default)
 * uses a private file per application; Postgres/MySQL (opt-in) get a
 * schema/database per application instead — see the drivers themselves.
 */
final class Database
{
    public readonly PDO $pdo;

    private ?Connection $connection = null;

    /**
     * @param string $path   the application's SQLite file path, as resolved
     *   by the caller — used by {@see SqliteDriver}; ignored by every other
     *   driver, which derives its own connection target from config.
     * @param string|null $tenant the application's stable id/slug, used by
     *   Postgres/MySQL to name the per-application schema/database. Defaults
     *   to a name derived from $path, which is only meaningful for SQLite
     *   (where it is unused anyway) — real callers on another engine should
     *   always pass the application id explicitly (see {@see Kernel}).
     */
    public function __construct(string $path, ?string $tenant = null)
    {
        $driver = Drivers::active();
        if ($tenant === null && $driver->name() !== 'sqlite') {
            // Falling back to the file name on a schema-per-application engine
            // is not a smaller mistake, it is a silent one: every application
            // created through the UI gets the same `log-lens.sqlite` file name,
            // so the fallback would route every one of them into a single
            // shared schema — writes that the dashboard (which does pass the
            // id) then cannot see at all. Confirmed live: this is exactly what
            // the CLI entry points did on Postgres before they were fixed.
            throw new RuntimeException(
                'A tenant (application id) is required on the ' . $driver->name()
                . ' driver — the path-derived fallback is SQLite-only.'
            );
        }
        $this->pdo = $driver->connect($tenant ?? self::tenantFromPath($path), $path);
    }

    /**
     * The storage seam over this database (C-1). New code should depend on the
     * {@see Connection} rather than the raw PDO handle; `->pdo` remains for code
     * not yet migrated and for byte-range streaming reads.
     */
    public function connection(): Connection
    {
        return $this->connection ??= new PdoConnection($this->pdo, Drivers::active()->dialect());
    }

    /** Forget every driver's per-process schema cache (test isolation when a path/tenant is reused). */
    public static function resetSchemaCache(): void
    {
        (new SqliteDriver())->resetCache();
        (new PostgresDriver())->resetCache();
        (new MysqlDriver())->resetCache();
    }

    /** The minimum SQLite version the SQLite driver's schema requires (documented floor). */
    public static function minimumSqliteVersion(): string
    {
        return SqliteDriver::minimumVersion();
    }

    private static function tenantFromPath(string $path): string
    {
        if ($path === '' || $path === ':memory:') {
            return 'memory';
        }
        return pathinfo($path, PATHINFO_FILENAME) ?: 'default';
    }
}
