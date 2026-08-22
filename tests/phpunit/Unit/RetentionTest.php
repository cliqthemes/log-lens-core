<?php
declare(strict_types=1);

namespace LogLens\Tests\Unit;

use LogLens\Services\BackgroundSyncService;
use LogLens\Services\OccurrenceRetentionService;
use LogLens\Services\ProcessedRetentionService;
use LogLens\Tests\TestCase;
use PDO;

/**
 * Retention: the processed-archive pruner (age/count, injected clock), the
 * database-side occurrence pruner, and the worker-log rotation cap.
 */
final class RetentionTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private function seedArchive(): string
    {
        $dir = $this->path('retention-processed');
        mkdir($dir, 0775, true);
        foreach (['old-a' => 40, 'old-b' => 35, 'recent-a' => 5, 'recent-b' => 1] as $name => $ageDays) {
            $path = $dir . '/' . $name . '.log';
            file_put_contents($path, str_repeat('x', 1024));
            touch($path, self::NOW - $ageDays * 86_400);
        }
        return $dir;
    }

    public function testProcessedRetentionByAge(): void
    {
        $result = (new ProcessedRetentionService($this->seedArchive(), 30, 0, self::NOW))->prune();
        self::assertSame(2, $result['deleted']);
        self::assertSame(2, $result['remaining']);
        self::assertSame(2048, $result['freed_bytes']);
    }

    public function testProcessedRetentionByCount(): void
    {
        $result = (new ProcessedRetentionService($this->seedArchive(), 0, 1, self::NOW))->prune();
        self::assertSame(3, $result['deleted']);
        self::assertSame(1, $result['remaining']);
    }

    public function testProcessedRetentionDisabledDeletesNothing(): void
    {
        $result = (new ProcessedRetentionService($this->seedArchive(), 0, 0, self::NOW))->prune();
        self::assertFalse($result['enabled']);
        self::assertSame(0, $result['deleted']);
    }

    /** @return array{0:PDO,1:int} pdo + the seeded group id (with occurrences) */
    private function seedOccurrences(array $ages): array
    {
        $pdo = $this->makeDatabase()->pdo;
        $pdo->exec("INSERT INTO source_files(path,size,modified_at,log_type,channel) VALUES('retention-fixture.log',0,0,'laravel','retfix')");
        $source = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO error_groups(fingerprint,severity,environment,title,count,first_seen,last_seen,origin,status,log_type)
             VALUES('ret-fixture','ERROR','production','Retention fixture error',?,?,?,'ingested','open','laravel')"
        )->execute([count($ages), gmdate('Y-m-d H:i:s', time() - 200 * 86400), gmdate('Y-m-d H:i:s')]);
        $group = (int) $pdo->lastInsertId();
        $insert = $pdo->prepare(
            'INSERT INTO occurrences(group_id,source_file_id,exact_fingerprint,occurred_at,severity,byte_start,byte_end) VALUES(?,?,?,?,?,?,?)'
        );
        foreach ($ages as $i => $ageDays) {
            $when = gmdate('Y-m-d H:i:s', time() - $ageDays * 86400);
            $insert->execute([$group, $source, "r{$i}", $when, 'ERROR', $i, $i + 1]);
        }
        return [$pdo, $group];
    }

    private function countOccurrences(PDO $pdo, int $group): int
    {
        return (int) $pdo->query("SELECT COUNT(*) FROM occurrences WHERE group_id={$group}")->fetchColumn();
    }

    public function testOccurrenceRetentionDisabledDeletesNothing(): void
    {
        [$pdo, $group] = $this->seedOccurrences([200, 200, 0]);
        $result = (new OccurrenceRetentionService($pdo, 0, 0, 0))->prune();
        self::assertFalse($result['enabled']);
        self::assertSame(3, $this->countOccurrences($pdo, $group));
    }

    public function testOccurrenceRetentionByAgeKeepsRecent(): void
    {
        [$pdo, $group] = $this->seedOccurrences([200, 200, 0]);
        $result = (new OccurrenceRetentionService($pdo, 90, null, 0))->prune();
        self::assertTrue($result['enabled']);
        self::assertGreaterThanOrEqual(2, $result['deleted']);
        self::assertSame(1, $this->countOccurrences($pdo, $group));
    }

    public function testOccurrenceRetentionByCountKeepsNewest(): void
    {
        [$pdo, $group] = $this->seedOccurrences([0, 0, 0]);
        (new OccurrenceRetentionService($pdo, null, 1, 0))->prune();
        self::assertSame(1, $this->countOccurrences($pdo, $group));
    }

    public function testWorkerLogRotation(): void
    {
        $log = $this->path('rotate.log');
        file_put_contents($log, str_repeat('x', 2048));
        self::assertFalse(BackgroundSyncService::rotateLogFile($log, 5000), 'Under the cap: no rotation.');
        self::assertTrue(is_file($log));
        self::assertTrue(BackgroundSyncService::rotateLogFile($log, 1000), 'Over the cap: rotate.');
        self::assertTrue(is_file($log . '.1'));
        self::assertFalse(is_file($log));
    }
}
