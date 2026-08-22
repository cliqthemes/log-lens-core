<?php
declare(strict_types=1);

namespace LogLens\Tests\Unit;

use LogLens\Plugins\Analytics\AnalyticsService;
use LogLens\Services\IngestionSettingsService;
use LogLens\Services\LogImportService;
use LogLens\Tests\TestCase;
use PDO;

/**
 * M-9: access analytics reads the generated columns + partial indexes rather
 * than json_extract-scanning every occurrence. These tests pin both the output
 * and the presence of the supporting index infrastructure.
 */
final class AccessAnalyticsTest extends TestCase
{
    /** nginx access lines timestamped "now" so they land inside the query window. */
    private function nginxLog(): string
    {
        $stamp = date('d/M/Y:H:i:s O');
        return implode("\n", [
            "10.0.0.1 - - [{$stamp}] \"GET /health HTTP/1.1\" 200 21 \"-\" \"monitor/1.0\"",
            "10.0.0.2 - - [{$stamp}] \"POST /api/orders HTTP/1.1\" 502 123 \"-\" \"client/2.0\"",
            "10.0.0.3 - - [{$stamp}] \"GET /missing HTTP/1.1\" 404 99 \"-\" \"browser/1.0\"",
        ]) . "\n";
    }

    private function seed(): PDO
    {
        $database = $this->makeDatabase();
        (new IngestionSettingsService($database->pdo))->update(IngestionSettingsService::LEVELS);
        (new LogImportService($database->pdo))->importPaths([$this->writeFile('access.log', $this->nginxLog())]);
        return $database->pdo;
    }

    public function testSummaryClassifiesStatusCodesAndErrorRate(): void
    {
        $summary = (new AnalyticsService($this->seed()))->summary(30);

        self::assertSame(3, $summary['total']);
        // One 2xx, one 4xx (404), one 5xx (502) → 2 of 3 are errors.
        $byClass = [];
        foreach ($summary['status_classes'] as $row) {
            $byClass[$row['class']] = $row['count'];
        }
        self::assertSame(1, $byClass['2xx']);
        self::assertSame(1, $byClass['4xx']);
        self::assertSame(1, $byClass['5xx']);
        self::assertEqualsWithDelta(0.6667, $summary['error_rate'], 0.0001);
    }

    public function testSummaryAggregatesMethodsAndPaths(): void
    {
        $summary = (new AnalyticsService($this->seed()))->summary(30);

        $methods = [];
        foreach ($summary['methods'] as $row) {
            $methods[$row['method']] = $row['count'];
        }
        self::assertSame(2, $methods['GET']);
        self::assertSame(1, $methods['POST']);

        self::assertSame(3, $summary['unique_paths']);
        // The 5xx request on /api/orders must surface as an error path.
        $errorPaths = array_column($summary['error_paths'], 'path');
        self::assertContains('/api/orders', $errorPaths);
    }

    public function testPartialAccessIndexesExist(): void
    {
        $pdo = $this->seed();
        $indexes = $pdo
            ->query("SELECT name FROM sqlite_master WHERE type='index' AND name LIKE 'idx_occ_access_%'")
            ->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains('idx_occ_access_time', $indexes);
        self::assertContains('idx_occ_access_path', $indexes);
        self::assertContains('idx_occ_access_method', $indexes);
    }

    public function testNonAccessOccurrencesDoNotBreakGeneratedColumns(): void
    {
        // A plain-text (non-JSON) context_preview must yield NULL access fields,
        // not a "malformed JSON" insert failure.
        $database = $this->makeDatabase();
        (new IngestionSettingsService($database->pdo))->update(IngestionSettingsService::LEVELS);
        $laravel = $this->writeFile('laravel.log', "[2026-01-01 10:00:00] production.ERROR: Boom\nnon-json context line\n");
        (new LogImportService($database->pdo))->importPaths([$laravel]);

        $nulls = (int) $database->pdo
            ->query('SELECT COUNT(*) FROM occurrences WHERE access_status IS NULL')
            ->fetchColumn();
        self::assertGreaterThan(0, $nulls);
    }
}
