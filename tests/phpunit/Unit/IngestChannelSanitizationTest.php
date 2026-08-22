<?php
declare(strict_types=1);

namespace LogLens\Tests\Unit;

use LogLens\Plugins\Ingest\IngestService;
use LogLens\Repositories\IssueQueryRepository;
use LogLens\Tests\TestCase;

/**
 * Security regression: the HTTP-ingest `channel` field is a client-supplied
 * label, but it used to flow unsanitized into the synthetic
 * `source_files.path` that raw-source lookups later read from disk verbatim.
 * A `channel` containing "../" sequences let a holder of the (low-trust,
 * publicly-embeddable) ingest key plant a path-traversal payload that a
 * dashboard viewer's "view raw source" action would then read from the
 * filesystem. See IngestService::sanitizeChannel().
 */
final class IngestChannelSanitizationTest extends TestCase
{
    public function testTraversalInChannelIsNeutralized(): void
    {
        $pdo = $this->makeDatabase()->pdo;
        (new IngestService($pdo))->ingest([
            'events' => [[
                'message' => 'boom',
                'severity' => 'ERROR',
                'channel' => '../../../../../../etc/passwd',
            ]],
        ], null);

        $path = $pdo->query('SELECT path FROM source_files')->fetchColumn();
        self::assertIsString($path);
        self::assertStringNotContainsString('..', $path, 'A sanitized channel must never let the stored path escape its directory.');
        self::assertStringNotContainsString('/etc/passwd', $path);
    }

    public function testRawOccurrenceRefusesToReadAPathContainingTraversal(): void
    {
        // Defense in depth (IssueQueryRepository::rawOccurrence): even if a
        // ".." ever reached source_files.path some other way, the raw-read
        // path must refuse it rather than following it off disk.
        $db = $this->makeDatabase();
        $pdo = $db->pdo;
        $pdo->exec(
            "INSERT INTO source_files(path,size,modified_at,log_type,channel,module_id) "
            . "VALUES('http-ingest/0/../../../../../../etc/passwd',0,0,'http','app',NULL)"
        );
        $sourceFileId = (int) $pdo->lastInsertId();
        $pdo->exec(
            "INSERT INTO error_groups(fingerprint,severity,title,first_seen,last_seen,status,origin,kind) "
            . "VALUES('fp-traversal','error','Boom','2026-01-01 00:00:00','2026-01-01 00:00:00','open','ingested','issue')"
        );
        $groupId = (int) $pdo->lastInsertId();
        $pdo->exec(
            "INSERT INTO occurrences(group_id,source_file_id,exact_fingerprint,occurred_at,severity,byte_start,byte_end) "
            . "VALUES({$groupId},{$sourceFileId},'fp-exact','2026-01-01 00:00:00','error',0,4096)"
        );
        $occurrenceId = (int) $pdo->lastInsertId();

        $repository = new IssueQueryRepository($pdo);
        self::assertNull($repository->rawOccurrence($occurrenceId), 'A path containing ".." must be rejected outright.');
    }
}
