<?php
declare(strict_types=1);

namespace LogLens\Domain;

final readonly class RemoteLogFile
{
    public function __construct(
        public string $path,
        public string $identity,
        public int $size,
        public int $modifiedAt,
        public string $channel,
    ) {
    }
}
