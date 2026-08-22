<?php
declare(strict_types=1);

namespace LogLens\Services\Sync;

use LogLens\Config;
use LogLens\Contracts\LogSourceConnectorInterface;
use LogLens\Domain\RemoteLogFile;
use LogLens\Services\LogImportService;
use LogLens\Storage\Connection;
use RuntimeException;

/**
 * Brings one remote file's local mirror up to date and indexes what arrived.
 *
 * The mirror is append-only and byte-exact: a stream's local file is the bytes
 * the remote file had, in order, and the run only ever adds to its tail. That
 * is what makes an interrupted sync resumable — the mirror's own size is the
 * checkpoint, so the next run asks for the range after it and nothing is
 * fetched twice. It is also why the invariants below abort rather than repair:
 * a mirror larger than its remote generation, or a short read from the
 * connector, means the byte stream is no longer the file's history, and
 * appending to it would silently corrupt every offset already recorded.
 */
final class StreamSynchronizer
{
    private const CHUNK_SIZE = 8_388_608;
    private const PREFIX_HASH_BYTES = 1_048_576;

    public function __construct(
        private readonly Connection $db,
        private readonly string $sourcesDirectory,
        private readonly SourceStreamRepository $streams,
        private readonly ManualSourceMatcher $manual,
        private readonly SyncRunRecorder $runs,
        private readonly LogImportService $importer,
    ) {
    }

    /**
     * @param array<string,mixed> $connector
     * @param array<string,string> $identitiesByPath
     * @param array<string,int> $identityCounts
     * @return array<string,int>
     */
    public function synchronize(
        array $connector,
        LogSourceConnectorInterface $adapter,
        RemoteLogFile $file,
        array $identitiesByPath,
        array $identityCounts,
        int $runId,
    ): array {
        $connectorId = (int) $connector['id'];
        $moduleId = $connector['module_id'] === null ? null : (int) $connector['module_id'];

        [$stream, $created, $reconciled] = $this->resolveStream(
            $connectorId,
            $moduleId,
            $file,
            $adapter,
            $identitiesByPath,
            $identityCounts,
        );
        $bytesFetched = $this->mirror($adapter, $file, (string) $stream['local_path'], $runId);
        $events = $this->index($stream, $moduleId, $file, $adapter);

        return [
            'files_updated' => $bytesFetched > 0 ? 1 : 0,
            'bytes_fetched' => $bytesFetched,
            'events_indexed' => $events,
            'streams_created' => $created,
            'manual_sources_reconciled' => $reconciled,
        ];
    }

    /**
     * The stream this file's bytes belong to, creating a generation if needed.
     *
     * @param array<string,string> $identitiesByPath
     * @param array<string,int> $identityCounts
     * @return array{array<string,mixed>,int,int} stream, streams created, manual sources reconciled
     */
    private function resolveStream(
        int $connectorId,
        ?int $moduleId,
        RemoteLogFile $file,
        LogSourceConnectorInterface $adapter,
        array $identitiesByPath,
        array $identityCounts,
    ): array {
        $stream = $this->streams->latestForPath($connectorId, $file->path);
        $stream = $this->followRename($connectorId, $file, $stream, $identitiesByPath, $identityCounts);
        if (!$this->needsNewGeneration($stream, $file)) {
            return [$stream, 0, 0];
        }
        $generation = $stream === null ? 1 : (int) $stream['generation'] + 1;
        [$stream, $wasReconciled] = $this->createStream($connectorId, $moduleId, $file, $generation, $adapter);
        return [$stream, 1, $wasReconciled ? 1 : 0];
    }

    /**
     * Carry a stream over to a remote file that was renamed rather than rotated.
     *
     * All three conditions are load-bearing. The identity must be unique in the
     * listing, or a file duplicated under two names would have both claim the
     * same stream. The old path must not still be present with that identity —
     * if it is, this is a copy, not a rename. And the stream's path must
     * actually differ, or there is nothing to follow.
     *
     * @param array<string,mixed>|null $stream
     * @param array<string,string> $identitiesByPath
     * @param array<string,int> $identityCounts
     * @return array<string,mixed>|null
     */
    private function followRename(
        int $connectorId,
        RemoteLogFile $file,
        ?array $stream,
        array $identitiesByPath,
        array $identityCounts,
    ): ?array {
        if (($identityCounts[$file->identity] ?? 0) !== 1) {
            return $stream;
        }
        $identityStream = $this->streams->latestForIdentity($connectorId, $file->identity);
        if (
            $identityStream === null
            || $identityStream['remote_path'] === $file->path
            || ($identitiesByPath[$identityStream['remote_path']] ?? null) === $file->identity
        ) {
            return $stream;
        }
        $generation = $this->streams->nextGeneration($connectorId, $file->path);
        $this->streams->movePath((int) $identityStream['id'], $file->path, $generation, $file->modifiedAt);
        $identityStream['remote_path'] = $file->path;
        $identityStream['generation'] = $generation;
        return $identityStream;
    }

