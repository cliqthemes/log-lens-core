<?php
declare(strict_types=1);

namespace LogLens\Tests\Unit;

use LogLens\Repositories\IssueQueryRepository;
use LogLens\Services\IngestionSettingsService;
use LogLens\Services\LogImportService;
use LogLens\Tests\TestCase;
use PDO;

/**
 * Ingestion end-to-end against a fresh, isolated database. Each test gets its
 * own SQLite file, so counts never depend on what other tests imported.
 */
final class IngestionTest extends TestCase
{
    private const LARAVEL_LOG = <<<'LOG'
[2026-01-01 10:00:00] production.ERROR: Order 123 failed for user@example.test {"request_id":"b0f4a4ed-3ae5-4f18-8e06-4b8d459162ae"}
[2026-01-01 10:01:00] production.ERROR: Order 456 failed for other@example.test {"request_id":"1c5349eb-8530-4235-abca-43ceba04a990"}
[2026-01-01 10:02:00] production.WARNING: Gateway unavailable
line one of context
line two of context
[2026-01-01 10:03:00] production.DEBUG: Diagnostic event retained for investigation
LOG;

    private const NGINX_LOG = <<<'LOG'
10.0.0.1 - - [01/Jan/2026:12:00:00 +0000] "GET /health HTTP/1.1" 200 21 "-" "monitor/1.0"
10.0.0.2 - - [01/Jan/2026:12:01:00 +0000] "POST /api/webhooks/abc?token=secret HTTP/1.1" 502 123 "-" "client/2.0"
10.0.0.3 - - [01/Jan/2026:12:02:00 +0000] "GET /missing HTTP/1.1" 404 99 "-" "browser/1.0"
LOG;

    public function testFreshWorkspaceIngestsOnlyErrorsAndWarningsByDefault(): void
    {
        $database = $this->makeDatabase();
        $settings = new IngestionSettingsService($database->pdo);
        self::assertSame(['ERROR', 'WARNING'], $settings->severities());
    }

    public function testUpdatingSeverityAllowlistIsPersisted(): void
    {
        $database = $this->makeDatabase();
        $settings = new IngestionSettingsService($database->pdo);
        $configured = $settings->update(IngestionSettingsService::LEVELS);
        self::assertSame(IngestionSettingsService::LEVELS, $configured['ingested_severities']);
    }

    public function testImportCountsEventsAndNormalizesDynamicValues(): void
    {
        $database = $this->makeDatabase();
        (new IngestionSettingsService($database->pdo))->update(IngestionSettingsService::LEVELS);

        $laravel = $this->writeFile('laravel.log', self::LARAVEL_LOG);
        $nginx = $this->writeFile('access.log', self::NGINX_LOG);

        $result = (new LogImportService($database->pdo))->importPaths([$laravel, $nginx]);
        self::assertSame(7, $result['events'], 'Expected 4 Laravel + 3 nginx events.');
        self::assertSame(['laravel' => 4, 'nginx_access' => 3], $result['by_type']);

        // The two "Order N failed" lines collapse into one normalized group of 2.
        $orderCounts = $database->pdo
            ->query("SELECT count FROM error_groups WHERE title LIKE 'Order%'")
            ->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(1, $orderCounts);
        self::assertSame(2, (int) $orderCounts[0]);
    }

    public function testQueryRepositoryFiltersByLogTypeAndSource(): void
    {
        $database = $this->makeDatabase();
        (new IngestionSettingsService($database->pdo))->update(IngestionSettingsService::LEVELS);
        $laravel = $this->writeFile('laravel.log', self::LARAVEL_LOG);
        $nginx = $this->writeFile('access.log', self::NGINX_LOG);
        (new LogImportService($database->pdo))->importPaths([$laravel, $nginx]);

        $query = new IssueQueryRepository($database->pdo);
        self::assertSame(3, $query->list(['log_type' => 'nginx_access'], 200, null, false)['total']);
        self::assertSame(3, $query->list(['source' => 'access.log'], 200, null, false)['total']);
    }

    public function testContextIsProjectedOnlyWhenRequested(): void
    {
        $database = $this->makeDatabase();
        (new IngestionSettingsService($database->pdo))->update(IngestionSettingsService::LEVELS);
        $nginx = $this->writeFile('access.log', self::NGINX_LOG);
        (new LogImportService($database->pdo))->importPaths([$nginx]);

        $query = new IssueQueryRepository($database->pdo);
        $withContext = $query->list(['log_type' => 'nginx_access'], 200, null, false, true);
        self::assertArrayHasKey('sample_context', $withContext['items'][0]);
        self::assertStringContainsString('"status"', $withContext['items'][0]['sample_context']);

        $withoutContext = $query->list(['log_type' => 'nginx_access'], 200, null, false, false);
        self::assertArrayNotHasKey('sample_context', $withoutContext['items'][0]);
    }
}
