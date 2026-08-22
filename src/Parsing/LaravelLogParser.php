<?php
declare(strict_types=1);

namespace LogLens\Parsing;

use Generator;
use LogLens\Contracts\LogParserInterface;
use LogLens\Domain\LogEvent;

final class LaravelLogParser extends AbstractStreamingParser implements LogParserInterface
{
    private const HEADER = '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+([^.\s]+)\.([A-Z]+):\s?(.*)$/';

    public function __construct(private readonly string $logType = 'laravel')
    {
    }

    public function type(): string
    {
        return $this->logType;
    }

    /**
     * A horizon-named file still has to *be* a Laravel-framed log.
     *
     * The name check alone claimed every `horizon*.log` unconditionally, and a
     * file that turned out to carry `queue:work`-style framing
     * (`[2026-08-20 03:00:01][7f3a1c2e] Failed: App\Jobs\…`) then parsed to
     * zero events — the header never matched a single line and no later parser
     * ever got the chance, so the import reported a file with nothing in it.
     * Requiring the header here lets {@see GenericConsoleLogParser} pick those
     * up instead, which is what it is last in the registry for.
     */
    public function supports(string $path, string $sample): bool
    {
        $name = strtolower(basename($path));
        $framed = preg_match(self::HEADER, strtok($sample, "\r\n") ?: '') === 1;
        return $this->logType === 'horizon'
            ? str_contains($name, 'horizon') && $framed
            : !str_contains($name, 'horizon') && $framed;
    }

    public function parse(string $path, int $offset = 0): Generator
    {
        $handle = $this->open($path, $offset);
        $event = null;
        $channel = basename($path);

        try {
            while (($start = ftell($handle)) !== false && ($line = fgets($handle)) !== false) {
                $end = (int) ftell($handle);
                $text = rtrim($line, "\r\n");
                if (preg_match(self::HEADER, $text, $match) === 1) {
                    if ($event !== null) {
                        yield new LogEvent(...$event);
                    }
                    $event = [
                        'byteStart' => (int) $start,
                        'byteEnd' => $end,
                        'occurredAt' => $match[1],
                        'severity' => $this->normalizeSeverity($match[3]),
                        'environment' => $match[2],
                        'body' => $match[4],
                        'logType' => $this->logType,
                        'channel' => $channel,
                    ];
                    continue;
                }
                if ($event !== null) {
                    $event['byteEnd'] = $end;
                    $event['body'] = $this->append($event['body'], $text);
                }
            }
            if ($event !== null) {
                yield new LogEvent(...$event);
            }
        } finally {
            fclose($handle);
        }
    }
}
