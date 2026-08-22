<?php
declare(strict_types=1);

namespace LogLens\Storage;

use LogLens\Config;
use LogLens\Storage\Schema\Migrator;
use LogLens\Storage\Schema\PostgresGrammar;
use PDO;
use RuntimeException;
use Throwable;

/**
 * PostgreSQL backend (opt-in via `LOG_LENS_DB_DRIVER=pgsql`, C-1).
 *
 * Tenancy: every application gets its own **schema** inside one physical
 * database (`schema_prefix + application id`, e.g. `app_billing`), and the
 * connection pins `search_path` to it. Every unqualified table reference
 * already in the codebase (`FROM error_groups`, …) keeps working completely
 * unchanged — this is the Postgres analog of SQLite's file-per-application,
 * without rewriting a single repository. Schema application beyond that is
 * delegated to the {@see Migrator} (running the files under
 * `database/migrations/`).
 */
final class PostgresDriver implements Driver
{
    /** Per-process set of schema names already confirmed migrated. */
    private static array $schemaVerified = [];

    public function name(): string
    {
        return 'pgsql';
    }

    public function dialect(): Dialect
    {
        return new PostgresDialect();
    }

    public function connect(string $tenant, string $sqlitePath): PDO
    {
        $schema = $this->schemaName($tenant);
        $config = Config::get('database.pgsql', []);
        $pdo = new PDO(
            sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $config['host'] ?? '127.0.0.1',
                (int) ($config['port'] ?? 5432),
                $config['database'] ?? 'log_lens',
            ),
            $config['username'] ?? 'postgres',
            $config['password'] ?? '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        );
        $pdo->exec('CREATE SCHEMA IF NOT EXISTS "' . $schema . '"');
        $pdo->exec('SET search_path TO "' . $schema . '"');
        $this->migrate($pdo, $schema);
        return $pdo;
    }

    public function ping(): array
    {
        try {
            $config = Config::get('database.pgsql', []);
            $pdo = new PDO(
                sprintf(
                    'pgsql:host=%s;port=%d;dbname=%s',
                    $config['host'] ?? '127.0.0.1',
                    (int) ($config['port'] ?? 5432),
                    $config['database'] ?? 'log_lens',
                ),
                $config['username'] ?? 'postgres',
                $config['password'] ?? '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            $version = (string) $pdo->query('SHOW server_version')->fetchColumn();
            return ['ok' => true, 'version' => $version, 'minimum' => null];
        } catch (Throwable) {
            return ['ok' => false, 'version' => null, 'minimum' => null];
        }
    }

    public function resetCache(): void
    {
        self::$schemaVerified = [];
    }

    /**
     * Application ids are validated slugs (see ApplicationRegistry::slug());
     * double-checked here since it is string-interpolated into DDL.
     *
     * The configured prefix gets the same treatment. It comes from config rather
     * than a request, so this is a defence-in-depth check rather than a hole
     * being closed — but it is interpolated into `CREATE SCHEMA "…"` exactly
     * like the tenant is, and a prefix carrying a quote would break out of that
     * identifier. Validating both halves means no path reaches the DDL
     * unchecked.
     */
    private function schemaName(string $tenant): string
    {
        if (preg_match('/^[a-z0-9-]{1,64}$/', $tenant) !== 1) {
            throw new RuntimeException("Invalid application id for a Postgres schema name: '{$tenant}'.");
        }
        $prefix = Config::string('database.pgsql.schema_prefix', 'app_');
        if ($prefix !== '' && preg_match('/^[a-z0-9_]{1,32}$/', $prefix) !== 1) {
            throw new RuntimeException(
                'database.pgsql.schema_prefix must be 1-32 lowercase letters, digits, or underscores.'
            );
        }
        return $prefix . str_replace('-', '_', $tenant);
    }

    private function migrate(PDO $pdo, string $schema): void
    {
        if (isset(self::$schemaVerified[$schema])) {
            return;
        }
        $this->ensureSafeJsonbFunction($pdo);
        // Postgres supports transactional DDL, so a plain BEGIN gives the
        // same concurrent-worker protection SQLite gets from BEGIN IMMEDIATE
        // — no legacy-install marker: no Postgres tenant predates the Migrator.
        (new Migrator($this->migrationsPath()))->migrate($pdo, new PostgresGrammar(), 'BEGIN');
        self::$schemaVerified[$schema] = true;
    }

    /**
     * `json_valid()` (used by generated columns to guard non-JSON
     * `context_preview` values) has no Postgres equivalent — attempting
     * `->>`/`::jsonb` on invalid JSON simply throws. This IMMUTABLE wrapper
     * gives generated columns the same "NULL instead of an error" behavior,
     * and lives in `public` (shared, created once) rather than each
     * per-application schema, referenced fully-qualified so it resolves
     * regardless of the connection's `search_path`.
     */
    private function ensureSafeJsonbFunction(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE OR REPLACE FUNCTION public.safe_jsonb(input text)
RETURNS jsonb
LANGUAGE plpgsql
IMMUTABLE
AS $$
BEGIN
    RETURN input::jsonb;
EXCEPTION WHEN others THEN
    RETURN NULL;
END;
$$;
SQL);
    }

    private function migrationsPath(): string
    {
        return dirname(__DIR__, 2) . '/database/migrations';
    }
}
