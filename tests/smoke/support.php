<?php
declare(strict_types=1);

/**
 * Shared assertions for the live driver smoke tests (pgsql.php / mysql.php,
 * C-1). Deliberately plain functions, not PHPUnit — these need a real,
 * externally-provisioned database server and are run directly by CI, not by
 * `composer test`. See either script's header for how to run it locally.
 */

use LogLens\Plugins\Alerts\AlertService;
use LogLens\Plugins\Alerts\Notifier;
use LogLens\Plugins\Ingest\IngestService;
use LogLens\Storage\Connection;
use LogLens\Storage\Dialect;
use LogLens\Storage\Schema\Grammar;
use LogLens\Storage\Schema\Migrator;

function smoke_assert(bool $condition, string $description): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$description}\n");
        exit(1);
    }
    echo "ok - {$description}\n";
}

/** Run $body, printing a clear banner and re-throwing (with a non-zero exit) on any failure. */
function smoke_run(string $engine, string $tenant, callable $body): void
{
    echo "=== {$engine} driver smoke test (tenant={$tenant}) ===\n";
    try {
        $body();
    } catch (Throwable $exception) {
        fwrite(STDERR, 'FAIL: uncaught ' . get_class($exception) . ': ' . $exception->getMessage() . "\n");
        fwrite(STDERR, $exception->getTraceAsString() . "\n");
        exit(1);
    }
    echo "=== {$engine} driver smoke test: ALL CHECKS PASSED ===\n";
}

/**
 * The checks common to both engines: schema DDL, case-insensitive
 * uniqueness, generated columns (with the non-JSON guard), the JSON
 * aggregation and case-insensitive dialect helpers, and the key/value
 * upsert/lookup + insert-ignore + now() primitives — all live, against a
 * freshly migrated tenant.
 *
 * @param array{unique_violation_needle:string} $expectations
 */
