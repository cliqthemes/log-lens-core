<?php
declare(strict_types=1);

namespace LogLens\Services\Sync;

use LogLens\Domain\RemoteLogFile;

/**
 * What a synchronization run is about to do, decided before it does any of it.
 *
 * The same plan serves two callers, which is why it is an object rather than
 * the bare array it used to be. `?api=connector-preview` renders {@see
 * toArray()} for the operator to confirm; the run itself then asks the plan
 * which files to touch and in what capacity. Deriving those two views from one
 * object is what makes the snapshot check meaningful — the operator confirms
 * exactly the plan that executes.
 *
 * Two file lists, not one, because they answer different questions. Actionable
 * files have bytes to fetch or a local tail to index, and they are what the
 * progress bar counts. Processed files also include the ones whose remote
 * identity moved without their content changing (a rename, a rotation) — those
 * still need their stream row reconciled, but showing them as work would make
 * a no-op run look busy.
 */
final class SyncPlan
{
    /** @var array<string,true> */
    private array $actionablePaths = [];

    /** @var array<string,true> */
    private array $processedPaths = [];

    /** @var array<string,string> remote path → identity, for the current listing */
    private array $identitiesByPath = [];

    /** @var array<string,int> identity → how many discovered files carry it */
    private array $identityCounts = [];

    /**
     * @param array<string,mixed> $preview the operator-facing plan
     * @param list<RemoteLogFile> $files the full discovery listing
     */
    public function __construct(
        private readonly array $preview,
        private readonly array $files,
    ) {
        foreach ($this->preview['files'] as $plannedFile) {
            $path = (string) $plannedFile['path'];
            if ((bool) $plannedFile['will_sync']) {
                $this->actionablePaths[$path] = true;
            }
            if ((bool) $plannedFile['will_sync'] || (string) $plannedFile['action'] !== 'unchanged') {
                $this->processedPaths[$path] = true;
            }
        }
        foreach ($this->files as $file) {
            $this->identitiesByPath[$file->path] = $file->identity;
            $this->identityCounts[$file->identity] = ($this->identityCounts[$file->identity] ?? 0) + 1;
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->preview;
    }

    public function snapshot(): string
    {
        return (string) $this->preview['snapshot'];
    }

    /**
     * Files with bytes to fetch or a tail to index — what progress is measured against.
     *
     * @return list<RemoteLogFile>
     */
    public function actionableFiles(): array
    {
        return $this->filesIn($this->actionablePaths);
    }

    /**
     * Every file the run must touch, including the reconcile-only ones.
     *
     * @return list<RemoteLogFile>
     */
    public function processedFiles(): array
    {
        return $this->filesIn($this->processedPaths);
    }

    /**
     * Bytes the run expects to fetch — the progress bar's denominator.
     *
     * Summed over the whole plan rather than the actionable files alone, which
     * is the same figure: a file with bytes to fetch is actionable by
     * definition, so everything else contributes zero.
     */
    public function bytesToFetch(): int
    {
        return (int) $this->preview['summary']['bytes_to_fetch'];
    }

    public function countsAsProgress(RemoteLogFile $file): bool
    {
        return isset($this->actionablePaths[$file->path]);
    }

    /** @return array<string,string> */
    public function identitiesByPath(): array
    {
        return $this->identitiesByPath;
    }

    /** @return array<string,int> */
    public function identityCounts(): array
    {
        return $this->identityCounts;
    }

    /**
     * @param array<string,true> $paths
     * @return list<RemoteLogFile>
     */
    private function filesIn(array $paths): array
    {
        return array_values(array_filter(
            $this->files,
            static fn (RemoteLogFile $file): bool => isset($paths[$file->path]),
        ));
    }
}
