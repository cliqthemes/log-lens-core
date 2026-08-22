<?php
declare(strict_types=1);

namespace LogLens\Services\Sync;

use LogLens\Contracts\LogSourceConnectorInterface;
use LogLens\Domain\RemoteLogFile;
use LogLens\Storage\Connection;

/**
 * Pairs a remote log file with a copy of it that was already imported by hand.
 *
 * Without this, connecting a server after uploading its logs manually would
 * re-index everything: the connector sees a file it has no stream for, mirrors
 * it from byte zero, and every event is ingested a second time. So before
 * creating a stream, an unattached local source of the same size is confirmed
 * to be a *prefix* of the remote file by comparing hashes over that many bytes,
 * and adopted as the mirror if it is.
 *
 * A hash comparison over a prefix is not proof of identity, which is why an
 * ambiguous result is never used: {@see uniqueMatch()} returns a candidate only
 * when exactly one matched. Adopting the wrong file would append a different
 * server's bytes onto this one's mirror, and nothing downstream could tell.
 */
final class ManualSourceMatcher
{
    /** Local sources considered per remote file; the ORDER BY puts the likeliest first. */
    private const CANDIDATE_LIMIT = 25;

    public function __construct(
        private readonly Connection $db,
        private readonly SourceStreamRepository $streams,
    ) {
    }

    /**
     * The manual source to adopt for one remote file, if there is exactly one.
     *
     * @return array<string,mixed>|null
     */
    public function match(
        ?int $moduleId,
        RemoteLogFile $file,
        LogSourceConnectorInterface $adapter,
    ): ?array {
        $candidates = $this->candidates($moduleId, $file);
        $requests = [];
        $requestSizes = [];
        foreach ($candidates as $candidate) {
            $size = (int) $candidate['size'];
            if (!isset($requestSizes[$size])) {
                $requestSizes[$size] = count($requests);
                $requests[] = ['file' => $file, 'length' => $size];
            }
        }
        return $this->uniqueMatch($candidates, $requestSizes, $adapter->prefixHashes($requests));
    }

    /**
     * The same question asked for a whole discovery listing at once.
     *
     * A preview covers every file the connector found, and each candidate needs
     * a prefix hash from the remote side. Asking per file would be one round
     * trip each — over SSH that is the difference between a preview that
     * returns and one the operator gives up on — so every request is batched
     * into a single {@see LogSourceConnectorInterface::prefixHashes()} call.
     *
     * @param list<RemoteLogFile> $files
     * @return array<string,array<string,mixed>> keyed by remote path
     */
    public function matchAll(
        int $connectorId,
        ?int $moduleId,
        array $files,
        LogSourceConnectorInterface $adapter,
    ): array {
        $candidateSets = [];
        $requests = [];
        $requestIndexes = [];
        foreach ($files as $file) {
            if (
                $this->streams->latestForPath($connectorId, $file->path) !== null
                || $this->streams->latestForIdentity($connectorId, $file->identity) !== null
            ) {
                continue;
            }
            $candidates = $this->candidates($moduleId, $file);
            if ($candidates === []) {
                continue;
            }
            $candidateSets[$file->path] = $candidates;
            foreach ($candidates as $candidate) {
                $key = $this->requestKey($file->path, (int) $candidate['size']);
                if (!isset($requestIndexes[$key])) {
                    $requestIndexes[$key] = count($requests);
                    $requests[] = ['file' => $file, 'length' => (int) $candidate['size']];
                }
            }
        }
        $hashes = $adapter->prefixHashes($requests);
        $matches = [];
        foreach ($files as $file) {
            $candidates = $candidateSets[$file->path] ?? [];
            if ($candidates === []) {
                continue;
            }
            $indexes = [];
            foreach ($candidates as $candidate) {
                $size = (int) $candidate['size'];
                $indexes[$size] = $requestIndexes[$this->requestKey($file->path, $size)];
            }
            $match = $this->uniqueMatch($candidates, $indexes, $hashes);
            if ($match !== null) {
                $matches[$file->path] = $match;
            }
        }
        return $matches;
    }

    /**
     * Unattached local sources that could be a prefix of this remote file.
     *
     * `size<=` rather than `size=`: the manual import may predate the remote
     * file's latest appends, which is the common case. Rows are re-checked
     * against the filesystem because a source recorded in the database may have
     * been moved or truncated since, and a stale size would make the prefix
     * comparison meaningless.
     *
     * @return list<array<string,mixed>>
     */
    public function candidates(?int $moduleId, RemoteLogFile $file): array
    {
        $moduleWhere = $moduleId === null ? 'sf.module_id IS NULL' : 'sf.module_id=?';
        $parameters = $moduleId === null
            ? [$file->size, $file->channel]
            : [$moduleId, $file->size, $file->channel];
        $rows = $this->db->selectAll(
            "SELECT sf.* FROM source_files sf
             WHERE sf.source_stream_id IS NULL AND {$moduleWhere} AND sf.size<=?
             ORDER BY (sf.channel=?) DESC,sf.size DESC LIMIT " . self::CANDIDATE_LIMIT,
            $parameters,
        );
        $candidates = [];
        foreach ($rows as $candidate) {
            if (!is_file($candidate['path'])) {
                continue;
            }
            $size = filesize($candidate['path']);
            if ($size === false || $size > $file->size || $size !== (int) $candidate['size']) {
                continue;
            }
            $candidates[] = $candidate;
        }
        // Narrow to the same channel or file name when anything matches: those
        // are the ones a hash tie would otherwise be decided by chance.
        $preferred = array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool =>
                $candidate['channel'] === $file->channel
                || basename((string) $candidate['path']) === basename($file->path),
        ));
        return $preferred !== [] ? $preferred : $candidates;
    }

    /**
     * @param list<array<string,mixed>> $candidates
     * @param array<int,int> $hashIndexes candidate size → index into $remoteHashes
     * @param list<string> $remoteHashes
     * @return array<string,mixed>|null
     */
    private function uniqueMatch(array $candidates, array $hashIndexes, array $remoteHashes): ?array
    {
        $matches = [];
        foreach ($candidates as $candidate) {
            $index = $hashIndexes[(int) $candidate['size']] ?? null;
            $localHash = hash_file('sha256', (string) $candidate['path']);
            if (
                $index !== null
                && isset($remoteHashes[$index])
                && $localHash !== false
                && hash_equals($localHash, $remoteHashes[$index])
            ) {
                $matches[] = $candidate;
            }
        }
        // Two local files sharing a prefix cannot be told apart, so neither is used.
        return count($matches) === 1 ? $matches[0] : null;
    }

    private function requestKey(string $path, int $size): string
    {
        return hash('sha256', $path . "\0" . $size);
    }
}
