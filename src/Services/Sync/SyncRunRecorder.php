<?php
declare(strict_types=1);

namespace LogLens\Services\Sync;

use LogLens\Config;
use LogLens\Domain\NotFoundException;
use LogLens\Storage\Connection;
use LogLens\Storage\Dialects;
use RuntimeException;

/**
 * Every read and write of the `connector_sync_runs` table.
 *
 * A synchronization run is the audit trail *and* the live progress bar: the
 * dashboard polls this table while a run is in flight, and a scheduled drain
 * relies on it to find work and to recover from a worker that died mid-run.
 * Keeping all of that in one place means the invariants hold together — a
 * status only ever moves through queued → running → success/failed, every
 * progress write bumps `updated_at` (which is what makes the stale-run reaper
 * able to tell a slow run from a dead one), and free-text coming from an
 * exception is truncated once, here, rather than at each call site.
 */
final class SyncRunRecorder
{
    /** Longest message stored; the column is text but a stack trace is not an audit entry. */
    private const MESSAGE_LIMIT = 4_000;

    public function __construct(private readonly Connection $db)
    {
    }

    public function start(int $connectorId, ?string $expectedSnapshot = null): int
    {
        return $this->insertRun($connectorId, 'running', $expectedSnapshot);
    }

    public function enqueue(int $connectorId, ?string $expectedSnapshot = null): int
    {
        return $this->insertRun($connectorId, 'queued', $expectedSnapshot);
    }

    private function insertRun(int $connectorId, string $status, ?string $expectedSnapshot): int
    {
        return $this->db->insert(
            "INSERT INTO connector_sync_runs(connector_id,status,expected_snapshot,updated_at)
             VALUES(?,?,?,CURRENT_TIMESTAMP)",
            [$connectorId, $status, $expectedSnapshot],
        );
    }

    /**
     * Take ownership of a queued run, so two workers draining the same queue
     * cannot both pick it up. The UPDATE is the claim: it matches on the current
     * status, so exactly one worker sees an affected-row count of 1.
     *
     * @return array{connector_id:int,expected_snapshot:string|null}
     */
    public function claimQueued(int $runId): array
    {
        $run = $this->db->selectOne(
            "SELECT connector_id,expected_snapshot FROM connector_sync_runs WHERE id=? AND status='queued'",
            [$runId],
        );
        if ($run === null) {
            throw new NotFoundException('Queued connector synchronization run was not found.');
        }
        $claimed = $this->db->execute(
            "UPDATE connector_sync_runs SET status='running',updated_at=CURRENT_TIMESTAMP
             WHERE id=? AND status='queued'",
            [$runId],
        );
        if ($claimed !== 1) {
            throw new RuntimeException('Queued connector synchronization run was claimed by another worker.');
        }
        return [
            'connector_id' => (int) $run['connector_id'],
            'expected_snapshot' => $run['expected_snapshot'] === null ? null : (string) $run['expected_snapshot'],
        ];
    }

    /** Confirm a run this process already claimed is still the one it may write to. */
    public function activate(int $runId, int $connectorId): void
    {
        $claimed = $this->db->execute(
            "UPDATE connector_sync_runs SET status='running',updated_at=CURRENT_TIMESTAMP
             WHERE id=? AND connector_id=? AND status='running'",
            [$runId, $connectorId],
        );
        if ($claimed !== 1) {
            throw new RuntimeException('Connector synchronization run is no longer queued.');
        }
    }

    /**
     * Close out every other run for this connector. A run only reaches here
     * holding the connector's lock, so anything else still marked queued or
     * running belongs to a process that is gone.
     */
    public function supersedeOthers(int $runId, int $connectorId): void
    {
        $this->db->execute(
            "UPDATE connector_sync_runs SET status='failed',
             message='Synchronization was interrupted and superseded by another run.',
             current_file=NULL,updated_at=CURRENT_TIMESTAMP,finished_at=CURRENT_TIMESTAMP
             WHERE connector_id=? AND id<>? AND status IN ('queued','running')",
            [$connectorId, $runId],
        );
    }

    /** @param array<string,mixed> $result */
    public function finishSuccessful(int $runId, array $result): void
    {
        $this->db->execute(
            'UPDATE connector_sync_runs SET status=?,files_discovered=?,files_updated=?,
             files_completed=?,bytes_fetched=?,events_indexed=?,current_file=NULL,message=?,
             updated_at=CURRENT_TIMESTAMP,finished_at=CURRENT_TIMESTAMP WHERE id=?',
            [
                'success',
                (int) ($result['files_discovered'] ?? 0),
                (int) ($result['files_updated'] ?? 0),
                (int) ($result['files_discovered'] ?? 0),
                (int) ($result['bytes_fetched'] ?? 0),
                (int) ($result['events_indexed'] ?? 0),
                $this->truncate($result['message'] ?? null),
                $runId,
            ],
        );
    }

