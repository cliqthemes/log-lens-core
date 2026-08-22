<?php
declare(strict_types=1);

namespace LogLens\Domain;

final readonly class EventAnalysis
{
    public function __construct(
        public string $title,
        public string $context,
        public string $exceptionClass,
        public string $sourceFrame,
        public string $stack,
    ) {
    }
}
