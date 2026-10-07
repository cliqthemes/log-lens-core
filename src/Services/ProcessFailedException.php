<?php
declare(strict_types=1);

namespace LogLens\Services;

use RuntimeException;

/**
 * A connector subprocess exited non-zero. The message is the short,
 * response-safe summary; the full stderr rides along for the server-side
 * sync error log only and must never be returned over HTTP.
 */
final class ProcessFailedException extends RuntimeException
{
    public function __construct(string $message, int $exitCode, public readonly string $stderr)
    {
        parent::__construct($message, $exitCode);
    }
}
