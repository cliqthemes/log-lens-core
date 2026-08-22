<?php
declare(strict_types=1);

namespace LogLens\Services;

use LogLens\Connectors\ConnectorFactory;
use LogLens\Contracts\LogSourceConnectorInterface;
use LogLens\Services\Sync\ManualSourceMatcher;
use LogLens\Services\Sync\SourceStreamRepository;
use LogLens\Services\Sync\StreamSynchronizer;
use LogLens\Services\Sync\SyncPlan;
use LogLens\Services\Sync\SyncPlanner;
use LogLens\Services\Sync\SyncRunRecorder;
use LogLens\Storage\Connection;
use LogLens\Storage\Dialects;
use LogLens\Storage\PdoConnection;
use PDO;
use RuntimeException;

/**
 * Runs a connector: plan, lock, fetch, index, record.
 *
 * This is the orchestrator, and deliberately only that. The work itself lives
 * in Services\Sync\: {@see SyncPlanner} decides what a run will do, {@see
 * StreamSynchronizer} does it one file at a time, {@see SyncRunRecorder} keeps
 * the run's status and progress, and {@see SourceStreamRepository} plus {@see
 * ManualSourceMatcher} answer the questions both of the first two need to ask.
 *
 * What stays here is the sequencing those pieces must not decide for
 * themselves: a run holds an exclusive per-connector lock for its whole
 * duration, it re-plans and re-checks the snapshot before touching anything, it
 * finalizes the import once at the end rather than per file, and its status is
 * always closed out — success or failure — before the lock is released.
 */
final class ConnectorSyncService
{
    private readonly Connection $db;
    private readonly SourceStreamRepository $streams;
    private readonly ManualSourceMatcher $manual;
    private readonly SyncPlanner $planner;
    private readonly SyncRunRecorder $runs;
    private ?LogImportService $importer = null;
    private ?StreamSynchronizer $synchronizer = null;

    public function __construct(
        Connection|PDO $db,
        private readonly string $sourcesDirectory,
        private readonly ConnectorFactory $factory = new ConnectorFactory(),
    ) {
        $this->db = $db instanceof Connection ? $db : new PdoConnection($db, Dialects::active());
        $this->streams = new SourceStreamRepository($this->db);
        $this->manual = new ManualSourceMatcher($this->db, $this->streams);
        $this->planner = new SyncPlanner($this->streams, $this->manual);
        $this->runs = new SyncRunRecorder($this->db);
    }

    private function importer(): LogImportService
    {
        return $this->importer ??= new LogImportService($this->db);
    }

    private function synchronizer(): StreamSynchronizer
    {
        return $this->synchronizer ??= new StreamSynchronizer(
            $this->db,
            $this->sourcesDirectory,
            $this->streams,
            $this->manual,
            $this->runs,
            $this->importer(),
        );
    }

    /** @return array<string,mixed> */
    public function preview(int $connectorId): array
    {
        return $this->plan($this->enabledConnector($connectorId))->toArray();
    }

    /** @return array{results:list<array<string,mixed>>,errors:list<array<string,mixed>>} */
    public function previewAll(): array
    {
        return $this->forEachEnabledConnector(
            fn (int $id): array => $this->preview($id),
            'connector_id',
        );
    }

