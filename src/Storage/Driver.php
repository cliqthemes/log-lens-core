<?php
declare(strict_types=1);

namespace LogLens\Storage;

use PDO;

/**
 * An engine backend (C-1): everything Dialect does NOT cover — connecting,
 * migrating the schema, and resolving per-application tenancy. Dialect stays
 * a small, stateless "which SQL fragment" concern that repositories build
 * queries with directly; Driver is the stateful "open me a migrated
 * connection for this application" concern that only `Database` talks to.
 *
 * One instance per engine: {@see SqliteDriver} (file-per-application),
 * {@see PostgresDriver} (schema-per-application), {@see MysqlDriver}
 * (database-per-application). SQLite is the default; the others are opt-in
 * via `database.driver` in config (never enforced).
 */
interface Driver
{
    /** Engine key: 'sqlite' | 'pgsql' | 'mysql'. */
    public function name(): string;

    /** The SQL-fragment dialect for this engine. */
    public function dialect(): Dialect;

    /**
     * Open a fully migrated PDO connection for one application.
     *
     * @param string $tenant     the application's stable id/slug — ignored by
     *   SqliteDriver (each application already has its own file); used by
     *   Postgres/MySQL to name the per-application schema/database.
     * @param string $sqlitePath the application's SQLite file path, as
     *   resolved by the caller — ignored by every driver except SqliteDriver.
     */
    public function connect(string $tenant, string $sqlitePath): PDO;

    /**
     * A lightweight connectivity + version probe for `?api=health`, run
     * against a disposable/ephemeral target — never a real application's
     * data. Returns ['ok' => bool, 'version' => ?string, 'minimum' => ?string].
     *
     * @return array{ok: bool, version: ?string, minimum: ?string}
     */
    public function ping(): array;

    /** Forget any per-process memoization (test isolation). */
    public function resetCache(): void;
}