    public function finishFailed(int $runId, ?string $message): void
    {
        $this->db->execute(
            "UPDATE connector_sync_runs SET status='failed',message=?,current_file=NULL,
             updated_at=CURRENT_TIMESTAMP,finished_at=CURRENT_TIMESTAMP WHERE id=?",
            [$this->truncate($message), $runId],
        );
    }

    /** Fail a run that never started, so a rejected enqueue does not sit in the queue. */
    public function failQueued(int $runId, string $message): void
    {
        $this->db->execute(
            "UPDATE connector_sync_runs SET status='failed',message=?,updated_at=CURRENT_TIMESTAMP,
             finished_at=CURRENT_TIMESTAMP WHERE id=? AND status='queued'",
            [$this->truncate($message), $runId],
        );
    }

    /**
     * Recover runs abandoned by a crashed worker or a server restart. Progress
     * bumps `updated_at` on every fetched chunk, so a `running` run that has not
     * advanced within the timeout is provably a dead worker — mark it failed so
     * it never lingers as a perpetual "running" and the connector is freed for
     * the next scheduled drain. Returns the number of runs reaped.
     */
    public function reapStale(): int
    {
        $timeout = Config::int('sync.stale_run_timeout_seconds', 900, 30);
        $cutoff = Dialects::active()->now(-$timeout);
        return $this->db->execute(
            "UPDATE connector_sync_runs
             SET status='failed', current_file=NULL,
                 message='Synchronization worker stopped responding; marked interrupted after timeout.',
                 updated_at=CURRENT_TIMESTAMP, finished_at=CURRENT_TIMESTAMP
             WHERE status='running' AND updated_at < {$cutoff}"
        );
    }

    /** @return list<int> */
    public function queuedIds(?int $connectorId = null): array
    {
        $sql = $connectorId !== null
            ? "SELECT id FROM connector_sync_runs WHERE status='queued' AND connector_id=? ORDER BY started_at,id"
            : "SELECT id FROM connector_sync_runs WHERE status='queued' ORDER BY started_at,id";
        $rows = $this->db->selectAll($sql, $connectorId === null ? [] : [$connectorId]);
        return array_map('intval', array_column($rows, 'id'));
    }

    /** @return list<array<string,mixed>> */
    public function history(int $limit = 25): array
    {
        $limit = min(100, max(1, $limit));
        return $this->db->selectAll(
            'SELECT r.id,r.connector_id,r.status,r.files_discovered,r.files_completed,
                    r.files_updated,r.bytes_total,r.bytes_fetched,r.events_indexed,
                    r.current_file,r.message,r.started_at,r.updated_at,r.finished_at,
                    c.name connector_name,c.type connector_type
             FROM connector_sync_runs r JOIN connectors c ON c.id=r.connector_id
             ORDER BY r.started_at DESC,r.id DESC LIMIT ?',
            [$limit],
        );
    }

    public function initializeProgress(int $runId, int $files, int $bytesTotal): void
    {
        $this->db->execute(
            'UPDATE connector_sync_runs SET files_discovered=?,bytes_total=?,
             updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [$files, $bytesTotal, $runId],
        );
    }

    public function setCurrentFile(int $runId, string $path): void
    {
        $this->db->execute(
            'UPDATE connector_sync_runs SET current_file=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [substr($path, 0, self::MESSAGE_LIMIT), $runId],
        );
    }

    public function addFetchedBytes(int $runId, int $bytes): void
    {
        $this->db->execute(
            'UPDATE connector_sync_runs SET bytes_fetched=bytes_fetched+?,
             updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [$bytes, $runId],
        );
    }

    /** @param array<string,int> $result */
    public function completeFile(int $runId, int $filesCompleted, array $result): void
    {
        $this->db->execute(
            'UPDATE connector_sync_runs SET files_completed=?,files_updated=files_updated+?,
             events_indexed=events_indexed+?,current_file=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [
                $filesCompleted,
                (int) $result['files_updated'],
                (int) $result['events_indexed'],
                $runId,
            ],
        );
    }

    private function truncate(mixed $message): ?string
    {
        return $message === null ? null : substr((string) $message, 0, self::MESSAGE_LIMIT);
    }
}
