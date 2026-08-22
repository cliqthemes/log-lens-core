<?php
declare(strict_types=1);

namespace LogLens\Tests\Unit;

use LogLens\Parsing\GenericConsoleLogParser;
use LogLens\Parsing\LaravelLogParser;
use LogLens\Parsing\NginxAccessLogParser;
use LogLens\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `occurrences.occurred_at` is a UTC column: every other writer uses gmdate()
 * or a driver-normalized CURRENT_TIMESTAMP. The parsers are the one path that
 * takes a timestamp from untrusted text, so this pins the two rules:
 *
 *   - a timestamp carrying an offset is CONVERTED to UTC, not truncated to its
 *     local wall clock (the nginx combined format always carries one);
 *   - a bare timestamp with no offset is left exactly as written, because there
 *     is no information to convert it with.
 *
 * Regression: normalizeTime() used to format without setTimezone(), silently
 * discarding the offset and storing local wall-clock time — up to ±14h of skew
 * against every other row in the same column.
 */
final class TimestampNormalizationTest extends TestCase
{
    /** Each test runs under a deliberately non-UTC default timezone. */
    private string $originalTimezone = 'UTC';

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTimezone = date_default_timezone_get();
        // If the parsers leaned on the process timezone anywhere, this is the
        // setting that would expose it.
        date_default_timezone_set('Australia/Brisbane');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimezone);
        parent::tearDown();
    }

    /** @return list<array{0:string,1:string}> */
    public static function offsetCases(): array
    {
        return [
            'positive offset' => ['01/Jan/2026:10:15:30 +1000', '2026-01-01 00:15:30'],
            'negative offset' => ['01/Jan/2026:10:15:30 -0700', '2026-01-01 17:15:30'],
            'utc offset' => ['01/Jan/2026:10:15:30 +0000', '2026-01-01 10:15:30'],
            'offset across midnight' => ['01/Jan/2026:01:00:00 +1000', '2025-12-31 15:00:00'],
        ];
    }

    #[DataProvider('offsetCases')]
    public function testNginxOffsetsAreConvertedToUtc(string $stamp, string $expected): void
    {
        $path = $this->writeFile(
            'access.log',
            "10.0.0.1 - - [{$stamp}] \"GET /boom HTTP/1.1\" 500 21 \"-\" \"client/1.0\"\n",
        );
        $events = iterator_to_array((new NginxAccessLogParser())->parse($path));
        self::assertCount(1, $events);
        self::assertSame($expected, $events[0]->occurredAt);
    }

    public function testBareLaravelTimestampIsStoredVerbatim(): void
    {
        $path = $this->writeFile(
            'laravel.log',
            "[2026-01-01 10:15:30] production.ERROR: Boom\n",
        );
        $events = iterator_to_array((new LaravelLogParser())->parse($path));
        self::assertCount(1, $events);
        // No offset in the source means nothing to convert with: the wall clock
        // is preserved rather than shifted by the process timezone.
        self::assertSame('2026-01-01 10:15:30', $events[0]->occurredAt);
    }

    public function testConsoleIsoTimestampWithOffsetIsConvertedToUtc(): void
    {
        $path = $this->writeFile(
            'worker.log',
            "2026-01-01T10:15:30+10:00 ERROR Worker crashed\n",
        );
        $events = iterator_to_array((new GenericConsoleLogParser())->parse($path));
        self::assertCount(1, $events);
        self::assertSame('2026-01-01 00:15:30', $events[0]->occurredAt);
    }

    /**
     * An unparseable line falls back to "now", which must also be UTC — the old
     * date() fallback used the process timezone.
     */
    public function testUnparseableConsoleLineFallsBackToUtcNow(): void
    {
        $path = $this->writeFile('worker.log', "no timestamp here at all\n");
        $events = iterator_to_array((new GenericConsoleLogParser())->parse($path));
        self::assertNotSame([], $events);
        $occurredAt = $events[0]->occurredAt;
        $drift = abs(strtotime($occurredAt . ' UTC') - time());
        self::assertLessThan(
            120,
            $drift,
            "Fallback timestamp {$occurredAt} is {$drift}s from now in UTC — it is "
            . 'probably being written in the process timezone (' . date_default_timezone_get() . ').',
        );
    }
}
