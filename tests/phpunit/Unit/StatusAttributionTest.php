<?php
declare(strict_types=1);

namespace LogLens\Tests\Unit;

use LogLens\Services\BulkIssueService;
use LogLens\Services\WorkflowService;
use LogLens\Tests\TestCase;
use PDO;

/**
 * C-2: workflow status changes are attributed to the acting user in the audit
 * trail (issue_status_history.actor).
 */
final class StatusAttributionTest extends TestCase
{
    private function seedIssue(PDO $pdo, string $fingerprint = 'fp1'): int
    {
        $pdo->prepare(
            "INSERT INTO error_groups(fingerprint,severity,environment,title,count,first_seen,last_seen,
                sample_message,created_at,status,log_type,channel,origin,kind)
             VALUES(?,?,?,?,1,?,?,?,?,?,?,?,?,?)"
        )->execute([
            $fingerprint, 'ERROR', 'production', 'Boom', '2026-01-01 00:00:00', '2026-01-01 00:00:00',
            'msg', '2026-01-01 00:00:00', 'open', 'laravel', 'app', 'ingested', 'issue',
        ]);
        return (int) $pdo->lastInsertId();
    }

    public function testSingleStatusChangeRecordsTheActor(): void
    {
        $pdo = $this->makeDatabase()->pdo;
        $id = $this->seedIssue($pdo);

        (new WorkflowService($pdo))->change($id, 'fixed', 'done', 'Alice');

        $actor = $pdo->query(
            "SELECT actor FROM issue_status_history WHERE group_id={$id} ORDER BY id DESC LIMIT 1"
        )->fetchColumn();
        self::assertSame('Alice', $actor);
    }

    public function testUnattributedChangeStoresNullActor(): void
    {
        $pdo = $this->makeDatabase()->pdo;
        $id = $this->seedIssue($pdo);

        (new WorkflowService($pdo))->change($id, 'fixed', '');

        $actor = $pdo->query(
            "SELECT actor FROM issue_status_history WHERE group_id={$id} ORDER BY id DESC LIMIT 1"
        )->fetchColumn();
        self::assertNull($actor);
    }

    public function testBulkStatusChangeRecordsTheActor(): void
    {
        $pdo = $this->makeDatabase()->pdo;
        $a = $this->seedIssue($pdo, 'fpa');
        $b = $this->seedIssue($pdo, 'fpb');

        (new BulkIssueService($pdo))->execute(
            ['action' => 'status', 'ids' => [$a, $b], 'status' => 'fixed', 'note' => 'batch'],
            'Bob',
        );

        $actors = $pdo->query(
            'SELECT DISTINCT actor FROM issue_status_history WHERE actor IS NOT NULL'
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['Bob'], $actors);
    }
}
