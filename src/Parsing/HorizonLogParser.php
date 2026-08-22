<?php
declare(strict_types=1);

namespace LogLens\Parsing;

use Generator;
use LogLens\Contracts\LogParserInterface;
use LogLens\Domain\LogEvent;

/**
 * Horizon / `queue:work` console output — the framing those commands actually
 * print, as opposed to the Laravel `[time] env.LEVEL:` framing a Horizon log
 * only has when it is written through the logging stack.
 *
 * Two shapes, because both are in the wild and a worker's log file often
 * contains a run of each across a framework upgrade:
 *
 *   [2026-08-20 03:00:02][7f3a1c2e] Failed:     App\Jobs\SyncCustomer
 *   2026-08-20 03:00:02 App\Jobs\SyncCustomer ................. 1s FAIL
 *
 * Neither one carries a log level, so the verb is the level: `Failed`/`FAIL`
 * is the ERROR, everything else is progress. Without that mapping a Horizon
 * log ingests to nothing under the default ERROR/WARNING severities — which
 * is precisely what happened before this parser existed, since
 * {@see LaravelLogParser} claimed every horizon-named file on its name alone
 * and then matched none of its lines.
 *
 * Lines that are neither shape (`Stack trace:`, `#0 /app/…`, an exception
 * message) append to the event above them, so a failure keeps the trace that
 * explains it — the same continuation rule the other parsers use.
 */
final class HorizonLogParser extends AbstractStreamingParser implements LogParserInterface
{
    /** `[2026-08-20 03:00:02][7f3a1c2e] Failed:     App\Jobs\SyncCustomer` */
    private const BRACKETED = '/^\[(?<time>\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}[^\]]*)\](?:\[(?<id>[^\]]*)\])?\s+(?<body>.+)$/';

    /** `2026-08-20 03:00:02 App\Jobs\SyncCustomer ......... 1s FAIL` */
    private const PROGRESS = '/^(?<time>\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:[.,]\d+)?(?:Z|[+-]\d{2}:?\d{2})?)\s+(?<job>\S.*?)\s*\.{3,}\s*(?<detail>.*)$/';

    /** The verbs that mean the job did not make it. */
    private const FAILURE = '/(?:^failed\b|\bFAIL(?:ED)?$)/i';

    public function type(): string
    {
        return 'horizon';
    }

    /**
     * A horizon-named file whose first parsable line is one of the two console
     * shapes. The name alone is not enough: a `horizon.log` written through the
     * logging stack is a Laravel-framed file and belongs to
     * {@see LaravelLogParser}, which is tried first.
     */
    public function supports(string $path, string $sample): bool
    {
        if (!str_contains(strtolower(basename($path)), 'horizon')) {
            return false;
        }
        foreach (preg_split('/\r?\n/', $sample) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            return preg_match(self::BRACKETED, $line) === 1 || preg_match(self::PROGRESS, $line) === 1;
        }
        return false;
    }

    public function parse(string $path, int $offset = 0): Generator
    {
        $handle = $this->open($path, $offset);
        $channel = basename($path);
        $event = null;
        try {
            while (($start = ftell($handle)) !== false && ($line = fgets($handle)) !== false) {
                $end = (int) ftell($handle);
                $text = rtrim($line, "\r\n");
                if ($text === '') {
                    continue;
                }
                $header = $this->header($text);
                if ($header === null) {
                    if ($event !== null) {
                        $event['byteEnd'] = $end;
                        $event['body'] = $this->append($event['body'], trim($text));
                    }
                    continue;
                }
                if ($event !== null) {
                    yield new LogEvent(...$event);
                }
                $event = [
                    'byteStart' => (int) $start,
                    'byteEnd' => $end,
                    'occurredAt' => $this->normalizeTime($header['time']),
                    'severity' => $header['severity'],
                    'environment' => 'horizon',
                    'body' => $header['body'],
                    'logType' => $this->type(),
                    'channel' => $channel,
                ];
            }
            if ($event !== null) {
                yield new LogEvent(...$event);
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return array{time:string,severity:string,body:string}|null */
    private function header(string $text): ?array
    {
        if (preg_match(self::BRACKETED, $text, $match) === 1) {
            $body = trim($match['body']);
            return [
                'time' => $match['time'],
                'severity' => $this->severityFor($body),
                'body' => $body,
            ];
        }
        if (preg_match(self::PROGRESS, $text, $match) === 1) {
            $detail = trim($match['detail']);
            return [
                'time' => $match['time'],
                'severity' => $this->severityFor($detail),
                'body' => $detail === '' ? $match['job'] : $match['job'] . ' — ' . $detail,
            ];
        }
        return null;
    }

    /**
     * A failure verb, or an exception class where the verb would be — the line
     * after a `Failed:` in the bracketed format is the exception itself, and
     * that is the line carrying the message worth grouping on.
     */
    private function severityFor(string $body): string
    {
        if (preg_match(self::FAILURE, $body) === 1) {
            return 'ERROR';
        }
        return preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*(?:Exception|Error)\b/', $body) === 1 ? 'ERROR' : 'INFO';
    }
}
