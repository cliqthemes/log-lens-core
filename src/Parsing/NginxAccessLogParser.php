<?php
declare(strict_types=1);

namespace LogLens\Parsing;

use Generator;
use LogLens\Contracts\LogParserInterface;
use LogLens\Domain\LogEvent;

final class NginxAccessLogParser extends AbstractStreamingParser implements LogParserInterface
{
    private const COMBINED = '/^(?<ip>\S+)\s+\S+\s+(?<user>\S+)\s+\[(?<time>[^\]]+)\]\s+"(?<method>[A-Z]+)\s+(?<target>\S+)(?:\s+HTTP\/[^"]+)?"\s+(?<status>\d{3})\s+(?<bytes>\S+)(?:\s+"(?<referer>[^"]*)"\s+"(?<agent>[^"]*)")?/';

    public function type(): string
    {
        return 'nginx_access';
    }

    public function supports(string $path, string $sample): bool
    {
        $name = strtolower(basename($path));
        return (str_contains($name, 'access') || str_contains($name, 'nginx'))
            && preg_match(self::COMBINED, strtok($sample, "\r\n") ?: '') === 1;
    }

    public function parse(string $path, int $offset = 0): Generator
    {
        $handle = $this->open($path, $offset);
        $channel = basename($path);
        try {
            while (($start = ftell($handle)) !== false && ($line = fgets($handle)) !== false) {
                $end = (int) ftell($handle);
                $text = rtrim($line, "\r\n");
                if (preg_match(self::COMBINED, $text, $match) !== 1) {
                    continue;
                }
                $status = (int) $match['status'];
                $severity = $status >= 500 ? 'ERROR' : ($status >= 400 ? 'WARNING' : 'INFO');
                $target = parse_url($match['target'], PHP_URL_PATH) ?: $match['target'];
                $context = [
                    'ip' => $match['ip'],
                    'method' => $match['method'],
                    'target' => $match['target'],
                    'path' => $target,
                    'status' => $status,
                    'bytes' => $match['bytes'],
                    'referer' => $match['referer'] ?? '',
                    'user_agent' => $match['agent'] ?? '',
                ];
                yield new LogEvent(
                    (int) $start,
                    $end,
                    $this->normalizeTime($match['time']),
                    $severity,
                    'nginx',
                    sprintf('HTTP %d %s %s', $status, $match['method'], $target),
                    $this->type(),
                    $channel,
                    $context,
                );
            }
        } finally {
            fclose($handle);
        }
    }
}
