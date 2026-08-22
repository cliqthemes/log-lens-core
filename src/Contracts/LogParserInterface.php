<?php
declare(strict_types=1);

namespace LogLens\Contracts;

use Generator;

interface LogParserInterface
{
    public function type(): string;

    public function supports(string $path, string $sample): bool;

    /** @return Generator<\LogLens\Domain\LogEvent> */
    public function parse(string $path, int $offset = 0): Generator;
}
