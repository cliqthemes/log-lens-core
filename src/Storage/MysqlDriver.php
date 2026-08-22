<?php
declare(strict_types=1);

namespace LogLens\Storage;

use LogLens\Config;
use LogLens\Storage\Schema\Migrator;
use LogLens\Storage\Schema\MysqlGrammar;
use PDO;
use RuntimeException;
use Throwable;

/**
 * MySQL/MariaDB backend (opt-in via `LOG_LENS_DB_DRIVER=mysql`, C-1).
 *
 * Tenancy: every application gets its own physical **database**, named
 * `database_prefix + application id` (e.g. `log_lens_billing`) — MySQL has no
 * lightweight schema-within-database concept like Postgres, so a full
 * database is the closest equivalent to SQLite's file-per-application.
 * Schema application beyond that is delegated to the {@see Migrator}
 * (running the files under `database/migrations/`).
 *
 * Requires MySQL **8.0.13+**: an expression column default (used for every
 * `->useCurrent()` timestamp — see {@see \LogLens\Storage\Schema\MysqlGrammar})
 * only exists from that version on.
 */
final class MysqlDriver implements Driver
{
    private const MIN_MYSQL_VERSION = '8.0.13';

    /** Per-process set of database names already confirmed migrated. */
    private static array $schemaVerified = [];

    public function name(): string
    {
        return 'mysql';
    }

    public function dialect(): Dialect
    {
        return new MysqlDialect();
    }

    public function connect(string $tenant, string $sqlitePath): PDO
    {
        $database = $this->databaseName($tenant);
        $config = Config::get('database.mysql', []);
        $host = $config['host'] ?? '127.0.0.1';
        $port = (int) ($config['port'] ?? 3306);
        $username = $config['username'] ?? 'root';
        $password = $config['password'] ?? '';

        // Connect without a target database first: the database itself may
        // not exist yet on a brand-new application.
        $server = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $this->assertSupportedMysql($server);
        $server->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");

        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database),
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Every timestamp the app writes from PHP (created_at, occurred_at, …)
                // is UTC (gmdate()). Unlike SQLite's CURRENT_TIMESTAMP (always UTC) and
                // Postgres's now()/CURRENT_TIMESTAMP (normalized to UTC by PostgresDialect
                // and PostgresGrammar), MySQL's NOW()/CURRENT_TIMESTAMP evaluate in the
                // *session* time zone, which defaults to the server's local system zone
                // (@@time_zone=SYSTEM) — not UTC. Left unset, every server-side
                // CURRENT_TIMESTAMP default/assignment and Dialects::active()->now() call
                // silently drifts from the app's UTC timestamps by the server's offset
                // (e.g. Alerts spike windows, ingest "imported_at", sync run timings).
                // Pin the session to UTC once, here, rather than special-casing every
                // call site — mirrors what PostgresGrammar/PostgresDialect already do
                // with `AT TIME ZONE 'UTC'`.
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
                // Report *matched* rows from an UPDATE, not changed ones. MySQL
                // alone defaults to "changed", and the codebase reads affected
                // rows as logic: a claim like `UPDATE … SET status='running'
                // WHERE id=? AND status='running'` matches its row but changes
                // nothing, so the default made every connector sync run abort
                // with "run is no longer queued" (found live on MySQL). Matched
                // rows is also what SQLite and Postgres already return, so this
                // makes one engine agree with the other two rather than
                // introducing a third behaviour.
                PDO::MYSQL_ATTR_FOUND_ROWS => true,
            ],
        );
        $this->migrate($pdo, $database);
        return $pdo;
    }

    public function ping(): array
    {
        try {
            $config = Config::get('database.mysql', []);
            $pdo = new PDO(
                sprintf(
                    'mysql:host=%s;port=%d;charset=utf8mb4',
                    $config['host'] ?? '127.0.0.1',
                    (int) ($config['port'] ?? 3306),
                ),
                $config['username'] ?? 'root',
                $config['password'] ?? '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            return [
                'ok' => version_compare($this->numericVersion($version), self::MIN_MYSQL_VERSION, '>='),
                'version' => $version,
                'minimum' => self::MIN_MYSQL_VERSION,
            ];
        } catch (Throwable) {
            return ['ok' => false, 'version' => null, 'minimum' => self::MIN_MYSQL_VERSION];
        }
    }

    public function resetCache(): void
    {
        self::$schemaVerified = [];
    }

    /** The minimum MySQL version this schema requires (documented floor). */
    public static function minimumVersion(): string
    {
        return self::MIN_MYSQL_VERSION;
    }

    private function assertSupportedMysql(PDO $pdo): void
    {
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        if (version_compare($this->numericVersion($version), self::MIN_MYSQL_VERSION, '<')) {
            throw new RuntimeException(
                'Log Lens requires MySQL ' . self::MIN_MYSQL_VERSION
                . ' or newer (expression column defaults); this server reports ' . $version . '.'
            );
        }
    }

    /** MySQL's VERSION() can carry a vendor suffix (e.g. "8.0.36-log" or MariaDB variants); keep just the leading dotted number. */
    private function numericVersion(string $version): string
    {
        return preg_match('/^\d+(\.\d+)*/', $version, $m) === 1 ? $m[0] : '0';
    }

    /**
     * Application ids are validated slugs (see ApplicationRegistry::slug());
     * double-checked here since it is string-interpolated into DDL.
     *
     * The configured prefix gets the same treatment. It comes from config rather
     * than a request, so this is a defence-in-depth check rather than a hole
     * being closed — but it is interpolated into ``CREATE DATABASE `…` `` exactly
     * like the tenant is, and a prefix carrying a backtick would break out of
     * that identifier. Validating both halves means no path reaches the DDL
     * unchecked.
     */
    private function databaseName(string $tenant): string
    {
        if (preg_match('/^[a-z0-9-]{1,64}$/', $tenant) !== 1) {
            throw new RuntimeException("Invalid application id for a MySQL database name: '{$tenant}'.");
        }
        $prefix = Config::string('database.mysql.database_prefix', 'log_lens_');
        if ($prefix !== '' && preg_match('/^[a-z0-9_]{1,32}$/', $prefix) !== 1) {
            throw new RuntimeException(
                'database.mysql.database_prefix must be 1-32 lowercase letters, digits, or underscores.'
            );
        }
        return $prefix . str_replace('-', '_', $tenant);
    }

    private function migrate(PDO $pdo, string $database): void
    {
        if (isset(self::$schemaVerified[$database])) {
            return;
        }
        // MySQL DDL auto-commits statement-by-statement (no real
        // transactional DDL), so a wrapping transaction cannot serialize
        // concurrent workers the way SQLite's BEGIN IMMEDIATE or Postgres's
        // transactional DDL can — a documented limitation, not a bug: every
        // migration statement here is idempotent (IF NOT EXISTS, or a
        // migration recorded exactly once), so a race would at worst
        // duplicate-error on a second worker's CREATE TABLE, not corrupt data.
        (new Migrator($this->migrationsPath()))->migrate($pdo, new MysqlGrammar(), 'START TRANSACTION');
        self::$schemaVerified[$database] = true;
    }

    private function migrationsPath(): string
    {
        return dirname(__DIR__, 2) . '/database/migrations';
    }
}