    /** @param array<string,mixed>|null $stream */
    private function needsNewGeneration(?array $stream, RemoteLogFile $file): bool
    {
        return $stream === null
            || $stream['remote_identity'] !== $file->identity
            || $file->size < (int) $stream['fetched_offset'];
    }

    /**
     * Append the bytes the mirror is missing, a chunk at a time.
     *
     * Chunked because a log file is arbitrarily large and the whole point of
     * this app is not to hold one in memory. Progress is reported per chunk
     * rather than per file: it is what the dashboard's live byte count reads,
     * and it is what proves to the stale-run reaper that this worker is alive.
     */
    private function mirror(
        LogSourceConnectorInterface $adapter,
        RemoteLogFile $file,
        string $localPath,
        int $runId,
    ): int {
        $localSize = is_file($localPath) ? filesize($localPath) : 0;
        if ($localSize === false) {
            throw new RuntimeException('Could not inspect the canonical source mirror.');
        }
        if ($localSize > $file->size) {
            throw new RuntimeException('Canonical source mirror is larger than the remote generation.');
        }
        $chunkSize = Config::int('sync.chunk_size', self::CHUNK_SIZE);
        $bytesFetched = 0;
        $offset = (int) $localSize;
        while ($offset < $file->size) {
            $length = min($chunkSize, $file->size - $offset);
            $content = $adapter->readRange($file, $offset, $length);
            if (strlen($content) !== $length) {
                throw new RuntimeException("Connector returned an incomplete byte range for {$file->path}.");
            }
            if (file_put_contents($localPath, $content, FILE_APPEND | LOCK_EX) !== $length) {
                throw new RuntimeException('Could not append to the canonical source mirror.');
            }
            $offset += $length;
            $bytesFetched += $length;
            $this->runs->addFetchedBytes($runId, $length);
        }
        return $bytesFetched;
    }

    /**
     * Parse the mirror's new tail and record the stream as caught up.
     *
     * The prefix hash is refreshed here rather than at creation because it is
     * how a later run recognises this generation's content — it has to describe
     * the file as it now stands.
     *
     * @param array<string,mixed> $stream
     */
    private function index(
        array $stream,
        ?int $moduleId,
        RemoteLogFile $file,
        LogSourceConnectorInterface $adapter,
    ): int {
        $localPath = (string) $stream['local_path'];
        // finalize: false — aggregates are recomputed once for the whole run.
        $import = $this->importer->importManagedPath($localPath, $moduleId, (int) $stream['id'], finalize: false);
        $sourceFileId = $this->db->selectValue('SELECT id FROM source_files WHERE path=?', [$localPath]);
        if ($sourceFileId === null) {
            throw new RuntimeException('The synchronized source was not registered after import.');
        }
        $prefixLength = min($file->size, Config::int('sync.prefix_hash_bytes', self::PREFIX_HASH_BYTES));
        $this->streams->recordSynced(
            (int) $stream['id'],
            (int) $sourceFileId,
            $moduleId,
            $file,
            $adapter->prefixHash($file, $prefixLength),
        );
        return (int) $import['events'];
    }

    /**
     * Open a new generation: its own directory, its own mirror file.
     *
     * The directory is keyed by a hash of the remote path plus the generation
     * number, so two remote files that share a basename never collide and a
     * rotation never overwrites the generation before it.
     *
     * @return array{array<string,mixed>,bool} stream row, whether a manual source was adopted
     */
    private function createStream(
        int $connectorId,
        ?int $moduleId,
        RemoteLogFile $file,
        int $generation,
        LogSourceConnectorInterface $adapter,
    ): array {
        $localDirectory = sprintf(
            '%s/%d/%s-g%d',
            rtrim($this->sourcesDirectory, '/'),
            $connectorId,
            substr(hash('sha256', $file->path), 0, 16),
            $generation,
        );
        if (!is_dir($localDirectory) && !mkdir($localDirectory, 0775, true) && !is_dir($localDirectory)) {
            throw new RuntimeException('Could not create the canonical source directory.');
        }
        $localPath = $localDirectory . '/' . basename($file->path);
        $candidate = $this->manual->match($moduleId, $file, $adapter);
        if ($candidate !== null) {
            // Adopting the manual import as the mirror is what keeps its
            // already-indexed events from being ingested a second time.
            if (!copy($candidate['path'], $localPath)) {
                throw new RuntimeException('Could not create a canonical mirror from the manually imported source.');
            }
        } elseif (file_put_contents($localPath, '') === false) {
            throw new RuntimeException('Could not initialize the canonical source mirror.');
        }
        $streamId = $this->streams->create(
            $connectorId,
            $moduleId,
            $file,
            $generation,
            $localPath,
            filesize($localPath) ?: 0,
        );
        if ($candidate !== null) {
            $this->db->execute(
                'UPDATE source_files SET path=?,source_stream_id=?,module_id=? WHERE id=?',
                [$localPath, $streamId, $moduleId, (int) $candidate['id']],
            );
            $this->streams->attachSourceFile($streamId, (int) $candidate['id']);
        }
        $stream = $this->streams->find($streamId);
        if ($stream === null) {
            throw new RuntimeException('The new source stream could not be read back.');
        }
        return [$stream, $candidate !== null];
    }
}
