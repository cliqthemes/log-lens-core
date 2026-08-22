<?php
declare(strict_types=1);

namespace LogLens\Services;

use LogLens\Config;
use LogLens\Domain\LogEvent;
use LogLens\Contracts\LogParserInterface;
use LogLens\Parsing\ParserRegistry;
use LogLens\Repositories\IssueRepository;
use LogLens\Storage\Connection;
use LogLens\Storage\Dialects;
use LogLens\Storage\PdoConnection;
use PDO;
use RuntimeException;

final class LogImportService
{
    public const PARSER_VERSION = 11;
    private readonly Connection $db;
    private readonly IssueRepository $issues;
    private readonly TagService $tags;
    private readonly IngestionSettingsService $settings;
    private readonly ModuleService $modules;
    /** @var list<string> */
    private readonly array $indexedSeverities;

    public function __construct(
        Connection|PDO $db,
        private readonly ParserRegistry $parsers = new ParserRegistry(),
    ) {
        $this->db = $db instanceof Connection ? $db : new PdoConnection($db, Dialects::active());
        $this->issues = new IssueRepository($this->db);
        $this->tags = new TagService($this->db);
        $this->settings = new IngestionSettingsService($this->db);
        $this->modules = new ModuleService($this->db);
        $this->indexedSeverities = $this->settings->severities();
    }

    public function importPaths(array $paths): array
    {
        return $this->run($this->discover($paths), archiveDirectory: null, incomingDirectory: null);
    }

    public function importManagedPath(string $path, ?int $moduleId, int $streamId, bool $finalize = true): array
    {
        return $this->run(
            [$path],
            archiveDirectory: null,
            incomingDirectory: null,
            modulesByPath: [$path => $moduleId],
            streamsByPath: [$path => $streamId],
            finalize: $finalize,
        );
    }

    /**
     * Recompute group aggregates and reapply tag rules once, after a batch of
     * deferred imports (see importManagedPath with finalize: false).
     */
    public function finalize(): void
    {
        // Prune aged/excess occurrences first (self-throttled, off by default),
        // then recompute aggregates so any now-empty ingested group is removed.
        (new OccurrenceRetentionService($this->db))->prune();
        $this->issues->refreshAggregates();
        $this->tags->applyRules();
        // Notify enabled plugins (e.g. alerting) that ingestion completed. This
        // is a no-op unless a plugin with an ingest hook is enabled.
        (new \LogLens\Plugins\PluginManager($this->db))->onIngest();
    }

    public function importIncoming(string $incomingDirectory, string $processedDirectory): array
    {
        $result = $this->run(
            $this->discover([$incomingDirectory]),
            archiveDirectory: $processedDirectory,
            incomingDirectory: $incomingDirectory,
        );
        $result['retention'] = (new ProcessedRetentionService($processedDirectory))->prune();
        return $result;
    }

    public function rebuildAll(): array
    {
        $sources = $this->db->selectAll('SELECT path,module_id,source_stream_id FROM source_files ORDER BY path');
        $existing = array_values(array_filter(array_column($sources, 'path'), 'is_file'));
        $modulesByPath = [];
        $streamsByPath = [];
        foreach ($sources as $source) {
            $modulesByPath[$source['path']] = $source['module_id'] === null ? null : (int) $source['module_id'];
            if ($source['source_stream_id'] !== null) {
                $streamsByPath[$source['path']] = (int) $source['source_stream_id'];
            }
        }
        $this->db->execute('UPDATE source_files SET parser_version=0,last_offset=0');
        return $this->run(
            $existing,
            archiveDirectory: null,
            incomingDirectory: null,
            modulesByPath: $modulesByPath,
            streamsByPath: $streamsByPath,
        );
    }

