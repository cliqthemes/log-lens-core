<?php
declare(strict_types=1);

/**
 * Live smoke test for {@see \LogLens\Storage\MysqlDriver} (C-1).
 *
 * Not part of the PHPUnit suite: it needs a real, reachable MySQL 8 server
 * (the driver creates its own per-tenant database, so nothing needs
 * pre-provisioning beyond the server itself — see .github/workflows/ci.yml).
 * Run directly:
 *
 *   LOG_LENS_DB_HOST=127.0.0.1 LOG_LENS_DB_USER=root LOG_LENS_DB_PASSWORD=root \
 *     php tests/smoke/mysql.php
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
        'driver' => 'mysql',
        'mysql' => [
            'host' => getenv('LOG_LENS_DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('LOG_LENS_DB_PORT') ?: 3306),
            'username' => getenv('LOG_LENS_DB_USER') ?: 'root',
            'password' => getenv('LOG_LENS_DB_PASSWORD') ?: '',
            'database_prefix' => 'log_lens_smoke_',
        ],
    ],
]);
Drivers::reset();

$tenant = 'run-' . substr(sha1(uniqid('', true)), 0, 12);

smoke_run('mysql', $tenant, function () use ($tenant): void {
    $ping = Drivers::active()->ping();
    smoke_assert($ping['ok'] === true, 'ping() reports the server reachable and at/above the 8.0.13 floor');

    $db = new Database('', $tenant);
    $pdo = $db->pdo;
    $connection = $db->connection();
    $dialect = $connection->dialect();

    smoke_exercise_driver($pdo, $connection, $dialect, [
        // MySQL enforces case-insensitive uniqueness via the column's
        // utf8mb4_0900_ai_ci collation — a plain UNIQUE constraint.
        'unique_violation_needle' => 'Duplicate entry',
    ]);

    // Re-connecting must short-circuit the migration (schema_version already current).
    new Database('', $tenant);

    // Last: destroys and rebuilds the schema, so it must run after everything above.
    smoke_verify_rollback($pdo, new \LogLens\Storage\Schema\MysqlGrammar(), 'START TRANSACTION');
});
