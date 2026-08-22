<?php
declare(strict_types=1);

/**
 * Live smoke test for {@see \LogLens\Storage\PostgresDriver} (C-1).
 *
 * Not part of the PHPUnit suite: it needs a real, reachable Postgres server
 * and a pre-existing physical database (Postgres has no `CREATE DATABASE IF
 * NOT EXISTS`, so provisioning the container database itself is left to the
 * environment — see .github/workflows/ci.yml). Run directly:
 *
 *   LOG_LENS_DB_HOST=127.0.0.1 LOG_LENS_DB_NAME=log_lens_smoke \
 *   LOG_LENS_DB_USER=postgres LOG_LENS_DB_PASSWORD=postgres \
 *     php tests/smoke/pgsql.php
 *
 * Exits non-zero (and prints the failing assertion) on any failure, so CI can
 * gate on it directly.
 */

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/support.php';

use LogLens\Config;
use LogLens\Database;
use LogLens\Storage\Drivers;

Config::load([
    'database' => [
        'driver' => 'pgsql',
        'pgsql' => [
            'host' => getenv('LOG_LENS_DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('LOG_LENS_DB_PORT') ?: 5432),
            'database' => getenv('LOG_LENS_DB_NAME') ?: 'log_lens_smoke',
            'username' => getenv('LOG_LENS_DB_USER') ?: 'postgres',
            'password' => getenv('LOG_LENS_DB_PASSWORD') ?: 'postgres',
            'schema_prefix' => 'smoke_',
        ],
    ],
]);
Drivers::reset();

$tenant = 'run-' . substr(sha1(uniqid('', true)), 0, 12);

smoke_run('pgsql', $tenant, function () use ($tenant): void {
    $ping = Drivers::active()->ping();
    smoke_assert($ping['ok'] === true, 'ping() reports the server reachable');

    $db = new Database('', $tenant);
    $pdo = $db->pdo;
    $connection = $db->connection();
    $dialect = $connection->dialect();

    smoke_exercise_driver($pdo, $connection, $dialect, [
        // Postgres enforces case-insensitive uniqueness via a functional
        // lower() index rather than a plain UNIQUE — same guarantee.
        'unique_violation_needle' => 'duplicate key value violates unique constraint',
    ]);

    // Re-connecting must short-circuit the migration (schema_version already current).
    new Database('', $tenant);

    // Last: destroys and rebuilds the schema, so it must run after everything above.
    smoke_verify_rollback($pdo, new \LogLens\Storage\Schema\PostgresGrammar(), 'BEGIN');
});
