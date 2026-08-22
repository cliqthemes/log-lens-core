<?php
declare(strict_types=1);

namespace LogLens\Services;

use LogLens\Repositories\IssueRepository;
use LogLens\Storage\Connection;
use LogLens\Storage\Dialects;
use LogLens\Storage\PdoConnection;
use PDO;

final class WorkflowService
{
    /** @deprecated Use IssueRepository::STATUSES — kept for existing references. */
    public const STATUSES = IssueRepository::STATUSES;

    private readonly Connection $db;
    private readonly IssueRepository $issues;

    public function __construct(Connection|PDO $db)
    {
        $this->db = $db instanceof Connection ? $db : new PdoConnection($db, Dialects::active());
        // Shares this same Connection instance (not the raw $db argument) so a
        // nested transaction() call below joins this one rather than tracking
        // its own separate depth counter (Connection::transaction() is
        // re-entrant only across calls sharing one instance).
        $this->issues = new IssueRepository($this->db);
    }

    public function change(int $groupId, string $status, string $note = '', ?string $actor = null): array
    {
        $result = $this->db->transaction(fn (Connection $db): array => $this->issues->changeStatus($groupId, $status, $note, $actor));
        return ['id' => $groupId, 'from_status' => $result['from'], 'status' => $result['to'], 'note' => trim($note)];
    }

    /** Assign (or, with both null, unassign) an issue to a person (C-2). */
    public function assign(int $groupId, ?string $assignedToId, ?string $assignedToLabel): array
    {
        return ['id' => $groupId] + $this->issues->assign($groupId, $assignedToId, $assignedToLabel);
    }
}
