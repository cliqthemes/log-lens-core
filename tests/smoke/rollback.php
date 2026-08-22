<?php
declare(strict_types=1);

/**
 * Verifies {@see \LogLens\Storage\Schema\Migrator::rollback()} end to
 * end against SQLite: rolling back one batch, rolling back everything down
 * to a clean slate (proving every migration's down() is correct and
 * dependency-ordered — an index must be dropped before the column it
 * references, and this schema's indexes are added by later migrations than
 * the columns they cover), then re-migrating from scratch to prove the
 * cycle is fully reversible.
 *
 * SQLite only: rollback is an engine-agnostic Migrator feature, not
 * something that needs separate proof per engine — Postgres/MySQL run the
 * exact same Grammar-compiled down() statements through the exact same
 * Migrator code path (see pgsql.php/mysql.php for their own engine-specific
 * concerns instead). Run directly:
 *
 *   php tests/smoke/rollback.php
 */

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/support.php';

use LogLens\Database;
use LogLens\Storage\Schema\Migrator;
use LogLens\Storage\Schema\SqliteGrammar;

$path = sys_get_temp_dir() . '/ll_rollback_smoke_' . uniqid('', true) . '.sqlite';

smoke_run('rollback', 'n/a', function () use ($path): void {
    $db = new Database($path);
    $pdo = $db->pdo;
    $migrator = new Migrator(dirname(__DIR__, 2) . '/database/migrations');
    $grammar = new SqliteGrammar();

    $total = (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
    smoke_assert($total > 0, "migrations applied on fresh connect (got {$total})");
    smoke_assert(
        (bool) $pdo->query("SELECT 1 FROM pragma_table_info('issue_status_history') WHERE name='actor'")->fetchColumn(),
        'actor column exists before rollback',
    );

    $rolledBack = $migrator->rollback($pdo, $grammar, 1);
    $remaining = (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
    smoke_assert($remaining === $total - $rolledBack, 'one batch rolled back decrements the migrations count exactly');

    while ($migrator->rollback($pdo, $grammar, 1) > 0) {
        // drain every remaining batch
    }
    smoke_assert(
        (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn() === 0,
        'every batch rolled back (migrations table empty)',
    );
    smoke_assert(
        !(bool) $pdo->query("SELECT 1 FROM pragma_table_info('issue_status_history') WHERE name='actor'")->fetchColumn(),
        'actor column removed by its migration\'s down()',
    );
    smoke_assert(
        !(bool) $pdo->query("SELECT 1 FROM pragma_table_info('error_groups') WHERE name='external_source'")->fetchColumn(),
        'external_source column removed by its migration\'s down() (proves index-then-column drop ordering across migrations)',
    );
    $remainingTables = $pdo->query(
        "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' AND name != 'migrations'"
    )->fetchAll(PDO::FETCH_COLUMN);
    smoke_assert($remainingTables === [], 'every schema table dropped after a full rollback (got: ' . implode(',', $remainingTables) . ')');

    $migrator->migrate($pdo, $grammar, 'BEGIN IMMEDIATE', 'modules');
    smoke_assert(
        (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn() === $total,
        're-migrating after a full rollback rebuilds every migration',
    );
    smoke_assert(
        (bool) $pdo->query("SELECT 1 FROM pragma_table_info('issue_status_history') WHERE name='actor'")->fetchColumn(),
        'actor column exists again after re-migrating',
    );
});

@unlink($path);
