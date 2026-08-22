<?php
declare(strict_types=1);

namespace LogLens\Storage;

use LogLens\Config;
use LogLens\Storage\Schema\Migrator;
use LogLens\Storage\Schema\SqliteGrammar;
use PDO;
use RuntimeException;
use Throwable;

/**
 * SQLite backend — the default, zero-config engine (C-1). One file per
 * application; `$tenant` is ignored ($sqlitePath already identifies the
 * application). Schema application is delegated to the {@see Migrator}
 * (running the files under `database/migrations/`); this class owns only
 * the connection itself (PRAGMAs, version floor).
 */
final class SqliteDriver implements Driver
{
    /**
     * Minimum supported SQLite: generated columns (used for occurred_day and
     * the access-analytics columns) require 3.31.0; window functions, JSON1,
     * and partial indexes are all older. This is the documented runtime floor.
     */
    private const MIN_SQLITE_VERSION = '3.31.0';

    /** Per-process guard so the version is checked once, not on every connect. */
    private static bool $versionChecked = false;

    /**
     * Per-process record of database paths already confirmed migrated. A
     * reused PHP-FPM worker opens the same application database on every
     * request; once one request has verified (or run) migrations, later
     * requests in the same worker skip even the Migrator's own lightweight
     * "any migrations pending?" query. Assumes a migrated file is not
     * deleted out from under a live worker; tests that recreate a path call
     * {@see resetCache()}.
     *
     * @var array<string,true>
     */
    private static array $schemaVerified = [];

    public function name(): string
    {
        return 'sqlite';
    }

    public function dialect(): Dialect
    {
        return new SqliteDialect();
    }

    public function connect(string $tenant, string $sqlitePath): PDO
    {
        if ($sqlitePath !== ':memory:') {
            $directory = dirname($sqlitePath);
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException("Cannot create database directory: {$directory}");
            }
        }

        $pdo = new PDO('sqlite:' . $sqlitePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->assertSupportedSqlite($pdo);
        $this->configure($pdo);
        $this->migrate($pdo, $sqlitePath);
        return $pdo;
    }

    public function ping(): array
    {
        try {
            $pdo = $this->connect('health', ':memory:');
            $version = (string) $pdo->query('SELECT sqlite_version()')->fetchColumn();
            return [
                'ok' => version_compare($version, self::MIN_SQLITE_VERSION, '>='),
                'version' => $version,
                'minimum' => self::MIN_SQLITE_VERSION,
            ];
        } catch (Throwable) {
            return ['ok' => false, 'version' => null, 'minimum' => self::MIN_SQLITE_VERSION];
        }
    }

    public function resetCache(): void
    {
        self::$schemaVerified = [];
        self::$versionChecked = false;
    }

    /** The minimum SQLite version this schema requires (documented floor). */
    public static function minimumVersion(): string
    {
        return self::MIN_SQLITE_VERSION;
    }

    /**
     * Fail fast with a clear message on a SQLite older than the schema needs,
     * rather than a cryptic error mid-migration. Checked once per process.
     */
    private function assertSupportedSqlite(PDO $pdo): void
    {
        if (self::$versionChecked) {
            return;
        }
        $version = (string) $pdo->query('SELECT sqlite_version()')->fetchColumn();
        if (version_compare($version, self::MIN_SQLITE_VERSION, '<')) {
            throw new RuntimeException(
                'Log Lens requires SQLite ' . self::MIN_SQLITE_VERSION
                . ' or newer (generated columns); this build links ' . $version . '.'
            );
        }
        self::$versionChecked = true;
    }

    private function configure(PDO $pdo): void
    {
        // busy_timeout must be set first: it installs the busy handler that makes
        // every later lock (including the WAL-mode switch on a brand-new file and
        // the migration's BEGIN IMMEDIATE) wait instead of failing immediately when
        // concurrent PHP-FPM workers open the database at the same time.
        $pdo->exec('PRAGMA busy_timeout=' . Config::int('database.busy_timeout', 5000));
        $pdo->exec(
            'PRAGMA journal_mode=WAL;
             PRAGMA foreign_keys=ON;
             PRAGMA synchronous=NORMAL;
             PRAGMA temp_store=MEMORY;'
        );
    }

    private function migrate(PDO $pdo, string $path): void
    {
        // An in-memory database (":memory:") is private to its connection,
        // so it is never cached; a file-backed one already confirmed
        // migrated by this worker needs no further checks this process.
        $cacheable = $path !== '' && $path !== ':memory:';
        if ($cacheable && isset(self::$schemaVerified[$path])) {
            return;
        }
        // BEGIN IMMEDIATE (not the plain BEGIN a bare beginTransaction() would
        // issue) takes the write lock up front — busy_timeout makes a
        // concurrent PHP-FPM worker wait instead of racing on the first ALTER
        // TABLE — and 'modules' is the legacy-install marker: any SQLite
        // database that predates this Migrator already has it. The legacy
        // boundary is 0007: migrations 0001-0007 are a faithful
        // reconstruction of the schema the old inline runMigration() actually
        // produced (schema v8); 0008 onward are real migrations added after
        // the Migrator replaced it, and must always run for real — see
        // Migrator's docblock for why this distinction matters.
        (new Migrator($this->migrationsPath()))->migrate(
            $pdo,
            new SqliteGrammar(),
            'BEGIN IMMEDIATE',
            'modules',
            '0007_create_remaining_indexes',
        );
        if ($cacheable) {
            self::$schemaVerified[$path] = true;
        }
    }

    private function migrationsPath(): string
    {
        return dirname(__DIR__, 2) . '/database/migrations';
    }
}
