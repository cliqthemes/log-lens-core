<?php
declare(strict_types=1);

namespace LogLens\Repositories;

use InvalidArgumentException;
use LogLens\Config;
use LogLens\Domain\IssueOrigin;
use LogLens\Domain\LogEvent;
use LogLens\Domain\NotFoundException;
use LogLens\Services\EventAnalyzer;
use LogLens\Storage\Connection;
use LogLens\Storage\Dialect;
use LogLens\Storage\Dialects;
use LogLens\Storage\PdoConnection;
use PDO;

/**
 * The write/lifecycle gateway for the shared `error_groups` (Issue) table. All
 * origin-sensitive mutations — issue creation from occurrences, status changes,
 * status-history, and occurrence-backed aggregate pruning — go through here so
 * the {@see IssueOrigin} invariants live in exactly one place (H-6). Read-model
 * queries (list/detail/summary) live in {@see IssueQueryRepository}.
 */
final class IssueRepository
{
    /** The workflow statuses an issue may hold. */
    public const STATUSES = ['open', 'in_progress', 'fixed', 'wont_fix', 'reoccurred'];

    private readonly Connection $db;
    private readonly int $messageLimit;
    private readonly int $contextPreviewLimit;
    private readonly Dialect $dialect;

    public function __construct(
        Connection|PDO $db,
        private readonly EventAnalyzer $analyzer = new EventAnalyzer(),
        ?Dialect $dialect = null,
    ) {
        $this->dialect = $dialect ?? Dialects::active();
        $this->db = $db instanceof Connection ? $db : new PdoConnection($db, $this->dialect);
        $this->messageLimit = Config::int('ingestion.message_limit', 120_000);
        $this->contextPreviewLimit = Config::int('ingestion.context_preview_limit', 12_000);
    }

    public function persist(
        int $fileId,
        LogEvent $event,
        ?int $moduleId = null,
        ?string $eventHash = null,
        ?string $fingerprintOverride = null,
    ): ?string
    {
        $analysis = $this->analyzer->analyze($event);
        // A caller-supplied fingerprint (e.g. from an SDK's grouping key) takes
        // precedence over the derived one so senders can control grouping.
        $fingerprint = $fingerprintOverride !== null && $fingerprintOverride !== ''
            ? hash('sha256', 'custom|' . ($moduleId ?? 'unassigned') . '|' . $fingerprintOverride)
            : hash('sha256', implode('|', [
                $moduleId ?? 'unassigned',
                $event->logType,
                $event->severity,
                $analysis->exceptionClass,
                $this->analyzer->normalize($analysis->title),
                $this->analyzer->normalize($analysis->sourceFrame),
            ]));
        $exact = hash('sha256', $fingerprint . '|' . $this->analyzer->normalize($analysis->stack));

        $this->db->execute($this->dialect->upsert(
            'error_groups',
            'fingerprint,severity,environment,title,exception_class,source_frame,count,'
                . 'first_seen,last_seen,sample_message,sample_stack,sample_context,log_type,channel,module_id',
            '?,?,?,?,?,?,0,?,?,?,?,?,?,?,?',
            ['fingerprint'],
            ['severity', 'environment', 'title', 'exception_class', 'source_frame', 'sample_message',
                'sample_stack', 'sample_context', 'log_type', 'channel', 'module_id'],
        ), [
            $fingerprint,
            $event->severity,
            $event->environment,
            $analysis->title,
            $analysis->exceptionClass ?: null,
            $analysis->sourceFrame ?: null,
            $event->occurredAt,
            $event->occurredAt,
            substr($event->body, 0, $this->messageLimit),
            substr($analysis->stack, 0, $this->messageLimit),
            substr($analysis->context, 0, $this->messageLimit),
            $event->logType,
            $event->channel,
            $moduleId,
        ]);

        $groupId = (int) $this->db->selectValue('SELECT id FROM error_groups WHERE fingerprint=?', [$fingerprint]);
        // `release` is a reserved word in MySQL (RELEASE SAVEPOINT); quoted so
        // it parses on every engine (a harmless no-op quote on SQLite/Postgres).
        $occurrenceAffected = $this->db->execute($this->dialect->insertIgnore(
            'occurrences',
            'group_id,source_file_id,exact_fingerprint,occurred_at,severity,environment,'
                . 'byte_start,byte_end,context_preview,event_hash,' . $this->dialect->quoteIdentifier('release'),
            '?,?,?,?,?,?,?,?,?,?,?',
        ), [
            $groupId,
            $fileId,
            $exact,
            $event->occurredAt,
            $event->severity,
            $event->environment,
            $event->byteStart,
            $event->byteEnd,
            substr($analysis->context, 0, $this->contextPreviewLimit),
            $eventHash,
            $event->release,
        ]);

        if ($occurrenceAffected === 0) {
            return null;
        }
        $this->recordOccurrence($groupId, $event->occurredAt);
        return $fingerprint;
    }

    public function clearSource(int $fileId): void
    {
        $this->db->execute('DELETE FROM occurrences WHERE source_file_id=?', [$fileId]);
    }

    /** Whether an issue exists. */
    public function exists(int $id): bool
    {
        return $this->db->selectValue('SELECT 1 FROM error_groups WHERE id=?', [$id]) !== null;
    }

