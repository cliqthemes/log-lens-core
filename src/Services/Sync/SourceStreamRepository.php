<?php
declare(strict_types=1);

namespace LogLens\Services\Sync;

use LogLens\Domain\RemoteLogFile;
use LogLens\Storage\Connection;

/**
 * Reads and writes of the `source_streams` table.
 *
 * A stream is one generation of one remote file's local mirror. Two lookups
 * matter and they are not interchangeable: by remote *path* (what the file is
 * called now) and by remote *identity* (inode/size fingerprint — what the file
 * actually is). A rename changes the first and not the second; a rotation
 * changes the second and not the first. Both the planner and the synchronizer
 * ask the same two questions, so they ask them here.
 */
final class SourceStreamRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function latestForPath(int $connectorId, string $remotePath): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM source_streams WHERE connector_id=? AND remote_path=?
             ORDER BY generation DESC LIMIT 1',
            [$connectorId, $remotePath],
        );
    }

    /** @return array<string,mixed>|null */
    public function latestForIdentity(int $connectorId, string $remoteIdentity): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM source_streams WHERE connector_id=? AND remote_identity=?
             ORDER BY last_synced_at DESC,id DESC LIMIT 1',
            [$connectorId, $remoteIdentity],
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $streamId): ?array
    {
        return $this->db->selectOne('SELECT * FROM source_streams WHERE id=?', [$streamId]);
    }

    public function nextGeneration(int $connectorId, string $remotePath): int
    {
        return (int) $this->db->selectValue(
            'SELECT COALESCE(MAX(generation),0)+1 FROM source_streams WHERE connector_id=? AND remote_path=?',
            [$connectorId, $remotePath],
        );
    }

    public function create(
        int $connectorId,
        ?int $moduleId,
        RemoteLogFile $file,
        int $generation,
        string $localPath,
        int $fetchedOffset,
    ): int {
        return $this->db->insert(
            'INSERT INTO source_streams(
                connector_id,module_id,remote_path,remote_identity,generation,local_path,
                remote_size,fetched_offset,remote_modified_at
             ) VALUES(?,?,?,?,?,?,?,?,?)',
            [
                $connectorId,
                $moduleId,
                $file->path,
                $file->identity,
                $generation,
                $localPath,
                $file->size,
                $fetchedOffset,
                $file->modifiedAt,
            ],
        );
    }

    /** Follow a renamed remote file: same stream, new path, next generation. */
    public function movePath(int $streamId, string $remotePath, int $generation, int $modifiedAt): void
    {
        $this->db->execute(
            'UPDATE source_streams SET remote_path=?,generation=?,remote_modified_at=? WHERE id=?',
            [$remotePath, $generation, $modifiedAt, $streamId],
        );
    }

    public function attachSourceFile(int $streamId, int $sourceFileId): void
    {
        $this->db->execute('UPDATE source_streams SET source_file_id=? WHERE id=?', [$sourceFileId, $streamId]);
    }

    /** Record that the mirror is now caught up with the remote file. */
    public function recordSynced(
        int $streamId,
        int $sourceFileId,
        ?int $moduleId,
        RemoteLogFile $file,
        string $prefixHash,
    ): void {
        $this->db->execute(
            'UPDATE source_streams SET source_file_id=?,module_id=?,remote_size=?,fetched_offset=?,
             remote_modified_at=?,prefix_hash=?,last_synced_at=CURRENT_TIMESTAMP WHERE id=?',
            [
                $sourceFileId,
                $moduleId,
                $file->size,
                $file->size,
                $file->modifiedAt,
                $prefixHash,
                $streamId,
            ],
        );
    }

    /**
     * True when the mirror holds bytes the indexer has not read yet.
     *
     * This is the case a size comparison alone misses: the remote file has not
     * grown, so nothing needs fetching, but a previous run was interrupted
     * between appending to the mirror and indexing it. Without this the tail
     * would stay invisible until the file next changed.
     *
     * @param array<string,mixed> $stream
     */
    public function hasUnindexedTail(array $stream, int $localSize): bool
    {
        if ($localSize < 1 || empty($stream['source_file_id'])) {
            return false;
        }
        $offset = $this->db->selectValue(
            'SELECT last_offset FROM source_files WHERE id=?',
            [(int) $stream['source_file_id']],
        );
        return $offset !== null && (int) $offset < $localSize;
    }
}
