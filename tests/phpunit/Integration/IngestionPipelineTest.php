<?php
declare(strict_types=1);

namespace LogLens\Tests\Integration;

use LogLens\Database;
use LogLens\Repositories\IssueQueryRepository;
use LogLens\Services\IngestionSettingsService;
use LogLens\Services\LogImportService;
use LogLens\Services\ManualIssueService;
use LogLens\Services\ModuleService;
use LogLens\Services\TagService;
use LogLens\Tests\TestCase;
use PDO;

/**
 * The parse → group → query pipeline and the manual-issue / reindex behaviours,
 * each on its own freshly-seeded workspace.
 */
final class IngestionPipelineTest extends TestCase
{
    private const LARAVEL = <<<'LOG'
[2026-01-01 10:00:00] production.ERROR: Order 123 failed for user@example.test {"request_id":"b0f4a4ed-3ae5-4f18-8e06-4b8d459162ae"}
[2026-01-01 10:01:00] production.ERROR: Order 456 failed for other@example.test {"request_id":"1c5349eb-8530-4235-abca-43ceba04a990"}
[2026-01-01 10:02:00] production.WARNING: Gateway unavailable
line one of context
line two of context
[2026-01-01 10:03:00] production.DEBUG: Diagnostic event retained for investigation
LOG;
    private const HORIZON = <<<'LOG'
[2026-01-01 11:00:00] production.ERROR: Horizon worker terminated unexpectedly
[stacktrace]
#0 /srv/app/app/Jobs/ImportFeed.php(42): App\Jobs\ImportFeed->handle()
#1 /srv/app/vendor/laravel/framework/src/Queue/Worker.php(100): Worker->run()
LOG;
    private const ACCESS = <<<'LOG'
10.0.0.1 - - [01/Jan/2026:12:00:00 +0000] "GET /health HTTP/1.1" 200 21 "-" "monitor/1.0"
10.0.0.2 - - [01/Jan/2026:12:01:00 +0000] "POST /api/webhooks/abc?token=secret HTTP/1.1" 502 123 "-" "client/2.0"
10.0.0.3 - - [01/Jan/2026:12:02:00 +0000] "GET /missing HTTP/1.1" 404 99 "-" "browser/1.0"
LOG;
    private const CONSOLE = <<<'LOG'
[2026-01-01 13:00:00] INFO Starting catalog synchronization
2026-01-01 13:01:00 ERROR Catalog synchronization failed for batch 812
#0 /srv/app/app/Console/SyncCatalog.php(12): SyncCatalog->handle()
LOG;

    /** @return array{db:Database,importer:LogImportService,query:IssueQueryRepository,first:array,laravel:string} */
    private function seed(): array
    {
        $laravel = $this->writeFile('laravel.log', self::LARAVEL);
        $horizon = $this->writeFile('horizon.log', self::HORIZON);
        $access = $this->writeFile('access.log', self::ACCESS);
        $console = $this->writeFile('sync-command.log', self::CONSOLE);
        $db = new Database($this->path('test.sqlite'));
        (new IngestionSettingsService($db->pdo))->update(IngestionSettingsService::LEVELS);
        $importer = new LogImportService($db->pdo);
        $first = $importer->importPaths([$laravel, $horizon, $access, $console]);
        return ['db' => $db, 'importer' => $importer, 'query' => new IssueQueryRepository($db->pdo), 'first' => $first, 'laravel' => $laravel];
    }