    /** The current workflow status, or null when the issue does not exist. */
    public function statusOf(int $id): ?string
    {
        $status = $this->db->selectValue('SELECT status FROM error_groups WHERE id=?', [$id]);
        return $status === null ? null : (string) $status;
    }

    /** The issue's origin (see {@see IssueOrigin}), or null when it does not exist. */
    public function originOf(int $id): ?string
    {
        $origin = $this->db->selectValue('SELECT origin FROM error_groups WHERE id=?', [$id]);
        return $origin === null ? null : (string) $origin;
    }

    /** Count issues of a given origin. */
    public function countByOrigin(string $origin): int
    {
        return (int) $this->db->selectValue('SELECT COUNT(*) FROM error_groups WHERE origin=?', [$origin]);
    }

    /**
     * Change an issue's workflow status and append an attributed history row.
     * Does NOT manage a transaction so callers can batch (e.g. bulk updates);
     * wrap the call when atomicity across several issues is required.
     *
     * @return array{from:string,to:string}
     */
    public function changeStatus(int $id, string $status, string $note = '', ?string $actor = null): array
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Unsupported issue status.');
        }
        $from = $this->statusOf($id);
        if ($from === null) {
            throw new NotFoundException('Issue not found.');
        }
        $this->db->execute('UPDATE error_groups SET status=?,status_updated_at=CURRENT_TIMESTAMP WHERE id=?', [$status, $id]);
        $this->recordStatusChange($id, $from, $status, trim($note) !== '' ? trim($note) : null, $actor);
        return ['from' => $from, 'to' => $status];
    }

    /** Append one immutable status-history row (create/import-seeded/system changes). */
    public function recordStatusChange(int $id, ?string $from, string $to, ?string $note, ?string $actor = null): void
    {
        $this->db->execute(
            'INSERT INTO issue_status_history(group_id,from_status,to_status,note,actor) VALUES(?,?,?,?,?)',
            [$id, $from, $to, $note, $actor],
        );
    }

    /**
     * Assign (or, with both null, unassign) an issue to a person. Log Lens has
     * no user table of its own — a Laravel host owns its users, standalone has
     * only the local owner (see Identity\Actor) — so $assignedToId/$assignedToLabel
     * are an opaque id/label pair supplied by the assigning system, not a
     * foreign key. Distinct from `assignee`, a read-only Linear-sync mirror
     * never written here.
     *
     * @return array{assigned_to_id:?string,assigned_to_label:?string}
     */
    public function assign(int $id, ?string $assignedToId, ?string $assignedToLabel): array
    {
        if (!$this->exists($id)) {
            throw new NotFoundException('Issue not found.');
        }
        $this->db->execute('UPDATE error_groups SET assigned_to_id=?,assigned_to_label=? WHERE id=?', [$assignedToId, $assignedToLabel, $id]);
        return ['assigned_to_id' => $assignedToId, 'assigned_to_label' => $assignedToLabel];
    }

    public function refreshAggregates(): void
    {
        // Only aggregate rows sourced from occurrences (see IssueOrigin). Externally
        // sourced issues — manual and linear — have no occurrences by design and
        // must never be recomputed to count=0 or pruned here.
        //
        // Two separate execute() calls rather than one semicolon-joined
        // PDO::exec() string (the Connection seam has no raw multi-statement
        // exec — {@see \LogLens\Storage\Connection}) — more portable too:
        // a single exec() with several statements relies on the PDO driver
        // accepting multi-statement strings, which MySQL only does with
        // PDO::MYSQL_ATTR_MULTI_STATEMENTS (not set here) and which some
        // hardened proxies/poolers reject outright.
        $occurrenceBacked = IssueOrigin::occurrenceBackedSql();
        $this->db->execute(
            "DELETE FROM error_groups WHERE {$occurrenceBacked} AND NOT EXISTS (
                SELECT 1 FROM occurrences WHERE group_id=error_groups.id
             )"
        );
        $this->db->execute(
            "UPDATE error_groups SET
                count=(SELECT COUNT(*) FROM occurrences WHERE group_id=error_groups.id),
                first_seen=(SELECT MIN(occurred_at) FROM occurrences WHERE group_id=error_groups.id),
                last_seen=(SELECT MAX(occurred_at) FROM occurrences WHERE group_id=error_groups.id)
             WHERE {$occurrenceBacked}"
        );
    }

    private function recordOccurrence(int $groupId, string $occurredAt): void
    {
        // MIN(a,b)/MAX(a,b) (the scalar, not aggregate, form) is a SQLite-only
        // overload — Postgres and MySQL both reject a multi-argument MIN/MAX
        // outright (aggregate-only there) and need LEAST()/GREATEST() instead.
        // Confirmed live: this previously broke every occurrence recorded on
        // either engine — i.e. all of file-based and HTTP ingest.
        $this->db->execute(
            "UPDATE error_groups
             SET count=count+1,first_seen={$this->dialect->least('first_seen', '?')},last_seen={$this->dialect->greatest('last_seen', '?')}
             WHERE id=?",
            [$occurredAt, $occurredAt, $groupId],
        );

        $reoccurredAffected = $this->db->execute(
            "UPDATE error_groups
             SET status='reoccurred',status_updated_at=CURRENT_TIMESTAMP
             WHERE id=? AND status='fixed'",
            [$groupId],
        );
        if ($reoccurredAffected > 0) {
            $this->recordStatusChange($groupId, 'fixed', 'reoccurred', 'A new occurrence was detected during log import.');
        }
    }
}
