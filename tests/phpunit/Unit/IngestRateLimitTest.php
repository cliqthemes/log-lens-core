<?php
declare(strict_types=1);

namespace LogLens\Tests\Unit;

use LogLens\Config;
use LogLens\Plugins\Ingest\IngestService;
use LogLens\Tests\TestCase;
use PDO;

/**
 * M-12: the ingest limiter is a sliding-window counter with an optional
 * per-IP sub-limit. A long window is configured so a test run cannot straddle
 * a bucket boundary and see the previous bucket weighted in.
 */
final class IngestRateLimitTest extends TestCase
{
    private function configure(int $max, int $perIp): void
    {
        Config::load(['ingest' => [
            'rate_max_events' => $max,
            'rate_max_events_per_ip' => $perIp,
            'rate_window_seconds' => 3600,
            'rate_ip_table_max' => 1000,
        ]]);
    }

    /** @return array<int,array<string,mixed>> */
    private function events(int $n): array
    {
        $events = [];
        for ($i = 0; $i < $n; $i++) {
            $events[] = ['message' => 'event ' . $i, 'severity' => 'ERROR'];
        }
        return $events;
    }

    private function ingest(PDO $pdo, int $count, ?string $ip = null): array
    {
        return (new IngestService($pdo))->ingest(['events' => $this->events($count)], $ip);
    }

    public function testGlobalLimitCapsAcceptedEvents(): void
    {
        $this->configure(max: 5, perIp: 0);
        $pdo = $this->makeDatabase()->pdo;

        $first = $this->ingest($pdo, 4);
        self::assertSame(4, $first['accepted']);
        self::assertSame(0, $first['dropped']);

        // 4 already used this window; only 1 of the next 3 fits.
        $second = $this->ingest($pdo, 3);
        self::assertSame(1, $second['accepted']);
        self::assertSame(2, $second['dropped']);

        // Window exhausted: the whole batch is rejected (429 upstream).
        $third = $this->ingest($pdo, 2);
        self::assertSame(0, $third['accepted']);
        self::assertTrue($third['rate_limited']);
        self::assertGreaterThan(0, $third['retry_after']);
    }

    public function testPerIpLimitIsIndependentPerAddress(): void
    {
        $this->configure(max: 1000, perIp: 3);
        $pdo = $this->makeDatabase()->pdo;

        $a = $this->ingest($pdo, 5, '10.0.0.1');
        self::assertSame(3, $a['accepted'], 'First IP capped at its per-IP limit.');

        // A different IP has its own budget even though the global limit is far
        // from reached.
        $b = $this->ingest($pdo, 5, '10.0.0.2');
        self::assertSame(3, $b['accepted'], 'Second IP gets its own per-IP budget.');

        // The first IP is now exhausted for the window.
        $aAgain = $this->ingest($pdo, 2, '10.0.0.1');
        self::assertSame(0, $aAgain['accepted']);
    }

    public function testPerIpLimitDisabledByDefault(): void
    {
        $this->configure(max: 1000, perIp: 0);
        $pdo = $this->makeDatabase()->pdo;

        // With the per-IP limit off, one IP can use the full global budget.
        $result = $this->ingest($pdo, 50, '10.0.0.1');
        self::assertSame(50, $result['accepted']);
    }

    public function testPerIpTableStaysWithinItsCap(): void
    {
        Config::load(['ingest' => [
            'rate_max_events' => 100000,
            'rate_max_events_per_ip' => 5,
            'rate_window_seconds' => 3600,
            'rate_ip_table_max' => 10,
        ]]);
        $pdo = $this->makeDatabase()->pdo;

        for ($i = 0; $i < 25; $i++) {
            $this->ingest($pdo, 1, '10.0.1.' . $i);
        }

        $stored = $pdo->query("SELECT value FROM app_settings WHERE key='http_ingest.rate.ip'")->fetchColumn();
        $table = json_decode((string) $stored, true);
        self::assertIsArray($table);
        self::assertLessThanOrEqual(10, count($table), 'Per-IP table must not exceed its configured cap.');
    }
}
