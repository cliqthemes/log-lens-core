<?php
declare(strict_types=1);

namespace LogLens\Services;

use LogLens\Config;

/**
 * Prunes the processed/ archive by age and/or file count.
 *
 * Removing an archived file only makes raw-event retrieval unavailable for its
 * occurrences (the API already handles that gracefully); indexed issue groups,
 * counts, and dates are untouched. Both rules are disabled when configured to 0.
 */
final class ProcessedRetentionService
{
    public function __construct(
        private readonly string $processedDirectory,
        private readonly ?int $maxAgeDays = null,
        private readonly ?int $maxFiles = null,
        private readonly ?int $now = null,
    ) {
    }

    /**
     * The active policy and current archive size, without deleting anything.
     *
     * @return array{policy:array{processed_max_age_days:int,processed_max_files:int},archived_files:int,archived_bytes:int}
     */
    public function status(): array
    {
        $files = $this->archivedFiles();
        return [
            'policy' => [
                'processed_max_age_days' => $this->maxAgeDays ?? Config::int('retention.processed_max_age_days', 0, 0),
                'processed_max_files' => $this->maxFiles ?? Config::int('retention.processed_max_files', 0, 0),
            ],
            'archived_files' => count($files),
            'archived_bytes' => array_sum(array_column($files, 'size')),
        ];
    }

    /** @return array{enabled:bool,deleted:int,freed_bytes:int,remaining:int,scanned:int} */
    public function prune(): array
    {
        $maxAgeDays = $this->maxAgeDays ?? Config::int('retention.processed_max_age_days', 0, 0);
        $maxFiles = $this->maxFiles ?? Config::int('retention.processed_max_files', 0, 0);
        $files = $this->archivedFiles();
        $result = [
            'enabled' => $maxAgeDays > 0 || $maxFiles > 0,
            'deleted' => 0,
            'freed_bytes' => 0,
            'remaining' => count($files),
            'scanned' => count($files),
        ];
        if (!$result['enabled'] || $files === []) {
            return $result;
        }

        $now = $this->now ?? time();
        $ageCutoff = $maxAgeDays > 0 ? $now - $maxAgeDays * 86_400 : null;
        // Newest first, so the count rule keeps the head and drops the tail.
        usort($files, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

        $kept = 0;
        foreach ($files as $file) {
            $tooOld = $ageCutoff !== null && $file['mtime'] < $ageCutoff;
            $overLimit = $maxFiles > 0 && $kept >= $maxFiles;
            if (!$tooOld && !$overLimit) {
                $kept++;
                continue;
            }
            if (@unlink($file['path'])) {
                $result['deleted']++;
                $result['freed_bytes'] += $file['size'];
            } else {
                $kept++;
            }
        }
        $result['remaining'] = $kept;
        return $result;
    }

    /** @return list<array{path:string,mtime:int,size:int}> */
    private function archivedFiles(): array
    {
        if (!is_dir($this->processedDirectory)) {
            return [];
        }
        $pattern = LogImportService::logFilePattern();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->processedDirectory, \FilesystemIterator::SKIP_DOTS)
        );
        $files = [];
        foreach ($iterator as $item) {
            if (!$item->isFile() || preg_match($pattern, $item->getFilename()) !== 1) {
                continue;
            }
            $files[] = [
                'path' => $item->getPathname(),
                'mtime' => (int) $item->getMTime(),
                'size' => (int) $item->getSize(),
            ];
        }
        return $files;
    }
}
