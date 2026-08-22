<?php
declare(strict_types=1);

namespace LogLens\Domain;

final readonly class LogEvent
{
    public function __construct(
        public int $byteStart,
        public int $byteEnd,
        public string $occurredAt,
        public string $severity,
        public string $environment,
        public string $body,
        public string $logType,
        public string $channel,
        public array $context = [],
        public ?string $release = null,
    ) {
    }
}