    private function run(
        array $files,
        ?string $archiveDirectory,
        ?string $incomingDirectory,
        array $modulesByPath = [],
        array $streamsByPath = [],
        bool $finalize = true,
    ): array
    {
        $result = [
            'files' => 0,
            'events' => 0,
            'groups' => 0,
            'skipped' => 0,
            'processed' => 0,
            'ingested_severities' => $this->indexedSeverities,
            'by_type' => [],
            'errors' => [],
        ];
        foreach ($files as $file) {
            try {
                $module = $this->moduleForPath($file, $incomingDirectory);
                $moduleId = array_key_exists($file, $modulesByPath)
                    ? $modulesByPath[$file]
                    : ($module === null ? null : (int) $module['id']);
                $streamId = array_key_exists($file, $streamsByPath) ? (int) $streamsByPath[$file] : null;
                $fileResult = $this->importFile($file, $moduleId, $streamId);
                $result['files']++;
                $result['events'] += $fileResult['events'];
                $result['groups'] += $fileResult['groups'];
                $result['skipped'] += (int) $fileResult['skipped'];
                $type = $fileResult['log_type'];
                $result['by_type'][$type] = ($result['by_type'][$type] ?? 0) + $fileResult['events'];
                if ($archiveDirectory !== null) {
                    $moduleArchive = $module === null
                        ? $archiveDirectory
                        : $archiveDirectory . DIRECTORY_SEPARATOR . $module['slug'];
                    $this->archive(
                        $file,
                        $fileResult['file_id'],
                        $moduleArchive,
                        !($fileResult['archive_only'] ?? false),
                    );
                    $result['processed']++;
                }
            } catch (\Throwable $exception) {
                $result['errors'][] = basename($file) . ': ' . $exception->getMessage();
            }
        }
        if ($finalize) {
            $this->finalize();
        }
        ksort($result['by_type']);
        return $result;
    }

    /**
     * Import one file, resuming from wherever the last run stopped.
     *
     * @return array<string,mixed>
     */
    private function importFile(string $path, ?int $moduleId, ?int $streamId = null): array
    {
        clearstatcache(true, $path);
        $stat = stat($path);
        if ($stat === false) {
            throw new RuntimeException('Cannot inspect file metadata.');
        }
        $parser = $this->parsers->forFile($path);
        $importVersion = $this->importVersion();
        $previous = $this->db->selectOne('SELECT * FROM source_files WHERE path=?', [$path]);
        if ($previous === null && $streamId === null) {
            // A file being imported by hand may already exist as a connector's
            // mirror under a different path; adopting that row keeps its events
            // from being ingested twice.
            $reconciled = $this->reconcileManagedSource($path, $moduleId, $parser->type());
            if ($reconciled !== null) {
                return $reconciled;
            }
        }
        if ($previous !== null && $this->isUnchanged($previous, $stat, $parser->type(), $importVersion, $moduleId, $streamId)) {
            return [
                'events' => 0,
                'groups' => 0,
                'skipped' => true,
                'file_id' => (int) $previous['id'],
                'log_type' => $parser->type(),
            ];
        }
        return $this->indexFile($path, $previous, $stat, $parser, $importVersion, $moduleId, $streamId);
    }

    /**
     * True when a re-import would produce exactly what is already stored.
     *
     * Size *and* mtime, because either alone is fooled by an ordinary log: an
     * edit in place can leave the size identical, and a rotation can reuse the
     * mtime. The parser version is part of it too — a parser improvement has to
     * re-read files it already read, or the fix reaches only new events.
     *
     * @param array<string,mixed> $previous
     * @param array<string,mixed> $stat
     */
    private function isUnchanged(
        array $previous,
        array $stat,
        string $logType,
        int $importVersion,
        ?int $moduleId,
        ?int $streamId,
    ): bool {
        return (int) $previous['size'] === $stat['size']
            && (int) $previous['modified_at'] === $stat['mtime']
            && $this->matchesImportShape($previous, $logType, $importVersion, $moduleId, $streamId);
    }

