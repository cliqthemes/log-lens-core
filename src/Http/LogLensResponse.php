<?php
declare(strict_types=1);

namespace LogLens\Http;

/**
 * Transport-agnostic result of the core dispatcher: a JSON payload plus status.
 *
 * The standalone front controller emits it with JsonResponse; a host framework
 * adapter converts it into that framework's own response object.
 */
final class LogLensResponse
{
    public function __construct(
        public readonly mixed $data,
        public readonly int $status = 200,
    ) {
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self($data, $status);
    }
}