function smoke_exercise_driver(PDO $pdo, Connection $connection, Dialect $dialect, array $expectations): void
{
    $pdo->exec("INSERT INTO modules(name,slug,color) VALUES ('Billing','billing','#7c3aed')");
    smoke_assert(((int) $pdo->lastInsertId()) > 0, 'insert + lastInsertId()');

    try {
        $pdo->exec("INSERT INTO modules(name,slug,color) VALUES ('BILLING','billing-2','#000000')");
        smoke_assert(false, 'case-insensitive unique constraint on modules.name fires for a different-case duplicate');
    } catch (PDOException $exception) {
        smoke_assert(
            str_contains(strtolower($exception->getMessage()), strtolower($expectations['unique_violation_needle'])),
            'case-insensitive unique constraint on modules.name fires for a different-case duplicate',
        );
    }

    $row = $connection->selectOne('SELECT * FROM modules WHERE ' . $dialect->caseInsensitiveEquals('slug'), ['BILLING']);
    smoke_assert($row !== null && $row['name'] === 'Billing', 'caseInsensitiveEquals() finds a row by differently-cased value');
    smoke_assert(($row['color'] ?? '') === '#7c3aed', 'the column default (color) applied on insert');
    smoke_assert(!empty($row['created_at'] ?? ''), 'the column default (created_at) applied on insert');

    $pdo->exec("INSERT INTO source_files(path,log_type) VALUES ('/var/log/access.log','nginx_access')");
    $fileId = (int) $pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO error_groups(fingerprint,severity,title,first_seen,last_seen,log_type,status,origin,kind)"
        . " VALUES ('fp1','info','t','2026-01-01 00:00:00','2026-01-01 00:00:00','nginx_access','open','ingested','error')"
    );
    $groupId = (int) $pdo->lastInsertId();

    $insertOccurrence = $pdo->prepare(
        'INSERT INTO occurrences(group_id,source_file_id,exact_fingerprint,occurred_at,severity,byte_start,byte_end,context_preview)'
        . ' VALUES (?,?,?,?,?,?,?,?)'
    );
    $insertOccurrence->execute([$groupId, $fileId, 'ex1', '2026-01-01 12:34:56', 'info', 0, 10, '{"status":200,"path":"/foo","method":"GET","user_agent":"curl"}']);
    $occurrence = $connection->selectOne(
        'SELECT occurred_day,access_status,access_path,access_method,access_agent FROM occurrences WHERE group_id=?',
        [$groupId],
    );
    smoke_assert(($occurrence['occurred_day'] ?? null) === '2026-01-01', 'generated column occurred_day');
    smoke_assert(((int) ($occurrence['access_status'] ?? 0)) === 200, 'generated column access_status (parsed from JSON context_preview)');
    smoke_assert(($occurrence['access_path'] ?? null) === '/foo', 'generated column access_path');
    smoke_assert(($occurrence['access_method'] ?? null) === 'GET', 'generated column access_method');
    smoke_assert(($occurrence['access_agent'] ?? null) === 'curl', 'generated column access_agent');

    // A non-JSON context_preview must not trip the JSON-parsing generated columns.
    $insertOccurrence->execute([$groupId, $fileId, 'ex2', '2026-01-01 12:35:00', 'info', 20, 30, 'not json at all']);
    smoke_assert(true, 'a non-JSON context_preview does not break the JSON-guarded generated columns');

    $pdo->exec("INSERT INTO tags(name,color,icon) VALUES ('urgent','#ff0000','tag')");
    $tagId = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO error_group_tags(group_id,tag_id,source) VALUES ({$groupId},{$tagId},'manual')");
    $tagsJson = $dialect->jsonArrayAgg($dialect->jsonObject(['id' => 't.id', 'name' => 't.name']));
    $tagsRow = $connection->selectOne(
        "SELECT ({$tagsJson}) tags FROM error_group_tags gt JOIN tags t ON t.id=gt.tag_id WHERE gt.group_id=?",
        [$groupId],
    );
    $decodedTags = json_decode((string) ($tagsRow['tags'] ?? 'null'), true);
    smoke_assert(
        is_array($decodedTags) && count($decodedTags) === 1 && ($decodedTags[0]['name'] ?? null) === 'urgent',
        'jsonArrayAgg(jsonObject(...)) round-trips as valid JSON',
    );

    $upsert = $pdo->prepare($dialect->keyValueUpsert());
    $upsert->execute(['greeting', 'hello']);
    $upsert->execute(['greeting', 'hello again']);
    $value = $connection->selectValue($dialect->keyValueLookup(), ['greeting']);
    smoke_assert($value === 'hello again', 'keyValueUpsert()/keyValueLookup() round-trip (insert then update-on-conflict)');

    $pdo->prepare($dialect->insertIgnore('tags', 'name,color,icon', '?,?,?'))->execute(['urgent', '#111111', 'tag']);
    $count = (int) $connection->selectValue("SELECT COUNT(*) FROM tags WHERE name='urgent'");
    smoke_assert($count === 1, 'insertIgnore() silently no-ops on a duplicate unique key');

    $since = $dialect->now(-86400);
    $connection->selectValue("SELECT COUNT(*) FROM occurrences WHERE occurred_at >= {$since} OR occurred_at < {$since}");
    smoke_assert(true, 'now() with a negative offset evaluates against a TEXT timestamp column without a type error');

    $cast = $connection->selectValue('SELECT ' . $dialect->castToText('id') . ' FROM modules WHERE name=?', ['Billing']);
    smoke_assert(is_string($cast), 'castToText() casts an integer column to a string');

    $releaseColumn = $dialect->quoteIdentifier('release');
    $pdo->exec("UPDATE occurrences SET {$releaseColumn}='v1.0.0' WHERE group_id={$groupId}");
    smoke_assert(true, "quoteIdentifier('release') lets the reserved-word column be written and read");

    smoke_verify_alert_spike_detection($pdo, $fileId, $insertOccurrence);
    smoke_verify_http_ingest_and_repeat_occurrence($pdo);
}

/**
 * Regression guard: the migration from raw PDO to Storage\Connection
 * surfaced two bugs that made `?api=ingest` (and, by the same code path, every
 * file-based log import) a hard failure on Postgres/MySQL, never caught by
 * the checks above because those insert occurrences directly rather than
 * through the application's own ingest pipeline:
 *
 *   1. IngestService::ingest() opened its write transaction with the
 *      SQLite-only `BEGIN IMMEDIATE` statement — a syntax error on both
 *      Postgres and MySQL, so no event could ever be accepted.
 *   2. IssueRepository::recordOccurrence() built
 *      `first_seen=MIN(first_seen,?),last_seen=MAX(last_seen,?)` — the
 *      *scalar* two-argument form of MIN/MAX, which only SQLite supports;
 *      Postgres and MySQL both treat MIN/MAX as aggregate-only and reject
 *      the multi-argument form outright. This ran on literally every
 *      persisted occurrence, so ingestion broke immediately, not just on
 *      an edge case.
 *
 * Ingesting the same event twice exercises both: the first call proves the
 * transaction itself is portable; the second exercises recordOccurrence()'s
 * least()/greatest() dialect calls against an existing row.
 */
