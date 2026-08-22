<?php
declare(strict_types=1);

namespace LogLens\Plugins\Releases;

use LogLens\Storage\Connection;
use LogLens\Storage\Dialects;
use LogLens\Storage\PdoConnection;

use InvalidArgumentException;
use PDO;

/**
 * Stores Source Map v3 files per release so browser stack traces can be
 * de-minified. Uploaded at deploy time (CI or the dashboard), keyed by release
 * + minified file name.
 */
final class SourceMapService
{
    // A generous ceiling on a single stored map (bundles' maps are large).
    private const MAX_BYTES = 16 * 1024 * 1024;

    private readonly Connection $db;

    public function __construct(Connection|PDO $db)
    {
        $this->db = $db instanceof Connection ? $db : new PdoConnection($db, Dialects::active());
    }

    /** List stored maps (metadata only — never the full content). */
    public function all(): array
    {
        // `release` is a reserved word in MySQL (RELEASE SAVEPOINT); quoted so
        // it parses on every engine (a harmless no-op quote on SQLite/Postgres).
        $releaseColumn = Dialects::active()->quoteIdentifier('release');
        return $this->db->selectAll(
            "SELECT id,{$releaseColumn},file,length(content) size,created_at FROM source_maps
             ORDER BY created_at DESC, {$releaseColumn}, file"
        );
    }

    /**
     * Store (or replace) a map for a release + file. `file` may be a full URL;
     * only its basename is kept, since that is how stack frames are matched.
     *
     * @param array<string,mixed> $input
     */
    public function upload(array $input): array
    {
        $release = trim((string) ($input['release'] ?? ''));
        $file = $this->basename(trim((string) ($input['file'] ?? $input['url'] ?? '')));
        $content = $input['map'] ?? $input['content'] ?? '';
        if (is_array($content)) {
            $content = json_encode($content, JSON_UNESCAPED_SLASHES);
        }
        $content = (string) $content;

        if ($release === '' || strlen($release) > 120) {
            throw new InvalidArgumentException('A release (1–120 chars) is required.');
        }
        if ($file === '') {
            throw new InvalidArgumentException('A file name (or URL) is required.');
        }
        if ($content === '') {
            throw new InvalidArgumentException('The source map content is required.');
        }
        if (strlen($content) > self::MAX_BYTES) {
            throw new InvalidArgumentException('The source map exceeds the maximum size.');
        }
        $decoded = json_decode($content, true);
        if (!is_array($decoded) || !isset($decoded['mappings'])) {
            throw new InvalidArgumentException('The content is not a valid Source Map v3 document.');
        }

        $releaseColumn = Dialects::active()->quoteIdentifier('release');
        $this->db->execute(Dialects::active()->upsert(
            'source_maps',
            "{$releaseColumn},file,content",
            '?,?,?',
            [$releaseColumn, 'file'],
            ['content', 'created_at=CURRENT_TIMESTAMP'],
        ), [$release, $file, $content]);

        return ['release' => $release, 'file' => $file, 'size' => strlen($content), 'stored' => true];
    }

    public function delete(int $id): void
    {
        if ($this->db->selectValue('SELECT 1 FROM source_maps WHERE id=?', [$id]) === null) {
            throw new \LogLens\Domain\NotFoundException('Source map not found.');
        }
        $this->db->execute('DELETE FROM source_maps WHERE id=?', [$id]);
    }

    private function basename(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : $url;
        return basename($path);
    }
}
