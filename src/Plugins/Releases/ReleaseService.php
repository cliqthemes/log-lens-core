<?php
declare(strict_types=1);

namespace LogLens\Plugins\Releases;

use LogLens\Storage\Dialects;

use InvalidArgumentException;
use LogLens\Domain\NotFoundException;
use LogLens\Storage\Connection;
use LogLens\Storage\PdoConnection;
use LogLens\Storage\UniqueViolation;
use PDO;

/**
 * Release & deploy tracking: record deploys, attribute issue occurrences to the
 * release they arrived in, and turn application source frames into links to the
 * code host at the right ref.
 *
 * Release attribution comes for free: occurrences carry a `release` (set by
 * pushed events or connectors that supply one), so per-issue "releases affected"
 * and per-deploy issue counts are simple aggregate queries.
 */
final class ReleaseService
{
    private const SETTING_KEY = 'releases.settings';

    private readonly Connection $db;

    public function __construct(Connection|PDO $db)
    {
        $this->db = $db instanceof Connection ? $db : new PdoConnection($db, Dialects::active());
    }

    // ----- Deploys --------------------------------------------------------

    /** List deploys, newest first, each with the count of issues seen in it. */
    public function deploys(int $limit = 50): array
    {
        // `release` is a reserved word in MySQL (RELEASE SAVEPOINT); quoted so
        // it parses on every engine (a harmless no-op quote on SQLite/Postgres).
        $release = Dialects::active()->quoteIdentifier('release');
        return $this->db->selectAll(
            "SELECT r.*,
                (SELECT COUNT(DISTINCT o.group_id) FROM occurrences o WHERE o.{$release} = r.version) issue_count
             FROM releases r ORDER BY r.deployed_at DESC, r.id DESC LIMIT ?",
            [max(1, min(200, $limit))],
        );
    }

    public function createDeploy(array $input): array
    {
        $version = trim((string) ($input['version'] ?? ''));
        if ($version === '' || strlen($version) > 120) {
            throw new InvalidArgumentException('A version (1–120 chars) is required.');
        }
        $environment = trim((string) ($input['environment'] ?? 'production')) ?: 'production';
        $ref = trim((string) ($input['ref'] ?? '')) ?: null;
        $notes = trim((string) ($input['notes'] ?? '')) ?: null;
        $deployedAt = $this->timestamp($input['deployed_at'] ?? null);

        try {
            $id = $this->db->insert(
                'INSERT INTO releases(version,environment,ref,notes,deployed_at) VALUES(?,?,?,?,?)',
                [$version, $environment, $ref, $notes, $deployedAt],
            );
        } catch (\PDOException $exception) {
            if (UniqueViolation::matches($exception)) {
                throw new InvalidArgumentException("Release {$version} already exists for {$environment}.");
            }
            throw $exception;
        }
        return $this->deploy($id);
    }

    public function deleteDeploy(int $id): void
    {
        $this->deploy($id);
        $this->db->execute('DELETE FROM releases WHERE id=?', [$id]);
    }

    /** Deploy markers for charts: version + day, within a recent window. */
    public function markers(int $days = 30): array
    {
        $since = Dialects::active()->now(-max(1, $days) * 86400);
        return $this->db->selectAll(
            "SELECT version,environment,ref,deployed_at,substr(deployed_at,1,10) AS day
             FROM releases WHERE deployed_at >= {$since} ORDER BY deployed_at"
        );
    }

    // ----- Per-issue attribution -----------------------------------------

