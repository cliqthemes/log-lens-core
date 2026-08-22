<?php
declare(strict_types=1);

namespace LogLens\Tests\Integration;

use InvalidArgumentException;
use LogLens\Database;
use LogLens\Services\ApplicationRegistry;
use LogLens\Services\BulkIssueService;
use LogLens\Services\IngestionSettingsService;
use LogLens\Services\LogImportService;
use LogLens\Services\MaintenanceService;
use LogLens\Services\ManualIssueService;
use LogLens\Services\SourcePathRepairService;
use LogLens\Tests\TestCase;
use PDO;

/**
 * Issue-management workflows: incoming-import module classification, bulk
 * mutations, application isolation, workspace path repair, and scoped log
 * deletion — each on its own fresh workspace.
 */
final class IssueWorkflowTest extends TestCase
{
    private function database(): Database
    {
        $db = new Database($this->path('test.sqlite'));
        (new IngestionSettingsService($db->pdo))->update(IngestionSettingsService::LEVELS);
        return $db;
    }

    public function testIncomingImportMovesFilesAndClassifiesModules(): void
    {
        $db = $this->database();
        $importer = new LogImportService($db->pdo);
        $incoming = $this->path('logs');
        $processed = $this->path('processed');
        mkdir($incoming . '/billing', 0775, true);
        file_put_contents($incoming . '/scheduled-command.log', "[2026-01-02 08:00:00] ERROR Scheduled command failed\n");
        file_put_contents($incoming . '/billing/payment-worker.log', "[2026-01-02 08:01:00] ERROR Payment capture failed\n");

        $result = $importer->importIncoming($incoming, $processed);
        self::assertSame(2, $result['events']);
        self::assertFalse(is_file($incoming . '/scheduled-command.log'));
        self::assertTrue(is_file($processed . '/scheduled-command.log'));
        self::assertTrue(is_file($processed . '/billing/payment-worker.log'));
        self::assertSame(1, (int) $db->pdo->query(
            "SELECT COUNT(*) FROM error_groups g JOIN modules m ON m.id=g.module_id
             WHERE m.slug='billing' AND g.title LIKE 'Payment capture failed%'"
        )->fetchColumn());
    }

    public function testBulkStatusAndTagAssignment(): void
    {
        $pdo = $this->database()->pdo;
        $manual = new ManualIssueService($pdo);
        $a = $manual->create(['title' => 'First', 'kind' => 'task', 'severity' => 'INFO']);
        $b = $manual->create(['title' => 'Second', 'kind' => 'bug', 'severity' => 'ERROR']);
        $pdo->exec("INSERT INTO tags(name,color,icon) VALUES('Triage','#8b5cf6','tag')");
        $tagId = (int) $pdo->lastInsertId();

        $bulk = new BulkIssueService($pdo);
        $result = $bulk->execute(['ids' => [$a, $b], 'action' => 'status', 'status' => 'in_progress', 'note' => 'Bulk triage.']);
        self::assertSame(2, $result['updated']);
        self::assertSame(2, (int) $pdo->query(
            "SELECT COUNT(*) FROM issue_status_history WHERE group_id IN ({$a},{$b}) AND to_status='in_progress' AND note='Bulk triage.'"
        )->fetchColumn());

        $bulk->execute(['ids' => [$a, $b], 'action' => 'add_tag', 'tag_id' => $tagId]);
        self::assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM error_group_tags WHERE group_id IN ({$a},{$b}) AND tag_id={$tagId}")->fetchColumn());
    }

    public function testApplicationRegistryCreatesIsolatedWorkspaces(): void
    {
        $root = $this->path('registry-workspace');
        mkdir($root, 0775, true);
        $registry = new ApplicationRegistry($root);
        $registered = $registry->all();
        $second = $registry->create('Customer Portal');

        self::assertCount(1, $registered);
        self::assertSame('default', $registered[0]['id']);
        self::assertSame('customer-portal', $second['id']);
        self::assertTrue(is_dir($root . '/applications/customer-portal/logs'));
        self::assertNotSame(
            $registry->absolutePath($registry->resolve('default'), 'database'),
            $registry->absolutePath($registry->resolve('customer-portal'), 'database'),
        );
    }

    public function testWorkspacePathRepairRelocatesSizeVerifiedSources(): void
    {
        $pdo = $this->database()->pdo;
        $processed = $this->path('relocated/processed');
        $sources = $this->path('relocated/sources');
        mkdir($processed, 0775, true);
        mkdir($sources, 0775, true);
        file_put_contents($processed . '/moved.log', "moved source\n");
        $pdo->prepare('INSERT INTO source_files(path,size,modified_at,last_offset,log_type,channel) VALUES(?,?,?,?,?,?)')
            ->execute(['/old/workspace/processed/moved.log', 13, time(), 13, 'console', 'moved.log']);
        $id = (int) $pdo->lastInsertId();

        $result = (new SourcePathRepairService($pdo, $processed, $sources))->repairMovedWorkspacePaths();
        self::assertSame(1, $result['source_files']);
        self::assertSame(realpath($processed . '/moved.log'), $pdo->query("SELECT path FROM source_files WHERE id={$id}")->fetchColumn());
    }

    public function testScopedLogDeletionRemovesOccurrencesButKeepsRawAndManual(): void
    {
        $db = $this->database();
        $pdo = $db->pdo;
        $access = $this->writeFile('access.log', "10.0.0.1 - - [01/Jan/2026:12:00:00 +0000] \"GET /a HTTP/1.1\" 500 21 \"-\" \"x/1.0\"\n"
            . "10.0.0.2 - - [01/Jan/2026:12:01:00 +0000] \"GET /b HTTP/1.1\" 500 21 \"-\" \"x/1.0\"\n"
            . "10.0.0.3 - - [01/Jan/2026:12:02:00 +0000] \"GET /c HTTP/1.1\" 500 21 \"-\" \"x/1.0\"\n");
        (new LogImportService($pdo))->importPaths([$access]);
        (new ManualIssueService($pdo))->create(['title' => 'Keep me', 'kind' => 'task', 'severity' => 'INFO']);

        $maintenance = new MaintenanceService($pdo);
        $source = $pdo->query("SELECT id,path FROM source_files WHERE log_type='nginx_access' ORDER BY id LIMIT 1")->fetch();
        $sourceId = (int) $source['id'];
        self::assertSame(3, $maintenance->previewLogDeletion(null, $sourceId)['occurrences']);

        try {
            $maintenance->deleteLogs(null, $sourceId, 'DELETE');
            self::fail('Invalid confirmation was accepted.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $deleted = $maintenance->deleteLogs(null, $sourceId, MaintenanceService::LOG_DELETION_CONFIRMATION);
        self::assertSame(3, $deleted['deleted']['occurrences']);
        self::assertSame(0, $maintenance->previewLogDeletion(null, $sourceId)['occurrences']);
        self::assertTrue(is_file($source['path']), 'The raw archived file must be preserved.');
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM error_groups WHERE origin='manual'")->fetchColumn());
    }
}
