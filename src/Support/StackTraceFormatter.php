<?php
declare(strict_types=1);

namespace LogLens\Support;

/**
 * Pure stack-trace presentation transforms — extracted from `ApiController`
 * which had no business owning a formatting concern
 * unrelated to routing/dispatch.
 */
final class StackTraceFormatter
{
    /** Drop vendor frames from a stack trace, for the `skip_vendor` request flag. */
    public static function withoutVendor(?string $stack): ?string
    {
        if ($stack === null || $stack === '') {
            return $stack;
        }
        return implode("\n", array_values(array_filter(
            explode("\n", $stack),
            static fn (string $line): bool => !str_contains($line, '/vendor/')
        )));
    }
}