    /**
     * Whether the stored row was produced by the same parser, module, and
     * stream this import is using. When it was not, the offset it recorded
     * describes a different reading of the file and cannot be resumed from.
     *
     * @param array<string,mixed> $previous
     */
    private function matchesImportShape(
        array $previous,
        string $logType,
        int $importVersion,
        ?int $moduleId,
        ?int $streamId,
    ): bool {
        return (int) $previous['parser_version'] === $importVersion
            && $previous['log_type'] === $logType
            && $this->nullableInt($previous['module_id']) === $moduleId
            && $this->nullableInt($previous['source_stream_id']) === $streamId;
    }

    /**
     * Parse a file's unread tail into issues, inside a transaction.
     *
     * @param array<string,mixed>|null $previous
     * @param array<string,mixed> $stat
     * @return array<string,mixed>
     */
    private function indexFile(
        string $path,
        ?array $previous,
        array $stat,
        LogParserInterface $parser,
        int $importVersion,
        ?int $moduleId,
        ?int $streamId,
    ): array {
        // Uses the pdo() escape hatch rather than Connection::transaction(),
        // which has no way to express "commit now, then open a fresh
        // transaction" mid-callback (see indexEvents) — the periodic commit
        // keeps one very large log file from holding a single multi-million-row
        // transaction open. Not nestable inside another transaction() call;
        // nothing today calls importFile() from within one.
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            [$fileId, $offset] = $this->resumePoint($path, $previous, $stat, $parser, $importVersion, $moduleId, $streamId);
            [$events, $groups] = $this->indexEvents($parser, $path, $offset, $fileId, $moduleId, $pdo);
            $this->recordSource($fileId, $path, $stat, $parser->type(), $importVersion, $moduleId, $streamId);
            $pdo->commit();
            return [
                'events' => $events,
                'groups' => $groups,
                'skipped' => false,
                'file_id' => $fileId,
                'log_type' => $parser->type(),
            ];
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * The source row to import into and the byte offset to start at.
     *
     * Restarting from zero also means discarding what the previous reading
     * produced — otherwise re-parsing a file would double every event in it.
     * That happens when the file shrank (it was truncated or rotated in place,
     * so the old offset points past content that no longer exists) or when the
     * import shape changed.
     *
     * @param array<string,mixed>|null $previous
     * @param array<string,mixed> $stat
     * @return array{int,int} source file id and start offset
     */
    private function resumePoint(
        string $path,
        ?array $previous,
        array $stat,
        LogParserInterface $parser,
        int $importVersion,
        ?int $moduleId,
        ?int $streamId,
    ): array {
        if ($previous === null) {
            $fileId = $this->db->insert(
                'INSERT INTO source_files(path,size,modified_at,log_type,channel,module_id,source_stream_id)
                 VALUES(?,?,?,?,?,?,?)',
                [$path, $stat['size'], $stat['mtime'], $parser->type(), basename($path), $moduleId, $streamId],
            );
            return [$fileId, 0];
        }
        $fileId = (int) $previous['id'];
        $offset = (int) $previous['last_offset'];
        if (
            $stat['size'] < $offset
            || !$this->matchesImportShape($previous, $parser->type(), $importVersion, $moduleId, $streamId)
        ) {
            $this->issues->clearSource($fileId);
            return [$fileId, 0];
        }
        return [$fileId, $offset];
    }

    /**
     * Persist every indexable event from $offset on.
     *
     * The transaction is committed and reopened every thousand events. A single
     * transaction over a multi-gigabyte log would grow the write-ahead log
     * without bound and hold locks for the whole import; the trade is that an
     * interruption leaves the events already committed in place, which is
     * exactly what the resumable offset is for.
     *
     * @return array{int,int} events indexed and distinct issues touched
     */
    private function indexEvents(
        LogParserInterface $parser,
        string $path,
        int $offset,
        int $fileId,
        ?int $moduleId,
        PDO $pdo,
    ): array {
        $events = 0;
        $groups = [];
        foreach ($parser->parse($path, $offset) as $event) {
            if (!$this->shouldIndex($event)) {
                continue;
            }
            $fingerprint = $this->issues->persist($fileId, $event, $moduleId, $this->eventHash($event));
            if ($fingerprint !== null) {
                $events++;
                $groups[$fingerprint] = true;
            }
            if ($events > 0 && $events % 1_000 === 0) {
                $pdo->commit();
                $pdo->beginTransaction();
            }
        }
        return [$events, count($groups)];
    }

    /**
     * Identifies one event exactly, so a re-read of the same bytes does not
     * store it twice. Distinct from an issue's fingerprint, which deliberately
     * groups events that differ.
     */
    private function eventHash(LogEvent $event): string
    {
        return hash('sha256', implode('|', [
            $event->occurredAt,
            $event->severity,
            $event->environment,
            $event->logType,
            $event->channel,
            $event->body,
        ]));
    }

    /** @param array<string,mixed> $stat */
    private function recordSource(
        int $fileId,
        string $path,
        array $stat,
        string $logType,
        int $importVersion,
        ?int $moduleId,
        ?int $streamId,
    ): void {
        $this->db->execute(
            'UPDATE source_files SET
                size=?,modified_at=?,last_offset=?,parser_version=?,log_type=?,channel=?,
                module_id=?,source_stream_id=?,imported_at=CURRENT_TIMESTAMP
             WHERE id=?',
            [
                $stat['size'],
                $stat['mtime'],
                $stat['size'],
                $importVersion,
                $logType,
                basename($path),
                $moduleId,
                $streamId,
                $fileId,
            ],
        );
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function shouldIndex(LogEvent $event): bool
    {
        return in_array($event->severity, $this->indexedSeverities, true);
    }

    private function importVersion(): int
    {
        return self::PARSER_VERSION * 1_000 + $this->settings->importSignature();
    }

    private function discover(array $paths): array
    {
        $pattern = self::logFilePattern();
        $files = [];
        foreach ($paths as $input) {
            $path = realpath((string) $input) ?: (string) $input;
            if (is_file($path) && preg_match($pattern, basename($path)) === 1) {
                $files[] = $path;
                continue;
            }
            if (!is_dir($path)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $item) {
                if (
                    $item->isFile()
                    && !str_contains($item->getPathname(), DIRECTORY_SEPARATOR . 'processed' . DIRECTORY_SEPARATOR)
                    && preg_match($pattern, $item->getFilename()) === 1
                ) {
                    $files[] = $item->getPathname();
                }
            }
        }
        sort($files);
        return array_values(array_unique($files));
    }

    /**
     * The configured PCRE that decides which file names are log files.
     * Falls back to the built-in default when the configured value is missing
     * or not a usable pattern.
     */
    public static function logFilePattern(): string
    {
        return Config::pattern('ingestion.log_file_pattern', '/\.log(?:\.\d+)?$/i');
    }

    /** @return array<string,mixed>|null */
    private function moduleForPath(string $path, ?string $incomingDirectory): ?array
    {
        if ($incomingDirectory === null) {
            return null;
        }
        $root = rtrim(realpath($incomingDirectory) ?: $incomingDirectory, DIRECTORY_SEPARATOR);
        $absolute = realpath($path) ?: $path;
        $prefix = $root . DIRECTORY_SEPARATOR;
        if (!str_starts_with($absolute, $prefix)) {
            return null;
        }
        $relative = substr($absolute, strlen($prefix));
        $parts = explode(DIRECTORY_SEPARATOR, $relative);
        if (count($parts) < 2 || trim($parts[0]) === '') {
            return null;
        }
        return $this->modules->findOrCreateDirectory($parts[0]);
    }

    private function archive(string $path, int $fileId, string $directory, bool $updateSource = true): void
    {
        $directory = rtrim($directory, DIRECTORY_SEPARATOR);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create the processed log directory.');
        }
        $target = $directory . DIRECTORY_SEPARATOR . basename($path);
        if (file_exists($target)) {
            $extension = pathinfo($path, PATHINFO_EXTENSION);
            $stem = pathinfo($path, PATHINFO_FILENAME);
            $target = sprintf(
                '%s%s%s-%s-%s.%s',
                $directory,
                DIRECTORY_SEPARATOR,
                $stem,
                date('Ymd-His'),
                substr(hash('sha256', $path . microtime()), 0, 6),
                $extension ?: 'log',
            );
        }
        if (!rename($path, $target)) {
            throw new RuntimeException('Could not move the imported log into processed/.');
        }
        if ($updateSource) {
            $this->db->execute('UPDATE source_files SET path=?,channel=? WHERE id=?', [$target, basename($target), $fileId]);
        }
    }

    /** @return array<string,mixed>|null */
    private function reconcileManagedSource(string $incomingPath, ?int $moduleId, string $logType): ?array
    {
        $moduleWhere = $moduleId === null ? 'module_id IS NULL' : 'module_id=?';
        $parameters = $moduleId === null
            ? [$logType, basename($incomingPath)]
            : [$moduleId, $logType, basename($incomingPath)];
        $candidates = $this->db->selectAll(
            "SELECT * FROM source_files
             WHERE source_stream_id IS NOT NULL AND {$moduleWhere} AND log_type=? AND channel=?
             ORDER BY imported_at DESC LIMIT 20",
            $parameters,
        );
        $incomingSize = filesize($incomingPath);
        if ($incomingSize === false) {
            return null;
        }
        $matches = [];
        foreach ($candidates as $candidate) {
            if (!is_file($candidate['path'])) {
                continue;
            }
            $candidateSize = filesize($candidate['path']);
            if ($candidateSize === false) {
                continue;
            }
            $length = min($incomingSize, $candidateSize);
            if (hash_equals(
                $this->prefixHash($incomingPath, $length),
                $this->prefixHash($candidate['path'], $length),
            )) {
                $matches[] = [$candidate, $candidateSize];
            }
        }
        if (count($matches) !== 1) {
            return null;
        }
        [$candidate, $candidateSize] = $matches[0];
        if ($incomingSize <= $candidateSize) {
            return [
                'events' => 0,
                'groups' => 0,
                'skipped' => true,
                'file_id' => (int) $candidate['id'],
                'log_type' => $logType,
                'archive_only' => true,
            ];
        }

        $source = fopen($incomingPath, 'rb');
        $target = fopen($candidate['path'], 'ab');
        if ($source === false || $target === false || fseek($source, $candidateSize) !== 0) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($target)) {
                fclose($target);
            }
            throw new RuntimeException('Could not merge the manually supplied stream tail.');
        }
        if (stream_copy_to_stream($source, $target) !== $incomingSize - $candidateSize) {
            fclose($source);
            fclose($target);
            throw new RuntimeException('The manually supplied stream tail was only partially copied.');
        }
        fclose($source);
        fclose($target);
        $result = $this->importFile(
            $candidate['path'],
            $moduleId,
            (int) $candidate['source_stream_id'],
        );
        $result['archive_only'] = true;
        return $result;
    }

    private function prefixHash(string $path, int $length): string
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not hash a source prefix.');
        }
        $hash = hash_init('sha256');
        $remaining = $length;
        while ($remaining > 0 && !feof($handle)) {
            $chunk = fread($handle, min(1_048_576, $remaining));
            if ($chunk === false) {
                fclose($handle);
                throw new RuntimeException('Could not hash a source prefix.');
            }
            hash_update($hash, $chunk);
            $remaining -= strlen($chunk);
        }
        fclose($handle);
        if ($remaining !== 0) {
            throw new RuntimeException('Source prefix was shorter than expected.');
        }
        return hash_final($hash);
    }
}
