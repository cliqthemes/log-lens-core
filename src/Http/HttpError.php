<?php
declare(strict_types=1);

namespace LogLens\Http;

use RuntimeException;

/**
 * A controller-level error carrying an explicit HTTP status (e.g. 405). Caught
 * by the dispatcher and turned into a LogLensResponse with that status.
 */
final class HttpError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
