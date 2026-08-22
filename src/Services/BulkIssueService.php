<?php
declare(strict_types=1);

namespace LogLens\Services;

use LogLens\Storage\Connection;
use LogLens\Storage\Dialects;
use LogLens\Storage\PdoConnection;

use InvalidArgumentException;
use LogLens\Repositories\IssueRepository;
use PDO;

final class BulkIssueService
{
    private readonly Connection $db;
    private readonly IssueRepository $issues;

    public function __construct(Connection|PDO $db)
    {
        $this->db = $db instanceof Connection ? $db : new PdoConnection($db, Dialects::active());
        // Shared instance so the transaction() below and IssueRepository's own
        // calls join one re-entrant transaction rather than tracking separate
        // depth counters — see WorkflowService for the same note.
        $this->issues = new IssueRepository($this->db);
    }

    /** @return array<string,mixed> */
    public function execute(array $input, ?string $actor = null): array
    {
        $ids = $this->ids($input['ids'] ?? []);
        $action = strtolower(trim((string) ($input['action'] ?? '')));
        return match ($action) {
            'status' => $this->status($ids, (string) ($input['status'] ?? ''), (string) ($input['note'] ?? ''), $actor),
            'add_tag', 'remove_tag' => $this->tag($ids, (int) ($input['tag_id'] ?? 0), $action === 'add_tag'),
            default => throw new InvalidArgumentException('action must be status, add_tag, or remove_tag.'),
        };
    }

    /** @param list<int> $ids */
    private function status(array $ids, string $status, string $note, ?string $actor = null): array
    {
        $status = strtolower(trim($status));
        $note = trim($note);
        if (!in_array($status, IssueRepository::STATUSES, true)) {
            throw new InvalidArgumentException('Unsupported issue status.');
        }
        if (strlen($note) > 10_000) {
            throw new InvalidArgumentException('Status note cannot exceed 10,000 characters.');
        }
        // One transaction across every issue; each change goes through the Issue
        // gateway so status + attributed history stay consistent (H-6).
        $this->db->transaction(function () use ($ids, $status, $note, $actor): void {
            foreach ($ids as $id) {
                if (!$this->issues->exists($id)) {
                    throw new InvalidArgumentException("Issue {$id} does not exist in this application.");
                }
                $this->issues->changeStatus($id, $status, $note, $actor);
            }
        });
        return ['action' => 'status', 'status' => $status, 'note' => $note, 'updated' => count($ids), 'ids' => $ids];
    }

    /** @param list<int> $ids */
    private function tag(array $ids, int $tagId, bool $add): array
    {
        if ($tagId < 1) {
            throw new InvalidArgumentException('tag_id is required.');
        }
        if ($this->db->selectValue('SELECT 1 FROM tags WHERE id=?', [$tagId]) === null) {
            throw new InvalidArgumentException("Tag {$tagId} does not exist in this application.");
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $existingCount = (int) $this->db->selectValue("SELECT COUNT(*) FROM error_groups WHERE id IN ({$placeholders})", $ids);
        if ($existingCount !== count($ids)) {
            throw new InvalidArgumentException('One or more issues do not exist in this application.');
        }
        $sql = $add
            ? Dialects::active()->upsert(
                'error_group_tags',
                'group_id,tag_id,source',
                "?,?,'manual'",
                ['group_id', 'tag_id'],
                ["source='manual'"],
            )
            : 'DELETE FROM error_group_tags WHERE group_id=? AND tag_id=?';
        $this->db->transaction(function (Connection $db) use ($ids, $tagId, $sql): void {
            foreach ($ids as $id) {
                $db->execute($sql, [$id, $tagId]);
            }
        });
        return ['action' => $add ? 'add_tag' : 'remove_tag', 'tag_id' => $tagId, 'updated' => count($ids), 'ids' => $ids];
    }

    /** @return list<int> */
    private function ids(mixed $value): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('ids must be an array.');
        }
        $ids = array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $value)));
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
        if ($ids === [] || count($ids) > 200) {
            throw new InvalidArgumentException('Select between 1 and 200 issue ids.');
        }
        return $ids;
    }
}
