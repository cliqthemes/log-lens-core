<?php
declare(strict_types=1);

namespace LogLens;

/**
 * Central access to the settings in the project-root config.php.
 *
 * Values are loaded once and cached. Every getter takes an explicit default, so
 * the application keeps working against an older or partial config.php that does
 * not define a given key. Tests inject a config array with Config::load().
 *
 * Not safe under a persistent-worker runtime (Octane/Swoole/RoadRunner) as-is:
 * this cache is process-global, so the first request's
 * config would pin every subsequent request the worker serves, across
 * applications. Fine under the request-per-process model this app assumes
 * (PHP-FPM, CLI, standalone dev server) — call {@see reset()} at the start of
 * each request if that deployment model is ever supported.
 */
final class Config
{
    /** Longest configured PCRE accepted; well past any plausible file-name regex. */
    private const PATTERN_MAX_LENGTH = 512;

    /** @var array<string,mixed>|null */
    private static ?array $values = null;

    /** @var array<string,string> Validated patterns by path+value; see {@see pattern()}. */
    private static array $patternCache = [];

    /** @param array<string,mixed>|null $override */
    public static function load(?array $override = null): void
    {
        if ($override !== null) {
            self::$values = $override;
            return;
        }
        $path = dirname(__DIR__) . '/config.php';
        $loaded = is_file($path) ? require $path : [];
        self::$values = is_array($loaded) ? $loaded : [];
    }

    public static function reset(): void
    {
        self::$values = null;
        self::$patternCache = [];
    }

    public static function get(string $path, mixed $default = null): mixed
    {
        if (self::$values === null) {
            self::load();
        }
        $value = self::$values;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    /** @return array<string,mixed> The full loaded config (loads on first access). */
    public static function all(): array
    {
        if (self::$values === null) {
            self::load();
        }
        return self::$values ?? [];
    }

    public static function string(string $path, string $default = ''): string
    {
        $value = self::get($path, $default);
        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public static function int(string $path, int $default, int $min = 1): int
    {
        $value = self::get($path, $default);
        if (!is_numeric($value)) {
            return $default;
        }
        $value = (int) $value;
        return $value >= $min ? $value : $default;
    }

    public static function bool(string $path, bool $default): bool
    {
        $value = self::get($path, $default);
        if (is_bool($value)) {
            return $value;
        }
        return is_scalar($value)
            ? filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default
            : $default;
    }

    /** @param list<string> $paths @return array<string,mixed> */
    public static function subset(array $paths): array
    {
        if (self::$values === null) {
            self::load();
        }
        $result = [];
        foreach ($paths as $path) {
            $segments = explode('.', $path);
            $source = self::$values;
            foreach ($segments as $segment) {
                if (!is_array($source) || !array_key_exists($segment, $source)) {
                    continue 2;
                }
                $source = $source[$segment];
            }
            $target =& $result;
            foreach ($segments as $segment) {
                if (!isset($target[$segment]) || !is_array($target[$segment])) {
                    $target[$segment] = [];
                }
                $target =& $target[$segment];
            }
            $target = $source;
            unset($target);
        }
        return $result;
    }

    /**
     * A configured PCRE (with delimiters), validated. Returns $default when the
     * configured value is missing, does not compile, or blows the backtrack
     * limit on a probe subject.
     *
     * The compile check alone is not enough. A pattern that compiles perfectly
     * can still take exponential time on the wrong input — the classic shape is
     * a nested or adjacent quantifier over an overlapping character class,
     * `/(\w+\.)+log$/` being the kind of thing an operator writes to widen the
     * log-file match. Applied to every file name in a directory of rotated logs,
     * that is a hung import rather than a wrong answer, and the operator gets no
     * clue why. So the value is probed against a long uniform subject: PCRE's
     * backtrack limit turns the pathological case into a `false` return, which
     * is a signal we can act on here, once, instead of at every call site.
     *
     * The result is memoized because the probe costs two `preg_match` calls and
     * the callers ask per import run.
     */
    public static function pattern(string $path, string $default): string
    {
        $pattern = self::string($path, $default);
        if ($pattern === $default) {
            return $default;
        }
        $cacheKey = $path . "\0" . $pattern;
        if (isset(self::$patternCache[$cacheKey])) {
            return self::$patternCache[$cacheKey];
        }
        return self::$patternCache[$cacheKey] = self::usablePattern($pattern, $path) ? $pattern : $default;
    }

    private static function usablePattern(string $pattern, string $path): bool
    {
        if (strlen($pattern) > self::PATTERN_MAX_LENGTH) {
            self::rejectPattern($path, 'longer than ' . self::PATTERN_MAX_LENGTH . ' bytes');
            return false;
        }
        if (@preg_match($pattern, '') === false) {
            self::rejectPattern($path, 'not a valid PCRE (delimiters required)');
            return false;
        }
        // A long run of one character followed by a non-match is what makes an
        // ambiguous quantifier backtrack; a well-formed file-name pattern runs
        // over this in microseconds.
        $probe = str_repeat('a', 256) . '.';
        if (@preg_match($pattern, $probe) === false && preg_last_error() !== PREG_NO_ERROR) {
            self::rejectPattern($path, 'exceeded PCRE limits (' . preg_last_error_msg() . ')');
            return false;
        }
        return true;
    }

    private static function rejectPattern(string $path, string $reason): void
    {
        // Silently falling back to the default leaves the operator wondering why
        // their pattern has no effect, so say so.
        error_log("[log-lens] Ignoring config '{$path}': {$reason}. Using the built-in default.");
    }

    /**
     * @param list<string> $default
     * @return list<string>
     */
    public static function stringList(string $path, array $default): array
    {
        $value = self::get($path, $default);
        if (!is_array($value)) {
            return $default;
        }
        $list = array_values(array_filter(
            array_map(static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '', $value),
            static fn (string $item): bool => $item !== '',
        ));
        return $list === [] ? $default : $list;
    }
}
