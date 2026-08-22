<?php
declare(strict_types=1);

/**
 * Verifies the Migrator's legacy-install bridge (C-1) against an
 * *authentic* pre-Migrator SQLite database — built from
 * {@see LegacySchemaBuilder}, a verbatim copy of the old inline
 * `runMigration()`/`addColumn()` this repo shipped for years, not a guess at
 * what "legacy" looks like. This is the highest-stakes path in the whole
 * migration system: getting it wrong would silently corrupt or lose real
 * users' existing data on upgrade.
 *
 * Pure SQLite, no service container needed. Run directly:
 *
 *   php tests/smoke/legacy-sqlite-upgrade.php
 */

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/support.php';
require __DIR__ . '/LegacySchemaBuilder.php';

use LogLens\Database;

$path = sys_get_temp_dir() . '/ll_legacy_smoke_' . uniqid('', true) . '.sqlite';
$freshPath = sys_get_temp_dir() . '/ll_legacy_smoke_fresh_' . uniqid('', true) . '.sqlite';

smoke_run('legacy-sqlite-upgrade', 'n/a', function () use ($path, $freshPath): void {
    // 1. Build an authentic pre-Migrator legacy database and seed real data.
    $pdo = new PDO('sqlite:' . $path);
    LegacySchemaBuilder::build($pdo);
    $pdo->exec("INSERT INTO modules(name,slug,color) VALUES ('Billing','billing','#7c3aed')");
    $pdo->exec(
        "INSERT INTO error_groups(fingerprint,severity,title,first_seen,last_seen)"
        . " VALUES ('fp1','error','Boom','2026-01-01 00:00:00','2026-01-01 00:00:00')"
    );
    smoke_assert((int) $pdo->query('PRAGMA user_version')->fetchColumn() === 8, 'legacy DB built at user_version=8');
    smoke_assert(
        !(bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='migrations'")->fetchColumn(),
        'legacy DB has no migrations table yet',
    );
    $pdo = null;

    // 2. Open it through the new Database/Migrator-based code path.
    $db = new Database($path, 'legacy-test');
    $new = $db->pdo;

    // 3. The bridge must have recorded every migration through the legacy
    //    boundary (0007 — see Migrator's docblock) as already applied
    //    without re-running it (the pre-existing data below survives
    //    untouched), but must actually RUN any migration past that boundary
    //    (0008/0009, added after this legacy schema was frozen) — bridging
    //    those too would silently skip a real schema change (this exact bug
    //    shipped once: a legacy database's first-ever connect happening
    //    after a later migration already existed on disk).
    $migrations = $new->query('SELECT migration,batch FROM migrations ORDER BY id')->fetchAll();
    smoke_assert(count($migrations) === 9, 'all 9 migration files recorded (got ' . count($migrations) . ')');
    $batchOf = static fn (string $name): ?int => array_values(array_filter(
        $migrations,
        static fn (array $row): bool => $row['migration'] === $name,
    ))[0]['batch'] ?? null;
    smoke_assert((int) $batchOf('0007_create_remaining_indexes') === 0, '0007 (at the legacy boundary) bridged into batch 0');
    smoke_assert((int) $batchOf('0008_add_error_groups_assignment_columns') !== 0, '0008 (past the boundary) actually ran, not bridged');
    smoke_assert((int) $batchOf('0009_add_tags_attribution_columns') !== 0, '0009 (past the boundary) actually ran, not bridged');

    // 4. Pre-existing data survived untouched.
    $module = $new->query("SELECT * FROM modules WHERE slug='billing'")->fetch(PDO::FETCH_ASSOC);
    smoke_assert($module !== false && $module['name'] === 'Billing', 'pre-existing module row survived the bridge');
    $group = $new->query("SELECT * FROM error_groups WHERE fingerprint='fp1'")->fetch(PDO::FETCH_ASSOC);
    smoke_assert($group !== false && $group['title'] === 'Boom', 'pre-existing error_groups row survived the bridge');

    // 5. The schema now matches a fresh install exactly.
    $freshDb = new Database($freshPath);
    $freshTables = $freshDb->pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    $legacyTables = $new->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    smoke_assert($freshTables === $legacyTables, 'bridged legacy DB has the exact same table set as a fresh install');

    $freshCols = $freshDb->pdo->query('PRAGMA table_xinfo(occurrences)')->fetchAll(PDO::FETCH_COLUMN, 1);
    $legacyCols = $new->query('PRAGMA table_xinfo(occurrences)')->fetchAll(PDO::FETCH_COLUMN, 1);
    smoke_assert($freshCols === $legacyCols, 'bridged legacy occurrences columns (incl. generated) match a fresh install');

    // The specific regression: columns added by a past-the-boundary migration
    // (0008/0009) must actually exist on a bridged legacy database, not just
    // be recorded as "applied" — a comparison of table *sets* above wouldn't
    // have caught them being silently skipped.
    $groupCols = $new->query('PRAGMA table_xinfo(error_groups)')->fetchAll(PDO::FETCH_COLUMN, 1);
    smoke_assert(in_array('assigned_to_id', $groupCols, true), 'error_groups.assigned_to_id exists on the bridged legacy DB (0008)');
    smoke_assert(in_array('assigned_to_label', $groupCols, true), 'error_groups.assigned_to_label exists on the bridged legacy DB (0008)');
    $tagCols = $new->query('PRAGMA table_xinfo(tags)')->fetchAll(PDO::FETCH_COLUMN, 1);
    smoke_assert(in_array('created_by', $tagCols, true), 'tags.created_by exists on the bridged legacy DB (0009)');
    smoke_assert(in_array('updated_by', $tagCols, true), 'tags.updated_by exists on the bridged legacy DB (0009)');

    // 6. Writes work normally through the bridged connection.
    $new->exec("INSERT INTO tags(name,color,icon) VALUES ('urgent','#ff0000','tag')");
    smoke_assert(
        (int) $new->query("SELECT COUNT(*) FROM tags WHERE name='urgent'")->fetchColumn() === 1,
        'writes work normally through the bridged connection',
    );

    // 7. Reconnecting again must not re-trigger the bridge or re-run anything.
    $db2 = new Database($path, 'legacy-test');
    $migrationsAfter = (int) $db2->pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
    smoke_assert($migrationsAfter === 9, 'reconnecting is a no-op (still exactly 9 migration rows)');
});

@unlink($path);
@unlink($freshPath);