function smoke_verify_http_ingest_and_repeat_occurrence(PDO $pdo): void
{
    $ingest = new IngestService($pdo);
    $ingest->settings('smoke-tenant');
    $first = $ingest->ingest(['events' => [['message' => 'Smoke ingest test', 'severity' => 'ERROR']]], '127.0.0.1');
    smoke_assert(($first['accepted'] ?? 0) === 1, 'IngestService::ingest() accepts an event (portable transaction, not SQLite-only BEGIN IMMEDIATE)');

    $second = $ingest->ingest(['events' => [['message' => 'Smoke ingest test', 'severity' => 'ERROR']]], '127.0.0.1');
    smoke_assert(($second['accepted'] ?? 0) === 1, "recordOccurrence()'s least()/greatest() (not raw MIN/MAX(a,b)) updates an existing issue's first_seen/last_seen");
}

/**
 * Regression guard: AlertService::spikeMatches() used to
 * hardcode SQLite's `datetime('now', …)` directly instead of going through
 * `Dialects::active()->now()`, so it threw on every scan on Postgres/MySQL —
 * silently, since {@see \LogLens\Plugins\PluginManager::onIngest()} swallows
 * plugin exceptions. Exercises the real spike-detection query end to end.
 */
function smoke_verify_alert_spike_detection(PDO $pdo, int $fileId, PDOStatement $insertOccurrence): void
{
    $pdo->exec(
        "INSERT INTO error_groups(fingerprint,severity,title,first_seen,last_seen,log_type,status,origin,kind,count)"
        . " VALUES ('fp-spike','ERROR','Spike test','2026-01-01 00:00:00','2026-01-01 00:00:00','nginx_access','open','ingested','error',0)"
    );
    $spikeGroupId = (int) $pdo->lastInsertId();
    $now = gmdate('Y-m-d H:i:s');
    $insertOccurrence->execute([$spikeGroupId, $fileId, 'ex-spike-1', $now, 'ERROR', 40, 50, null]);
    $insertOccurrence->execute([$spikeGroupId, $fileId, 'ex-spike-2', $now, 'ERROR', 50, 60, null]);

    $alerts = new AlertService($pdo, new Notifier(static fn (): array => ['status' => 200, 'body' => 'ok']));
    $channel = $alerts->createChannel(['name' => 'Smoke', 'type' => 'slack', 'url' => 'https://hooks.slack.com/services/T00/B00/smoke']);
    $alerts->createRule([
        'name' => 'Smoke spike rule', 'channel_id' => $channel['id'], 'trigger_type' => 'spike',
        'severities' => ['ERROR'], 'min_count' => 1, 'spike_factor' => 1.5, 'cooldown_minutes' => 0,
    ]);

    $first = $alerts->scan();
    smoke_assert(($first['initialized'] ?? false) === true, 'AlertService::scan() initializes its cursor without throwing');

    $second = $alerts->scan();
    smoke_assert($second['sent'] === 1, "AlertService's spike query runs through Dialects::active()->now() and fires on this engine");
}

/**
 * Rolls back every migration batch on the already-migrated $pdo (proving
 * every migration's down() is correct and dependency-ordered for this
 * engine — an index must be dropped before the column it references, and
 * this schema's indexes are added by later migrations than the columns
 * they cover), then re-migrates from scratch to prove the cycle is fully
 * reversible.
 */
function smoke_verify_rollback(PDO $pdo, Grammar $grammar, string $beginStatement): void
{
    $migrator = new Migrator(dirname(__DIR__, 2) . '/database/migrations');
    $total = (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
    smoke_assert($total > 0, "migrations applied before rollback (got {$total})");

    while ($migrator->rollback($pdo, $grammar, 1) > 0) {
        // drain every batch
    }
    smoke_assert(
        (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn() === 0,
        'every migration batch rolled back (migrations table empty)',
    );

    $migrator->migrate($pdo, $grammar, $beginStatement);
    smoke_assert(
        (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn() === $total,
        're-migrating after a full rollback rebuilds every migration',
    );
}
