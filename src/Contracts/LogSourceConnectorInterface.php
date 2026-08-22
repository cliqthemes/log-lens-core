<?php
declare(strict_types=1);

namespace LogLens\Contracts;

use LogLens\Domain\RemoteLogFile;

interface LogSourceConnectorInterface
{
    /** @return list<RemoteLogFile> */
    public function discover(): array;

    public function readRange(RemoteLogFile $file, int $offset, int $length): string;

    public function prefixHash(RemoteLogFile $file, int $length): string;

    /**
     * @param list<array{file:RemoteLogFile,length:int}> $requests
     * @return list<string>
     */
    public function prefixHashes(array $requests): array;

    /** @return array{ok:bool,message:string} */
    public function test(): array;
}
