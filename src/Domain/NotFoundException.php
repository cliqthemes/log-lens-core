<?php
declare(strict_types=1);

namespace LogLens\Domain;

use RuntimeException;

/**
 * Thrown by a repository/service when a requested entity does not exist.
 *
 * Deliberately transport-agnostic (lives in `Domain`, not `Http`) — a
 * repository has no business knowing about HTTP status codes; that
 * translation is `ApiController`'s job. Replaces a plain `RuntimeException`
 * with a "not found" message, which `ApiController` used to detect via
 * `str_contains(strtolower($message), 'not found')` — a
 * string sniff that would misclassify any *other* runtime error whose
 * message happened to contain those two words as a 404. A type check can't
 * have that failure mode.
 */
final class NotFoundException extends RuntimeException
{
}
