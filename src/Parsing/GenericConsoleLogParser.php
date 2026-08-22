<?php
declare(strict_types=1);

namespace LogLens\Parsing;

use Generator;
use LogLens\Contracts\LogParserInterface;
use LogLens\Domain\LogEvent;

final class GenericConsoleLogParser extends AbstractStreamingParser implements LogParserInterface
{
    private const PATTERNS = [
        '/^\[(?<time>[^\]]+)\]\s+(?<level>EMERGENCY|ALERT|CRITICAL|ERROR|ERR|WARNING|WARN|NOTICE|INFO|DEBUG|TRACE|FATAL)[:\s-]+(?<body>.*)$/i',
        '/^(?<time>\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:[.,]\d+)?(?:Z|[+-]\d{2}:?\d{2})?)\s+(?:\[(?<level1>[A-Z]+)\]|(?<level2>[A-Z]+))[:\s-]+(?<body>.*)$/',
        '/^\[(?<time>[^\]]+)\]\s+(?<body>.*)$/',
    ];

    public function type(): string
    {
        return 'console';
    }

    public function supports(string $path, string $sample): bool
    {
        return true;
    }

    /**
     * The level captured by whichever alternative of the pattern matched.
     *
     * PCRE fills the named groups a pattern *could* have matched but did not
     * with an empty string, not null — so `$match['level1'] ?? $match['level2']`
     * always resolves to `level1`, and the second pattern's bare-level branch
     * (`2026-08-20T10:15:30Z ERROR redis: …`, the shape every containerized
     * worker and supervisor log has) silently graded every line INFO. With the
     * default ERROR/WARNING severities that meant a console log ingested to
     * exactly zero events, with no error to explain it. Coalesce on emptiness,
     * not on null.
     *
     * @param array<string,string> $match
     */
    private function level(array $match): string
    {
        foreach (['level', 'level1', 'level2'] as $group) {
            if (($match[$group] ?? '') !== '') {
                return $match[$group];
            }
        }
        return 'INFO';
    }

    public function parse(string $path, int $offset = 0): Generator
    {
        $handle = $this->open($path, $offset);
        $channel = basename($path);
        $event = null;
        try {
            while (($start = ftell($handle)) !== false && ($line = fgets($handle)) !== false) {
                $end = (int) ftell($handle);
                $text = trim($line);
                if ($text === '') {
                    continue;
                }
                $header = null;
                foreach (self::PATTERNS as $pattern) {
                    if (preg_match($pattern, $text, $match) !== 1) {
                        continue;
                    }
                    $header = [
                        'time' => $this->normalizeTime($match['time']),
                        'severity' => $this->normalizeSeverity($this->level($match)),
                        'body' => trim($match['body']),
                    ];
                    break;
                }
                if ($header !== null) {
                    if ($event !== null) {
                        yield new LogEvent(...$event);
                    }
                    $event = [
                        'byteStart' => (int) $start,
                        'byteEnd' => $end,
                        'occurredAt' => $header['time'],
                        'severity' => $header['severity'],
                        'environment' => 'console',
                        'body' => $header['body'],
                        'logType' => $this->type(),
                        'channel' => $channel,
                    ];
                    continue;
                }
                if ($event !== null) {
                    $event['byteEnd'] = $end;
                    $event['body'] = $this->append($event['body'], $text);
                    continue;
                }
                yield new LogEvent(
                    (int) $start,
                    $end,
                    // gmdate, not date: occurred_at is a UTC column (see
                    // AbstractStreamingParser::normalizeTime).
                    gmdate('Y-m-d H:i:s', filemtime($path) ?: time()),
                    'INFO',
                    'console',
                    $text,
                    $this->type(),
                    $channel,
                );
            }
            if ($event !== null) {
                yield new LogEvent(...$event);
            }
        } finally {
            fclose($handle);
        }
    }
}
