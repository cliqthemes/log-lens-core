<?php
declare(strict_types=1);

namespace LogLens\Services\Sync;

use LogLens\Contracts\LogSourceConnectorInterface;
use LogLens\Domain\RemoteLogFile;

/**
 * Decides what a synchronization run will do, without doing any of it.
 *
 * Planning is a pure read: it compares each discovered remote file against the
 * stream that mirrors it and classifies the difference. Keeping it separate
 * from the run is what lets the operator confirm a plan before a byte moves,
 * and what makes the plan hashable — see {@see snapshot()}.
 */
final class SyncPlanner
{
    /** Every classification a file can get, in the order the summary reports them. */
    private const ACTIONS = [
        'new',
        'append',
        'unchanged',
        'rotation',
        'rename',
        'reconcile',
        'already_ingested',
        'index_local',
    ];

    public function __construct(
        private readonly SourceStreamRepository $streams,
        private readonly ManualSourceMatcher $manual,
    ) {
    }

    /**
     * @param array<string,mixed> $connector
     * @param list<RemoteLogFile> $files
     */
    public function plan(
        array $connector,
        LogSourceConnectorInterface $adapter,
        array $files,
    ): SyncPlan {
        $connectorId = (int) $connector['id'];
        $moduleId = $connector['module_id'] === null ? null : (int) $connector['module_id'];
        $manualMatches = $this->manual->matchAll($connectorId, $moduleId, $files, $adapter);

        $planned = [];
        $counts = array_fill_keys(self::ACTIONS, 0);
        $bytes = 0;
        $filesToSync = 0;
        $alreadyCurrent = 0;
        foreach ($files as $file) {
            [$action, $localSize] = $this->classify($connectorId, $file, $manualMatches[$file->path] ?? null);
            $fetchBytes = max(0, $file->size - $localSize);
            // A file with nothing to fetch still needs a pass when the previous
            // run stopped before indexing what it had already mirrored.
            $willSync = $fetchBytes > 0 || $action === 'index_local';
            $bytes += $fetchBytes;
            $willSync ? $filesToSync++ : $alreadyCurrent++;
            $counts[$action]++;
            $planned[] = [
                'path' => $file->path,
                'identity' => $file->identity,
                'size' => $file->size,
                'modified_at' => $file->modifiedAt,
                'action' => $action,
                'bytes_to_fetch' => $fetchBytes,
                'will_sync' => $willSync,
            ];
        }

        return new SyncPlan([
            'connector_id' => $connectorId,
            'connector_name' => (string) $connector['name'],
            'snapshot' => $this->snapshot($files, $planned),
            'files' => $planned,
            'summary' => [
                'files' => count($planned),
                'files_to_sync' => $filesToSync,
                'already_current' => $alreadyCurrent,
                'bytes_to_fetch' => $bytes,
                'actions' => $counts,
            ],
        ], $files);
    }

    /**
     * Classify one remote file against what is already mirrored, and say how
     * many local bytes count as already held.
     *
     * The order of these branches is the whole logic. A file with no stream for
     * its path but a stream for its identity was renamed, and its mirror
     * carries over. A file whose stream's identity no longer matches — or that
     * shrank below what was already fetched — was rotated, so the mirror is
     * worthless and the local size resets to zero rather than being trusted.
     *
     * @param array<string,mixed>|null $manualMatch
     * @return array{string,int} action and local size
     */
    private function classify(int $connectorId, RemoteLogFile $file, ?array $manualMatch): array
    {
        $stream = $this->streams->latestForPath($connectorId, $file->path);
        if ($stream === null) {
            $identityStream = $this->streams->latestForIdentity($connectorId, $file->identity);
            if ($identityStream !== null) {
                return ['rename', $this->mirroredSize($identityStream)];
            }
            if ($manualMatch !== null) {
                $localSize = (int) $manualMatch['size'];
                return [$localSize === $file->size ? 'already_ingested' : 'reconcile', $localSize];
            }
            return ['new', 0];
        }
        if ($stream['remote_identity'] !== $file->identity || $file->size < (int) $stream['fetched_offset']) {
            return ['rotation', 0];
        }
        $localSize = $this->mirroredSize($stream);
        if ($file->size > $localSize) {
            return ['append', $localSize];
        }
        if ($this->streams->hasUnindexedTail($stream, $localSize)) {
            return ['index_local', $localSize];
        }
        return ['unchanged', $localSize];
    }

    /** @param array<string,mixed> $stream */
    private function mirroredSize(array $stream): int
    {
        $path = (string) $stream['local_path'];
        return is_file($path) ? (int) filesize($path) : 0;
    }

    /**
     * A hash of both the remote listing and the plan derived from it.
     *
     * The operator confirms a plan and the run re-derives it; if anything moved
     * in between — a file grew, rotated, appeared — the hashes differ and the
     * run refuses rather than doing something the operator did not agree to.
     * Both halves are included on purpose: the remote listing alone would miss
     * a local change, and the plan alone would miss a remote file whose size
     * changed without changing its classification.
     *
     * @param list<RemoteLogFile> $files
     * @param list<array<string,mixed>> $planned
     */
    private function snapshot(array $files, array $planned): string
    {
        return hash('sha256', json_encode([
            'remote' => array_map(
                static fn (RemoteLogFile $file): array => [
                    $file->path,
                    $file->identity,
                    $file->size,
                    $file->modifiedAt,
                ],
                $files,
            ),
            'plan' => array_map(
                static fn (array $file): array => [
                    (string) $file['path'],
                    (string) $file['identity'],
                    (string) $file['action'],
                    (int) $file['bytes_to_fetch'],
                    (bool) $file['will_sync'],
                ],
                $planned,
            ),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