    /**
     * Synchronize one connector.
     *
     * $existingRunId is passed when a queued run is being executed, so the run
     * the operator is already watching is the one that reports progress instead
     * of a second one appearing beside it.
     *
     * @return array<string,mixed>
     */
    public function sync(
        int $connectorId,
        ?string $expectedSnapshot = null,
        ?int $existingRunId = null,
    ): array {
        $connector = $this->enabledConnector($connectorId);
        $runId = $existingRunId ?? $this->runs->start($connectorId, $expectedSnapshot);
        $lock = null;
        try {
            // The lock is taken before planning: a plan made outside it could be
            // executed against a state another run had already moved on from.
            $lock = $this->lock($connectorId);
            if ($existingRunId !== null) {
                $this->runs->activate($runId, $connectorId);
            }
            $this->runs->supersedeOthers($runId, $connectorId);
            $adapter = $this->factory->make($connector);
            $plan = $this->plan($connector, $adapter);
            if ($expectedSnapshot !== null && !hash_equals($expectedSnapshot, $plan->snapshot())) {
                throw new RuntimeException(
                    'The synchronization plan changed after preview. Review the synchronization files again.'
                );
            }
            $result = $this->execute($connector, $adapter, $plan, $runId);
            $this->importer()->finalize();
            $this->runs->finishSuccessful($runId, $result);
            $this->recordConnectorStatus($connectorId, 'success', null);
            return $result;
        } catch (\Throwable $exception) {
            $this->runs->finishFailed($runId, $exception->getMessage());
            $this->recordConnectorStatus($connectorId, 'failed', $exception->getMessage());
            throw $exception;
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * Walk the plan, synchronizing each file and accumulating the totals.
     *
     * @param array<string,mixed> $connector
     * @return array<string,mixed>
     */
    private function execute(
        array $connector,
        LogSourceConnectorInterface $adapter,
        SyncPlan $plan,
        int $runId,
    ): array {
        $result = [
            'connector_id' => (int) $connector['id'],
            'files_discovered' => count($plan->actionableFiles()),
            'files_updated' => 0,
            'bytes_fetched' => 0,
            'events_indexed' => 0,
            'streams_created' => 0,
            'manual_sources_reconciled' => 0,
        ];
        $this->runs->initializeProgress($runId, $result['files_discovered'], $plan->bytesToFetch());
        $filesCompleted = 0;
        foreach ($plan->processedFiles() as $file) {
            // Reconcile-only files are still processed, but counting them as
            // progress would make a no-op run look like it moved bytes.
            $countsAsProgress = $plan->countsAsProgress($file);
            if ($countsAsProgress) {
                $this->runs->setCurrentFile($runId, $file->path);
            }
            $synced = $this->synchronizer()->synchronize(
                $connector,
                $adapter,
                $file,
                $plan->identitiesByPath(),
                $plan->identityCounts(),
                $runId,
            );
            foreach (['files_updated', 'bytes_fetched', 'events_indexed', 'streams_created', 'manual_sources_reconciled'] as $field) {
                $result[$field] += $synced[$field];
            }
            if ($countsAsProgress) {
                $filesCompleted++;
                $this->runs->completeFile($runId, $filesCompleted, $synced);
            }
        }
        return $result;
    }

    public function enqueue(int $connectorId, ?string $expectedSnapshot = null): int
    {
        $this->enabledConnector($connectorId);
        return $this->runs->enqueue($connectorId, $expectedSnapshot);
    }

    /**
     * @param array<string,string>|null $expectedSnapshots
     * @return list<int>
     */
    public function enqueueAll(?array $expectedSnapshots = null): array
    {
        $ids = $this->enabledConnectorIds();
        // Validated up front: enqueueing half the connectors and then rejecting
        // the rest would leave the operator with a partly-confirmed batch.
        foreach ($ids as $id) {
            $this->confirmedSnapshot($expectedSnapshots, $id);
        }
        $runIds = [];
        foreach ($ids as $id) {
            $runIds[] = $this->runs->enqueue($id, $this->confirmedSnapshot($expectedSnapshots, $id));
        }
        return $runIds;
    }

    /** @return array<string,mixed> */
    public function syncQueuedRun(int $runId): array
    {
        $run = $this->runs->claimQueued($runId);
        try {
            return $this->sync($run['connector_id'], $run['expected_snapshot'], $runId);
        } catch (\Throwable $exception) {
            $this->runs->failQueued($runId, $exception->getMessage());
            throw $exception;
        }
    }

    public function failQueuedRun(int $runId, string $message): void
    {
        $this->runs->failQueued($runId, $message);
    }

    /** Recover runs abandoned by a crashed worker. Returns the number reaped. */
    public function reapStaleRuns(): int
    {
        return $this->runs->reapStale();
    }

    /** @return list<int> */
    public function queuedRunIds(?int $connectorId = null): array
    {
        return $this->runs->queuedIds($connectorId);
    }

    /** @return array{results:list<array<string,mixed>>,errors:list<array<string,mixed>>} */
    public function drainQueued(?int $connectorId = null): array
    {
        $this->reapStaleRuns();
        $results = [];
        $errors = [];
        foreach ($this->queuedRunIds($connectorId) as $runId) {
            try {
                $results[] = $this->syncQueuedRun($runId);
            } catch (\Throwable $exception) {
                $errors[] = ['run_id' => $runId, 'error' => $exception->getMessage()];
            }
        }
        return compact('results', 'errors');
    }

    /**
     * @param array<string,string>|null $expectedSnapshots
     * @return array{results:list<array<string,mixed>>,errors:list<array<string,mixed>>}
     */
    public function syncAll(?array $expectedSnapshots = null): array
    {
        $this->reapStaleRuns();
        return $this->forEachEnabledConnector(
            fn (int $id): array => $this->sync($id, $this->confirmedSnapshot($expectedSnapshots, $id)),
            'connector_id',
        );
    }

    /** @return list<array<string,mixed>> */
    public function history(int $limit = 25): array
    {
        // Self-correct on read so the dashboard never shows a dead worker's run
        // stuck as "running", even when no scheduled drain has run since.
        $this->reapStaleRuns();
        return $this->runs->history($limit);
    }

    /**
     * Plan a run, discovering the connector's files if not already done.
     *
     * @param array<string,mixed> $connector
     */
    private function plan(array $connector, ?LogSourceConnectorInterface $adapter = null): SyncPlan
    {
        $adapter ??= $this->factory->make($connector);
        return $this->planner->plan($connector, $adapter, $adapter->discover());
    }

    /** @return array<string,mixed> */
    private function enabledConnector(int $connectorId): array
    {
        $connector = (new ConnectorService($this->db, $this->factory))->row($connectorId);
        if (!(bool) $connector['enabled']) {
            throw new RuntimeException('Connector is disabled.');
        }
        return $connector;
    }

    /** @return list<int> */
    private function enabledConnectorIds(): array
    {
        $rows = $this->db->selectAll('SELECT id FROM connectors WHERE enabled=1 ORDER BY id');
        return array_map('intval', array_column($rows, 'id'));
    }

    /**
     * Run $work for every enabled connector, collecting failures instead of
     * aborting: one unreachable server must not stop the others syncing.
     *
     * @param callable(int):array<string,mixed> $work
     * @return array{results:list<array<string,mixed>>,errors:list<array<string,mixed>>}
     */
    private function forEachEnabledConnector(callable $work, string $idKey): array
    {
        $results = [];
        $errors = [];
        foreach ($this->enabledConnectorIds() as $id) {
            try {
                $results[] = $work($id);
            } catch (\Throwable $exception) {
                $errors[] = [$idKey => $id, 'error' => $exception->getMessage()];
            }
        }
        return compact('results', 'errors');
    }

    /**
     * The snapshot the operator confirmed for one connector.
     *
     * A caller that supplies snapshots at all must supply one per connector: a
     * missing entry means a connector appeared after the preview the operator
     * approved, so it is refused rather than synced unreviewed.
     *
     * @param array<string,string>|null $expectedSnapshots
     */
    private function confirmedSnapshot(?array $expectedSnapshots, int $connectorId): ?string
    {
        if ($expectedSnapshots === null) {
            return null;
        }
        if (!isset($expectedSnapshots[(string) $connectorId])) {
            throw new RuntimeException('Connector was not included in the confirmed synchronization preview.');
        }
        return (string) $expectedSnapshots[(string) $connectorId];
    }

    private function recordConnectorStatus(int $connectorId, string $status, ?string $error): void
    {
        $this->db->execute(
            'UPDATE connectors SET last_synced_at=CURRENT_TIMESTAMP,last_status=?,
             last_error=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [$status, $error === null ? null : substr($error, 0, 4_000), $connectorId],
        );
    }

    /**
     * Take the connector's exclusive lock, or refuse.
     *
     * Non-blocking on purpose: a second run of the same connector has nothing
     * useful to wait for — the first is already fetching what it would fetch —
     * so the operator gets an immediate answer instead of a hung request.
     *
     * @return resource
     */
    private function lock(int $connectorId)
    {
        $directory = rtrim($this->sourcesDirectory, '/') . '/.locks';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create connector lock directory.');
        }
        $handle = fopen($directory . "/connector-{$connectorId}.lock", 'c');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('This connector is already synchronizing.');
        }
        return $handle;
    }
}
