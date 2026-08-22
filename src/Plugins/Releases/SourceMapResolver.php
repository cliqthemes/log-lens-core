<?php
declare(strict_types=1);

namespace LogLens\Plugins\Releases;

use LogLens\Storage\Connection;
use LogLens\Storage\Dialects;
use LogLens\Storage\PdoConnection;
use PDO;

/**
 * Resolves minified browser stack traces to original source locations using
 * Source Map v3 files uploaded per release. Turns a frame like
 * `at s (https://app/assets/app.4f2a.js:1:88213)` into
 * `at s (Checkout.tsx:88:12)`.
 *
 * Implements just enough of the spec — base64-VLQ decoding of the `mappings`
 * field and a nearest-segment lookup — to avoid any external dependency.
 */
final class SourceMapResolver
{
    private const B64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

    /** @var array<string,array<int,list<array<string,int>>>|null> decoded-mapping cache per file */
    private array $cache = [];

    private readonly Connection $db;

    public function __construct(Connection|PDO $db)
    {
        $this->db = $db instanceof Connection ? $db : new PdoConnection($db, Dialects::active());
    }

    /**
     * Rewrite every resolvable `file.js:line:col` frame in a stack against the
     * maps uploaded for a release. Frames without a map are left untouched.
     */
    public function resolveStack(string $stack, string $release): string
    {
        if ($stack === '' || $release === '') {
            return $stack;
        }
        return (string) preg_replace_callback(
            '#((?:https?://|/)?[^\s():]+\.(?:js|mjs|cjs)):(\d+):(\d+)#',
            function (array $match) use ($release): string {
                $original = $this->resolveFrame($release, $this->basename($match[1]), (int) $match[2], (int) $match[3]);
                if ($original === null) {
                    return $match[0];
                }
                $location = $original['source'] . ':' . $original['line'] . ':' . $original['column'];
                return $original['name'] !== null && $original['name'] !== ''
                    ? $location . ' (' . $original['name'] . ')'
                    : $location;
            },
            $stack,
        );
    }

    /**
     * Resolve a single generated position (1-based line & column, as browsers
     * report) to the original source. Public for direct testing.
     *
     * @return array{source:?string,line:int,column:int,name:?string}|null
     */
    public function resolveFrame(string $release, string $file, int $genLine, int $genColumn): ?array
    {
        $map = $this->map($release, $file);
        if ($map === null) {
            return null;
        }
        $lineIndex = $genLine - 1;
        $column = max(0, $genColumn - 1);
        if ($lineIndex < 0 || !isset($map['mappings'][$lineIndex])) {
            return null;
        }
        $best = null;
        foreach ($map['mappings'][$lineIndex] as $segment) {
            if (!isset($segment['src'])) {
                continue;
            }
            if ($segment['genCol'] <= $column) {
                $best = $segment;
            } else {
                break;
            }
        }
        if ($best === null) {
            return null;
        }
        return [
            'source' => $map['sources'][$best['src']] ?? null,
            'line' => $best['line'] + 1,
            'column' => $best['col'] + 1,
            'name' => isset($best['name']) ? ($map['names'][$best['name']] ?? null) : null,
        ];
    }

    /**
     * Load and decode a stored map. Returns ['sources','names','mappings'=>
     * per-line segment lists] or null when no usable map exists.
     *
     * @return array{sources:list<string>,names:list<string>,mappings:array<int,list<array<string,int>>>}|null
     */
    private function map(string $release, string $file): ?array
    {
        $key = $release . '|' . $file;
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }
        // `release` is a reserved word in MySQL (RELEASE SAVEPOINT); quoted so
        // it parses on every engine (a harmless no-op quote on SQLite/Postgres).
        $releaseColumn = Dialects::active()->quoteIdentifier('release');
        $content = $this->db->selectValue("SELECT content FROM source_maps WHERE {$releaseColumn}=? AND file=?", [$release, $file]);
        $decoded = is_string($content) ? json_decode($content, true) : null;
        if (!is_array($decoded) || !isset($decoded['mappings']) || !is_string($decoded['mappings'])) {
            return $this->cache[$key] = null;
        }
        return $this->cache[$key] = [
            'sources' => array_map('strval', $decoded['sources'] ?? []),
            'names' => array_map('strval', $decoded['names'] ?? []),
            'mappings' => $this->decodeMappings($decoded['mappings']),
        ];
    }

    /** @return array<int,list<array<string,int>>> per-line lists of absolute segments */
    private function decodeMappings(string $mappings): array
    {
        $lines = [];
        // source index, original line/column and name index accumulate across
        // the whole file; only the generated column resets each line.
        $srcIndex = 0;
        $origLine = 0;
        $origColumn = 0;
        $nameIndex = 0;
        foreach (explode(';', $mappings) as $lineText) {
            $genColumn = 0;
            $segments = [];
            if ($lineText !== '') {
                foreach (explode(',', $lineText) as $segmentText) {
                    if ($segmentText === '') {
                        continue;
                    }
                    $pos = 0;
                    $genColumn += $this->decodeVlq($segmentText, $pos);
                    $segment = ['genCol' => $genColumn];
                    if ($pos < strlen($segmentText)) {
                        $srcIndex += $this->decodeVlq($segmentText, $pos);
                        $origLine += $this->decodeVlq($segmentText, $pos);
                        $origColumn += $this->decodeVlq($segmentText, $pos);
                        $segment['src'] = $srcIndex;
                        $segment['line'] = $origLine;
                        $segment['col'] = $origColumn;
                        if ($pos < strlen($segmentText)) {
                            $nameIndex += $this->decodeVlq($segmentText, $pos);
                            $segment['name'] = $nameIndex;
                        }
                    }
                    $segments[] = $segment;
                }
            }
            $lines[] = $segments;
        }
        return $lines;
    }

    private function decodeVlq(string $segment, int &$pos): int
    {
        $result = 0;
        $shift = 0;
        $continuation = 0;
        do {
            if ($pos >= strlen($segment)) {
                break;
            }
            $digit = strpos(self::B64, $segment[$pos]);
            $pos++;
            if ($digit === false) {
                break;
            }
            $continuation = $digit & 0x20;
            $result += ($digit & 0x1f) << $shift;
            $shift += 5;
        } while ($continuation);
        $value = $result >> 1;
        return ($result & 1) ? -$value : $value;
    }

    private function basename(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : $url;
        return basename($path);
    }
}
