<?php
declare(strict_types=1);

namespace LogLens\Support;

use LogLens\Config;

/**
 * Best-effort validation of the loaded configuration so a typo does not just
 * silently fall back to a default and mask a misconfiguration (Low nit).
 *
 * It reports two classes of problem: unknown top-level sections (a misspelled
 * `retention` / `ingest` etc.) and known scalar keys whose value is the wrong
 * type. It never throws or mutates config — the getters still coerce/default at
 * runtime; this only surfaces problems (e.g. via the health endpoint).
 */
final class ConfigValidator
{
    /** Known top-level sections; anything else is a likely typo. */
    private const SECTIONS = [
        'LOG_LENS_URL', 'auth', 'ingestion', 'pagination', 'ingest', 'sync',
        'ssh', 'database', 'security', 'linear', 'retention', 'plugins', 'parsing',
    ];

    /**
     * Curated scalar keys and their expected type: 'int' | 'bool' | 'string'.
     * (Not exhaustive — the highest-value knobs where a wrong type is a real
     * mistake.)
     *
     * @var array<string,string>
     */
    private const TYPES = [
        'auth.token' => 'string',
        'pagination.default_limit' => 'int',
        'pagination.max_limit' => 'int',
        'ingest.rate_max_events' => 'int',
        'ingest.rate_window_seconds' => 'int',
        'ingest.rate_max_events_per_ip' => 'int',
        'ingest.trust_forwarded_for' => 'bool',
        'sync.chunk_size' => 'int',
        'sync.stale_run_timeout_seconds' => 'int',
        'sync.worker_log_max_bytes' => 'int',
        'database.busy_timeout' => 'int',
        'security.headers_enabled' => 'bool',
        'security.content_security_policy' => 'string',
        'linear.timeout' => 'int',
        'retention.processed_max_age_days' => 'int',
        'retention.occurrences_max_age_days' => 'int',
        'retention.max_occurrences_per_group' => 'int',
    ];

    /**
     * @return list<string> Human-readable warnings; empty when the config looks
     *   well-formed.
     */
    public static function validate(): array
    {
        $warnings = [];

        foreach (array_keys(Config::all()) as $section) {
            if (!in_array((string) $section, self::SECTIONS, true)) {
                $warnings[] = "Unknown top-level config key '{$section}' — check for a typo; it will be ignored.";
            }
        }

        foreach (self::TYPES as $path => $type) {
            $value = Config::get($path);
            if ($value === null) {
                continue; // absent → the getter's default applies; not a problem.
            }
            if (!self::matchesType($value, $type)) {
                $actual = get_debug_type($value);
                $warnings[] = "Config '{$path}' should be {$type}, got {$actual}; the default will be used.";
            }
        }

        return $warnings;
    }

    private static function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'int' => is_int($value) || (is_string($value) && $value !== '' && ctype_digit(ltrim($value, '-'))),
            'bool' => is_bool($value) || (is_int($value) && ($value === 0 || $value === 1)),
            'string' => is_string($value),
            default => true,
        };
    }
}