    public function testParserDistributionDedupAndRetention(): void
    {
        $s = $this->seed();
        $pdo = $s['db']->pdo;
        self::assertSame(10, $s['first']['events'], json_encode($s['first']));
        self::assertSame(['console' => 2, 'horizon' => 1, 'laravel' => 4, 'nginx_access' => 3], $s['first']['by_type']);

        $orderGroups = $pdo->query("SELECT count FROM error_groups WHERE title LIKE 'Order%'")->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(1, $orderGroups);
        self::assertSame(2, (int) $orderGroups[0], 'Dynamic values were not normalized into one group.');

        self::assertSame(['console', 'horizon', 'laravel', 'nginx_access'], $pdo->query('SELECT DISTINCT log_type FROM error_groups ORDER BY log_type')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM error_groups WHERE severity='DEBUG'")->fetchColumn());
        self::assertGreaterThanOrEqual(2, (int) $pdo->query("SELECT COUNT(*) FROM error_groups WHERE severity='INFO'")->fetchColumn());

        $stackTypes = $pdo->query("SELECT log_type FROM error_groups WHERE sample_stack IS NOT NULL AND sample_stack<>'' ORDER BY log_type")->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains('console', $stackTypes);
        self::assertContains('horizon', $stackTypes);
    }

    public function testSummaryReturnsDailySeveritySeries(): void
    {
        $summary = $this->seed()['query']->summary();
        self::assertNotEmpty($summary['daily_severity']);
        self::assertArrayHasKey('day', $summary['daily_severity'][0]);
        self::assertArrayHasKey('severity', $summary['daily_severity'][0]);
    }

    public function testQueryFilteringPaginationAndContextProjection(): void
    {
        $query = $this->seed()['query'];
        self::assertSame(3, $query->list(['log_type' => 'nginx_access'], 200, null, false)['total']);
        self::assertSame(3, $query->list(['source' => 'access.log'], 200, null, false)['total']);

        $withContext = $query->list(['log_type' => 'nginx_access'], 200, null, false, true);
        self::assertStringContainsString('"status"', $withContext['items'][0]['sample_context']);
        $withoutContext = $query->list(['log_type' => 'nginx_access'], 200, null, false, false);
        self::assertArrayNotHasKey('sample_context', $withoutContext['items'][0]);

        $page = $query->list([], 2, 2, false, false);
        self::assertCount(2, $page['items']);
        self::assertSame(2, $page['offset']);
        $sources = $query->sources(1, 2);
        self::assertCount(2, $sources['data']);
        self::assertSame(2, $sources['meta']['pages']);
    }

    public function testUnchangedFilesAreSkippedOnReimport(): void
    {
        $s = $this->seed();
        $second = $s['importer']->importPaths([
            $this->path('laravel.log'), $this->path('horizon.log'),
            $this->path('access.log'), $this->path('sync-command.log'),
        ]);
        self::assertSame(4, $second['skipped']);
    }

    public function testTagServiceCrud(): void
    {
        $pdo = $this->seed()['db']->pdo;
        $tags = new TagService($pdo);
        $tag = $tags->create('Gateway', '#3366ff', 'tag', 'Gateway');
        self::assertGreaterThan(0, $tag['id']);
        self::assertSame('Infrastructure', $tags->update($tag['id'], 'Infrastructure', '#112233', 'database', 'Horizon')['name']);
        $tags->delete($tag['id']);
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM tags WHERE id={$tag['id']}")->fetchColumn());
    }

    public function testManualIssueCreationAndFiltering(): void
    {
        $s = $this->seed();
        $pdo = $s['db']->pdo;
        $query = $s['query'];
        $pdo->exec("INSERT INTO tags(name,color,icon) VALUES('Orders','#8b5cf6','tag')");
        $tagId = (int) $pdo->lastInsertId();
        $module = (new ModuleService($pdo))->create('Billing', 'billing', '#7c3aed');
        $id = (new ManualIssueService($pdo))->create([
            'title' => 'Add an audit export for administrators',
            'description' => 'Provide a downloadable audit trail.',
            'kind' => 'feature_request',
            'severity' => 'INFO',
            'source_frame' => 'app/Services/AuditExport.php',
            'tag_ids' => [$tagId],
            'module_id' => $module['id'],
        ]);
        $detail = $query->detail($id);
        self::assertSame('manual', $detail['group']['origin']);
        self::assertSame('feature_request', $detail['group']['kind']);
        self::assertSame(0, (int) $detail['group']['count']);
        self::assertCount(1, $detail['tags']);
        self::assertCount(1, $detail['status_history']);
        self::assertSame('billing', $detail['group']['module_slug']);
        self::assertSame(1, $query->list(['module' => 'billing'], 20, 1, false, false)['total']);
        self::assertSame(1, $query->list(['origin' => 'manual', 'kind' => 'feature_request'], 20, 1, false, false)['total']);
        self::assertSame(1, $query->list(['date' => gmdate('Y-m-d'), 'origin' => 'manual'], 20, 1, false, false)['total']);
    }

    public function testReindexMarksReoccurrenceAppliesTagRuleAndPreservesManual(): void
    {
        $s = $this->seed();
        $pdo = $s['db']->pdo;
        $orderId = (int) $pdo->query("SELECT id FROM error_groups WHERE title LIKE 'Order%'")->fetchColumn();
        $pdo->prepare("UPDATE error_groups SET status='fixed' WHERE id=?")->execute([$orderId]);
        $pdo->exec("INSERT INTO tags(name,color,icon) VALUES('Orders','#8b5cf6','tag')");
        $tagId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO tag_rules(tag_id,match_word) VALUES(?,'Order')")->execute([$tagId]);
        $manualId = (new ManualIssueService($pdo))->create(['title' => 'Keep me', 'kind' => 'task', 'severity' => 'INFO']);

        file_put_contents($s['laravel'], "[2026-01-01 10:04:00] production.ERROR: Order 789 failed for last@example.test\n", FILE_APPEND);
        $third = $s['importer']->importPaths([$s['laravel']]);
        self::assertSame(1, $third['events']);
        self::assertSame(3, (int) $pdo->query("SELECT count FROM error_groups WHERE id={$orderId}")->fetchColumn());
        self::assertSame('reoccurred', $pdo->query("SELECT status FROM error_groups WHERE id={$orderId}")->fetchColumn());
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM issue_status_history WHERE group_id={$orderId} AND to_status='reoccurred'")->fetchColumn());
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM error_group_tags WHERE group_id={$orderId} AND tag_id={$tagId} AND source='rule'")->fetchColumn());
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM error_groups WHERE id={$manualId}")->fetchColumn(), 'Aggregate refresh deleted a manual issue.');
    }
}
