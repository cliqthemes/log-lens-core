<?php
declare(strict_types=1);

namespace LogLens\Tests\Integration;

use LogLens\Config;
use LogLens\Database;
use LogLens\Services\BackgroundSyncService;
use LogLens\Services\ConnectorService;
use LogLens\Services\ConnectorSyncService;
use LogLens\Services\IngestionSettingsService;
use LogLens\Services\LogImportService;
use LogLens\Tests\TestCase;
use RuntimeException;

/**
 * The connector synchronization lifecycle — an inherently sequential workflow:
 * manual/connector reconciliation, incremental byte-offset checkpointing,
 * truncation + rename rotation into new stream generations, snapshot-verified
 * previews, the queue/drain path, and the CLI fallback when the worker can't
 * launch. Kept as one isolated scenario on its own fresh database.
 */
final class ConnectorSyncTest extends TestCase
{
    public function testReconciliationCheckpointRotationAndQueue(): void
    {
        $fixtureRoot = $this->workspace;
        $database = new Database($fixtureRoot . '/test.sqlite');
        (new IngestionSettingsService($database->pdo))->update(IngestionSettingsService::LEVELS);
        $importer = new LogImportService($database->pdo);

        $incomingDirectory = $fixtureRoot . '/logs';
        $processedDirectory = $fixtureRoot . '/processed';
        mkdir($incomingDirectory);

        $remoteDirectory = $fixtureRoot . '/remote-logs';
        $sourceMirrors = $fixtureRoot . '/source-mirrors';
        mkdir($remoteDirectory);
        $remoteLog = $remoteDirectory . '/remote-laravel.log';
        file_put_contents(
            $remoteLog,
            "[2026-01-03 09:00:00] production.ERROR: Remote bridge failed for order 100\n"
            . "[2026-01-03 09:01:00] production.ERROR: Remote bridge failed for order 101\n"
        );
        copy($remoteLog, $incomingDirectory . '/remote-laravel.log');
        $manualNoon = $importer->importIncoming($incomingDirectory, $processedDirectory);
        self::assertSame(2, $manualNoon['events'], 'Manual noon import did not index the remote fixture.');

        $connectorService = new ConnectorService($database->pdo);
        $localConnector = $connectorService->create([
            'name' => 'Production local mirror',
            'type' => 'local',
            'config' => ['directory' => $remoteDirectory, 'recursive' => true],
        ]);
        self::assertTrue($connectorService->test((int) $localConnector['id'])['ok']);

        $syncService = new ConnectorSyncService($database->pdo, $sourceMirrors);
        $manualPreview = $syncService->preview((int) $localConnector['id']);
        self::assertSame(0, $manualPreview['summary']['files_to_sync']);
        self::assertSame(1, $manualPreview['summary']['already_current']);
        self::assertSame('already_ingested', $manualPreview['files'][0]['action']);
        self::assertFalse($manualPreview['files'][0]['will_sync']);

        $firstSync = $syncService->sync((int) $localConnector['id']);
        self::assertSame(0, $firstSync['events_indexed']);
        self::assertSame(0, $firstSync['bytes_fetched']);
        self::assertSame(1, $firstSync['manual_sources_reconciled'], 'Connector did not reconcile the manual noon prefix.');

        file_put_contents($remoteLog, "[2026-01-03 21:00:00] production.ERROR: Remote bridge failed for order 102\n", FILE_APPEND);
        $nightSync = $syncService->sync((int) $localConnector['id']);
        $repeatSync = $syncService->sync((int) $localConnector['id']);
        self::assertSame(1, $nightSync['events_indexed']);
        self::assertGreaterThan(0, $nightSync['bytes_fetched']);
        self::assertSame(0, $repeatSync['events_indexed']);
        self::assertSame(0, $repeatSync['bytes_fetched']);
        self::assertSame(3, (int) $database->pdo->query("SELECT count FROM error_groups WHERE title LIKE 'Remote bridge failed%'")->fetchColumn());

        copy($remoteLog, $incomingDirectory . '/remote-laravel.log');
        $manualRepeat = $importer->importIncoming($incomingDirectory, $processedDirectory);
        self::assertSame(0, $manualRepeat['events'], 'A complete manual snapshot duplicated a synchronized stream.');

        file_put_contents($remoteLog, "[2026-01-03 22:00:00] production.ERROR: Remote bridge failed for order 103\n", FILE_APPEND);
        copy($remoteLog, $incomingDirectory . '/remote-laravel.log');
        $manualNight = $importer->importIncoming($incomingDirectory, $processedDirectory);
        $checkpointSync = $syncService->sync((int) $localConnector['id']);
        self::assertSame(1, $manualNight['events']);
        self::assertSame(0, $checkpointSync['events_indexed']);
        self::assertSame(0, $checkpointSync['bytes_fetched']);
        self::assertSame(4, (int) $database->pdo->query("SELECT count FROM error_groups WHERE title LIKE 'Remote bridge failed%'")->fetchColumn());

        // Truncation rotation -> new stream generation.
        file_put_contents($remoteLog, "[2026-01-04 00:00:00] production.ERROR: Rotated remote stream started\n");
        $rotationSync = $syncService->sync((int) $localConnector['id']);
        self::assertSame(1, $rotationSync['streams_created']);
        self::assertSame(1, $rotationSync['events_indexed']);
        self::assertSame(2, (int) $database->pdo->query("SELECT COUNT(*) FROM source_streams WHERE connector_id={$localConnector['id']}")->fetchColumn());

        // Rename rotation -> another generation, renamed file not reindexed.
        rename($remoteLog, $remoteDirectory . '/remote-laravel.log.1');
        file_put_contents($remoteLog, "[2026-01-04 01:00:00] production.ERROR: New active stream after rename rotation\n");
        $renameRotationSync = $syncService->sync((int) $localConnector['id']);
        self::assertSame(1, $renameRotationSync['events_indexed']);
        self::assertSame(1, $renameRotationSync['streams_created']);
        self::assertSame(1, (int) $database->pdo->query("SELECT count FROM error_groups WHERE title='Rotated remote stream started'")->fetchColumn());

        // Snapshot-verified no-op.
        $syncPreview = $syncService->preview((int) $localConnector['id']);
        $noOpSync = $syncService->sync((int) $localConnector['id'], $syncPreview['snapshot']);
        self::assertSame(2, $syncPreview['summary']['files']);
        self::assertSame(0, $syncPreview['summary']['files_to_sync']);
        self::assertSame(2, $syncPreview['summary']['already_current']);
        self::assertSame(0, $noOpSync['files_discovered']);
        self::assertSame(0, $noOpSync['events_indexed']);

        // Stale snapshot rejected.
        $stalePreview = $syncService->preview((int) $localConnector['id']);
        file_put_contents($remoteDirectory . '/daily-2026-01-04.log', "[2026-01-04 02:00:00] production.ERROR: Dynamically discovered daily stream\n");
        try {
            $syncService->sync((int) $localConnector['id'], $stalePreview['snapshot']);
            self::fail('A stale connector preview snapshot was accepted.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('changed after preview', $exception->getMessage());
        }
        $freshPreview = $syncService->preview((int) $localConnector['id']);
        $freshSync = $syncService->sync((int) $localConnector['id'], $freshPreview['snapshot']);
        self::assertSame(3, $freshPreview['summary']['files']);
        self::assertSame(1, $freshPreview['summary']['files_to_sync']);
        self::assertSame(1, $freshSync['files_discovered']);
        self::assertSame(1, $freshSync['events_indexed']);

        // Queue + drain publishes durable progress.
        file_put_contents($remoteLog, "[2026-01-04 03:00:00] production.ERROR: Approved existing stream append\n", FILE_APPEND);
        file_put_contents($remoteDirectory . '/daily-2026-01-05.log', "[2026-01-05 02:00:00] production.ERROR: Approved new daily stream\n");
        $queuedPreview = $syncService->preview((int) $localConnector['id']);
        self::assertSame(4, $queuedPreview['summary']['files']);
        self::assertSame(2, $queuedPreview['summary']['files_to_sync']);
        self::assertSame(2, $queuedPreview['summary']['already_current']);

        $queuedRunId = $syncService->enqueue((int) $localConnector['id'], $queuedPreview['snapshot']);
        $drained = $syncService->drainQueued((int) $localConnector['id']);
        $queuedRun = array_values(array_filter($syncService->history(), static fn (array $run): bool => (int) $run['id'] === $queuedRunId))[0] ?? null;
        self::assertNotNull($queuedRun);
        self::assertSame('success', $queuedRun['status']);
        self::assertSame(2, (int) $queuedRun['files_completed']);
        self::assertSame(2, (int) $queuedRun['events_indexed']);
        self::assertNull($queuedRun['current_file']);
        self::assertSame([], $drained['errors']);

        // CLI fallback when the worker cannot launch.
        $fallbackPreview = $syncService->preview((int) $localConnector['id']);
        Config::load(['sync' => ['php_binary' => $fixtureRoot . '/missing-php-cli']]);
        $fallbackStart = (new BackgroundSyncService($fixtureRoot, 'default', $sourceMirrors, $syncService))
            ->start((int) $localConnector['id'], $fallbackPreview['snapshot'], null);
        $fallbackDrain = $syncService->drainQueued((int) $localConnector['id']);
        Config::reset();
        self::assertTrue($fallbackStart['accepted']);
        self::assertFalse($fallbackStart['worker_started']);
        self::assertStringContainsString('remains queued', (string) ($fallbackStart['warning'] ?? ''));
        self::assertSame([], $fallbackDrain['errors']);
    }
}