    /** Releases that a given issue has appeared in, plus a source link. */
    public function forGroup(int $groupId): array
    {
        // selectOne(), not selectValue(): source_frame is a nullable column,
        // so a legitimately-null value must not be confused with "no row" —
        // selectValue() collapses both to null (a real gap the storage-seam
        // migration surfaced rather than introduced).
        $group = $this->db->selectOne('SELECT source_frame FROM error_groups WHERE id=?', [$groupId]);
        if ($group === null) {
            throw new NotFoundException('Issue not found.');
        }
        $sourceFrame = $group['source_frame'];

        $release = Dialects::active()->quoteIdentifier('release');
        $releases = $this->db->selectAll(
            "SELECT o.{$release}, COUNT(*) occurrences, MIN(o.occurred_at) first_seen, MAX(o.occurred_at) last_seen
             FROM occurrences o WHERE o.group_id=? AND o.{$release} IS NOT NULL AND o.{$release}<>''
             GROUP BY o.{$release} ORDER BY last_seen DESC",
            [$groupId],
        );

        return [
            'releases' => $releases,
            'first_release' => $releases === [] ? null : end($releases)['release'],
            'last_release' => $releases === [] ? null : $releases[0]['release'],
            'source_link' => $this->sourceLink((string) ($sourceFrame ?: '')),
        ];
    }

    // ----- Settings (code host link) --------------------------------------

    public function settings(): array
    {
        $raw = $this->rawSettings();
        return [
            'repo_url_template' => $raw['repo_url_template'],
            'default_ref' => $raw['default_ref'],
            'configured' => $raw['repo_url_template'] !== '',
        ];
    }

    public function updateSettings(array $input): array
    {
        $raw = $this->rawSettings();
        if (array_key_exists('repo_url_template', $input)) {
            $template = trim((string) $input['repo_url_template']);
            if ($template !== '' && !preg_match('#^https?://#i', $template)) {
                throw new InvalidArgumentException('The repository URL template must be an http(s) URL.');
            }
            if (strlen($template) > 500) {
                throw new InvalidArgumentException('The repository URL template is too long.');
            }
            $raw['repo_url_template'] = $template;
        }
        if (array_key_exists('default_ref', $input)) {
            $raw['default_ref'] = substr(trim((string) $input['default_ref']), 0, 120);
        }
        $this->db->execute(Dialects::active()->keyValueUpsert(), [self::SETTING_KEY, json_encode($raw, JSON_THROW_ON_ERROR)]);
        return $this->settings();
    }

    /**
     * Build a code-host link for an application source frame such as
     * "app/Services/Foo.php:42" using the configured template. The template may
     * contain {ref}, {path}, and {line} placeholders.
     */
    public function sourceLink(string $sourceFrame): ?string
    {
        $raw = $this->rawSettings();
        $template = $raw['repo_url_template'];
        if ($template === '' || $sourceFrame === '') {
            return null;
        }
        $line = '';
        $path = $sourceFrame;
        if (preg_match('/^(.*?):(\d+)$/', $sourceFrame, $match) === 1) {
            $path = $match[1];
            $line = $match[2];
        }
        $path = ltrim($path, '/');
        $ref = $raw['default_ref'] !== '' ? $raw['default_ref'] : 'main';
        return strtr($template, [
            '{ref}' => rawurlencode($ref),
            '{path}' => implode('/', array_map('rawurlencode', explode('/', $path))),
            '{line}' => $line,
        ]);
    }

    // ----- Helpers --------------------------------------------------------

    private function rawSettings(): array
    {
        $stored = $this->db->selectValue(Dialects::active()->keyValueLookup(), [self::SETTING_KEY]);
        $decoded = is_string($stored) ? json_decode($stored, true) : null;
        $data = is_array($decoded) ? $decoded : [];
        return [
            'repo_url_template' => trim((string) ($data['repo_url_template'] ?? '')),
            'default_ref' => trim((string) ($data['default_ref'] ?? '')),
        ];
    }

    private function deploy(int $id): array
    {
        $row = $this->db->selectOne('SELECT * FROM releases WHERE id=?', [$id]);
        if ($row === null) {
            throw new NotFoundException('Release not found.');
        }
        return $row;
    }

    private function timestamp(mixed $value): string
    {
        if (is_string($value) && trim($value) !== '') {
            $parsed = strtotime($value);
            if ($parsed !== false) {
                return gmdate('Y-m-d H:i:s', $parsed);
            }
        }
        return gmdate('Y-m-d H:i:s');
    }
}
