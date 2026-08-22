<?php
declare(strict_types=1);

namespace LogLens\Tests\Unit;

use LogLens\Domain\IssueOrigin;
use LogLens\Repositories\IssueRepository;
use LogLens\Tests\TestCase;
use PDO;

/**
 * H-6: the "which origins are backed by occurrences" rule lives only in
 * IssueOrigin. These tests pin both the rule itself and the behaviour that
 * previously regressed — refreshAggregates wiping occurrence-less manual /
 * Linear issues.
 */
final class IssueOriginTest extends TestCase
{
    public function testOnlyIngestedIsOccurrenceBacked(): void
    {
        self::assertTrue(IssueOrigin::hasOccurrences(IssueOrigin::INGESTED));
        self::assertFalse(IssueOrigin::hasOccurrences(IssueOrigin::MANUAL));
        self::assertFalse(IssueOrigin::hasOccurrences(IssueOrigin::LINEAR));
        self::assertFalse(IssueOrigin::hasOccurrences('anything-else'));
    }

    public function testOccurrenceBackedSqlSelectsIngested(): void
    {
        $sql = IssueOrigin::occurrenceBackedSql('origin');
        self::assertStringContainsString('origin', $sql);
        self::assertStringContainsString("'ingested'", $sql);
        self::assertStringNotContainsString("'manual'", $sql);
        self::assertStringNotContainsString("'linear'", $sql);
    }

    public function testRefreshAggregatesPrunesEmptyIngestedButKeepsManualAndLinear(): void
    {
        $pdo = $this->makeDatabase()->pdo;
        $insert = static function (PDO $pdo, string $origin, string $fingerprint): void {
            $pdo->prepare(
                "INSERT INTO error_groups(fingerprint,severity,environment,title,count,first_seen,last_seen,
                    sample_message,created_at,status,log_type,channel,origin,kind)
                 VALUES(?,?,?,?,0,?,?,?,?,?,?,?,?,?)"
            )->execute([
                $fingerprint, 'ERROR', 'production', 'Title ' . $origin, '2026-01-01 00:00:00',
                '2026-01-01 00:00:00', 'msg', '2026-01-01 00:00:00', 'open',
                $origin, $origin, $origin, 'issue',
            ]);
        };
        // Three occurrence-less groups, one per origin.
        $insert($pdo, IssueOrigin::INGESTED, 'fp-ingested');
        $insert($pdo, IssueOrigin::MANUAL, 'fp-manual');
        $insert($pdo, IssueOrigin::LINEAR, 'fp-linear');

        (new IssueRepository($pdo))->refreshAggregates();

        $remaining = $pdo
            ->query('SELECT origin FROM error_groups ORDER BY origin')
            ->fetchAll(PDO::FETCH_COLUMN);
        // The empty ingested group is pruned; manual and linear survive.
        self::assertSame([IssueOrigin::LINEAR, IssueOrigin::MANUAL], $remaining);
    }
}
