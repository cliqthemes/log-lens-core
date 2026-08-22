<?php
declare(strict_types=1);

namespace LogLens\Services;

use InvalidArgumentException;
use LogLens\Domain\IssueOrigin;
use LogLens\Storage\Connection;
use LogLens\Storage\Dialects;
use LogLens\Storage\PdoConnection;
use PDO;

final class ManualIssueService
{
    public const KINDS = ['issue', 'bug', 'feature_request', 'task'];

    private readonly Connection $db;

    public function __construct(Connection|PDO $db)
    {
        $this->db = $db instanceof Connection ? $db : new PdoConnection($db, Dialects::active());
    }

    public function create(array $input): int
    {
        $title = trim((string) ($input['title'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $kind = strtolower(trim((string) ($input['kind'] ?? 'issue')));
        $severity = strtoupper(trim((string) ($input['severity'] ?? 'INFO')));
        $status = strtolower(trim((string) ($input['status'] ?? 'open')));
        $sourceFrame = trim((string) ($input['source_frame'] ?? ''));
        $environment = trim((string) ($input['environment'] ?? 'local'));
        $note = trim((string) ($input['note'] ?? ''));
        $tagIds = $this->tagIds($input['tag_ids'] ?? []);
        $moduleId = $this->moduleId($input['module_id'] ?? null);

        if ($title === '') {
            throw new InvalidArgumentException('Issue title is required.');
        }
        if (strlen($title) > 500) {
            throw new InvalidArgumentException('Issue title cannot exceed 500 characters.');
        }
        if (strlen($description) > 120_000) {
            throw new InvalidArgumentException('Issue description cannot exceed 120,000 characters.');
        }
        if (!in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('Unsupported manual issue kind.');
        }
        if (!in_array($severity, IngestionSettingsService::LEVELS, true)) {
            throw new InvalidArgumentException('Unsupported issue severity.');
        }
        if (!in_array($status, WorkflowService::STATUSES, true)) {
            throw new InvalidArgumentException('Unsupported issue status.');
        }
        if (strlen($sourceFrame) > 1_000) {
            throw new InvalidArgumentException('Related source cannot exceed 1,000 characters.');
        }
        if ($environment === '' || strlen($environment) > 100) {
            throw new InvalidArgumentException('Environment must contain 1–100 characters.');
        }
        if (strlen($note) > 10_000) {
            throw new InvalidArgumentException('Creation note cannot exceed 10,000 characters.');
        }

        $createdAt = gmdate('Y-m-d H:i:s');
        $fingerprint = hash('sha256', 'manual|' . bin2hex(random_bytes(32)));
        $context = json_encode(
            ['origin' => IssueOrigin::MANUAL, 'kind' => $kind, 'module_id' => $moduleId],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        $historyNote = $note !== '' ? $note : 'Created manually as ' . str_replace('_', ' ', $kind) . '.';

        $groupId = $this->db->transaction(function (Connection $db) use (
            $fingerprint, $severity, $environment, $title, $sourceFrame, $createdAt,
            $description, $context, $status, $kind, $moduleId, $historyNote, $tagIds,
        ): int {
            $groupId = $db->insert(
                'INSERT INTO error_groups(
                    fingerprint,severity,environment,title,source_frame,count,first_seen,last_seen,
                    sample_message,sample_context,created_at,status,status_updated_at,
                    log_type,channel,origin,kind,module_id
                 ) VALUES(?,?,?,?,?,0,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $fingerprint,
                    $severity,
                    $environment,
                    $title,
                    $sourceFrame !== '' ? $sourceFrame : null,
                    $createdAt,
                    $createdAt,
                    $description !== '' ? $description : $title,
                    $context,
                    $createdAt,
                    $status,
                    $createdAt,
                    'manual',            // log_type
                    'manual',            // channel
                    IssueOrigin::MANUAL, // origin
                    $kind,
                    $moduleId,
                ],
            );
            $db->execute(
                'INSERT INTO issue_status_history(group_id,from_status,to_status,note) VALUES(?,NULL,?,?)',
                [$groupId, $status, $historyNote],
            );
            foreach ($tagIds as $tagId) {
                $db->execute("INSERT INTO error_group_tags(group_id,tag_id,source) VALUES(?,?,'manual')", [$groupId, $tagId]);
            }
            return $groupId;
        });

        (new TagService($this->db))->applyRules();
        return $groupId;
    }

    /** @return list<int> */
    private function tagIds(mixed $value): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('tag_ids must be an array.');
        }
        $tagIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $value),
            static fn (int $id): bool => $id > 0,
        )));
        foreach ($tagIds as $tagId) {
            if ($this->db->selectValue('SELECT 1 FROM tags WHERE id=?', [$tagId]) === null) {
                throw new InvalidArgumentException("Tag {$tagId} does not exist.");
            }
        }
        return $tagIds;
    }

    private function moduleId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $moduleId = (int) $value;
        if ($moduleId < 1) {
            throw new InvalidArgumentException('module_id must be a positive integer or null.');
        }
        if ($this->db->selectValue('SELECT 1 FROM modules WHERE id=?', [$moduleId]) === null) {
            throw new InvalidArgumentException("Module {$moduleId} does not exist in this application.");
        }
        return $moduleId;
    }
}
