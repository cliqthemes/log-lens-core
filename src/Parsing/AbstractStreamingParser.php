<?php
declare(strict_types=1);

namespace LogLens\Parsing;

use Generator;
use LogLens\Config;
use RuntimeException;

abstract class AbstractStreamingParser
{
    protected const CAPTURE_LIMIT = 4_194_304;

    protected function captureLimit(): int
    {
        return Config::int('ingestion.capture_limit', self::CAPTURE_LIMIT);
    }

    /** @return resource */
    protected function open(string $path, int $offset)
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open log file: {$path}");
        }
        if ($offset > 0 && fseek($handle, $offset) !== 0) {
            fclose($handle);
            throw new RuntimeException("Cannot seek to byte {$offset}: {$path}");
        }
        return $handle;
    }

    protected function append(string $body, string $line): string
    {
        $limit = $this->captureLimit();
        if (strlen($body) >= $limit) {
            return $body;
        }
        return $body . "\n" . substr($line, 0, $limit - strlen($body));
    }

    protected function normalizeSeverity(string $severity): string
    {
        $severity = strtoupper(trim($severity));
        return match ($severity) {
            'WARN' => 'WARNING',
            'ERR', 'FATAL' => 'ERROR',
            'TRACE' => 'DEBUG',
            default => $severity ?: 'INFO',
        };
    }

    /**
     * Normalize a parsed timestamp to the UTC wall-clock string every other
     * writer in the schema stores (`gmdate()`, or a driver-normalized
     * CURRENT_TIMESTAMP — see MysqlDriver's init command).
     *
     * The UTC default timezone is doing real work in both directions:
     *
     *   - A bare timestamp ("2026-08-22 10:15:30", Laravel/console logs) carries
     *     no offset, so there is nothing to convert with — it is read as UTC and
     *     formatted straight back out, unchanged.
     *   - An offset-bearing timestamp ("22/Aug/2026:10:15:30 +1000", which the
     *     nginx combined format *always* produces) has its own offset override
     *     the default, and setTimezone() then converts it to the real UTC
     *     instant. Formatting without that conversion silently discarded the
     *     offset and stored local wall-clock time — up to ±14h of skew against
     *     every other row, which broke occurred_day bucketing, cross-origin
     *     ordering, and the alert/analytics time windows.
     */
    protected function normalizeTime(string $value): string
    {
        $utc = new \DateTimeZone('UTC');
        try {
            return (new \DateTimeImmutable($value, $utc))->setTimezone($utc)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return gmdate('Y-m-d H:i:s');
        }
    }
}
