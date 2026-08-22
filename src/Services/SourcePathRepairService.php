<?php
declare(strict_types=1);

namespace LogLens\Services;

use LogLens\Storage\Connection;
use LogLens\Storage\Dialects;
use LogLens\Storage\PdoConnection;

use PDO;

final class SourcePathRepairService
{
    /**
     * Per-process record of workspace fingerprints already confirmed current.
     * A reused PHP-FPM worker resolves the same application on every request;
     * the workspace directories do not move mid-request, so once a fingerprint
     * has matched (nothing to repair) this worker skips even the settings
     * lookup on later requests. A genuine relocation changes the fingerprint,
     * which misses the cache and re-runs the repair.
     *
     * @var array<string,true>
     */
    private static array $verified = [];

    private readonly Connection $db;

    public function __construct(
        Connection|PDO $db,
        private readonly string $processedDirectory,
        private readonly string $sourcesDirectory,
    ) {
        $this->db = $db instanceof Connection ? $db : new PdoConnection($db, Dialects::active());
    }

    /** Forget the per-process verification cache (test isolation). */
    public static function resetCache(): void
    {
        self::$verified = [];
    }

    /** @return array{source_files:int,source_streams:int} */
    public function repairMovedWorkspacePaths(): array
    {
        $workspace = json_encode([
            'processed' => realpath($this->processedDirectory) ?: $this->processedDirectory,
            'sources' => realpath($this->sourcesDirectory) ?: $this->sourcesDirectory,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (isset(self::$verified[$workspace])) {
            return ['source_files' => 0, 'source_streams' => 0];
        }
        if ($this->db->selectValue(Dialects::active()->keyValueLookup(), ['source_path_workspace']) === $workspace) {
            self::$verified[$workspace] = true;
            return ['source_files' => 0, 'source_streams' => 0];
        }

        [$repairedSources, $repairedStreams] = $this->db->transaction(function (Connection $db) use ($workspace): array {
            $repairedSources = 0;
            $repairedStreams = 0;
            $sources = $db->selectAll('SELECT id,path,size FROM source_files');
            foreach ($sources as $source) {
                if (is_file((string) $source['path'])) {
                    continue;
                }
                $candidate = $this->relocatedPath((string) $source['path'], (int) $source['size']);
                if ($candidate === null) {
                    continue;
                }
                $repairedSources += $db->execute('UPDATE source_files SET path=? WHERE id=?', [$candidate, (int) $source['id']]);
            }

            $streams = $db->selectAll('SELECT id,local_path,fetched_offset,source_file_id FROM source_streams');
            foreach ($streams as $stream) {
                if (is_file((string) $stream['local_path'])) {
                    continue;
                }
                $candidate = $this->relocatedPath((string) $stream['local_path'], (int) $stream['fetched_offset']);
                if ($candidate === null) {
                    continue;
                }
                $repairedStreams += $db->execute('UPDATE source_streams SET local_path=? WHERE id=?', [$candidate, (int) $stream['id']]);
                if ($stream['source_file_id'] !== null) {
                    $db->execute('UPDATE source_files SET path=? WHERE id=?', [$candidate, (int) $stream['source_file_id']]);
                }
            }
            $keyColumn = Dialects::active()->quoteIdentifier('key');
            $db->execute(Dialects::active()->upsert(
                'app_settings',
                "{$keyColumn},value",
                "'source_path_workspace',?",
                [$keyColumn],
                ['value', 'updated_at=CURRENT_TIMESTAMP'],
            ), [$workspace]);
            return [$repairedSources, $repairedStreams];
        });
        self::$verified[$workspace] = true;
        return ['source_files' => $repairedSources, 'source_streams' => $repairedStreams];
    }

    private function relocatedPath(string $oldPath, int $expectedSize): ?string
    {
        foreach ([
            'processed' => $this->processedDirectory,
            'sources' => $this->sourcesDirectory,
        ] as $segment => $currentDirectory) {
            $needle = '/' . $segment . '/';
            $position = strrpos(str_replace('\\', '/', $oldPath), $needle);
            if ($position === false) {
                continue;
            }
            $relative = substr(str_replace('\\', '/', $oldPath), $position + strlen($needle));
            if ($relative === '' || in_array('..', explode('/', $relative), true)) {
                return null;
            }
            $candidate = rtrim($currentDirectory, '/') . '/' . $relative;
            $size = is_file($candidate) ? filesize($candidate) : false;
            if ($size !== false && (int) $size === $expectedSize) {
                return realpath($candidate) ?: $candidate;
            }
        }
        return null;
    }
}
