<?php
declare(strict_types=1);

namespace LogLens\Tests\Unit;

use LogLens\Services\ConnectorService;
use LogLens\Services\ConnectorSyncService;
use LogLens\Tests\TestCase;

/**
 * Background-sync reliability: a crashed worker leaves a "running" row that no
 * longer advances; the reaper fails it while leaving a freshly-updated run.
 */
final class StaleRunReaperTest extends TestCase
{
    public function testReaperFailsStalledRunButLeavesLiveOne(): void
    {
        $pdo = $this->makeDatabase()->pdo;
        $mirror = $this->path('mirror');
        mkdir($mirror, 0775, true);
        $connector = (new ConnectorService($pdo))->create([
            'name' => 'Local mirror',
            'type' => 'local',
            'config' => ['directory' => $mirror, 'recursive' => true],
        ]);
        $connectorId = (int) $connector['id'];

        $pdo->prepare(
            "INSERT INTO connector_sync_runs(connector_id,status,started_at,updated_at)
             VALUES(?,'running',datetime('now','-2 hours'),datetime('now','-2 hours'))"
        )->execute([$connectorId]);
        $staleId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO connector_sync_runs(connector_id,status,started_at,updated_at)
             VALUES(?,'running',datetime('now'),datetime('now'))"
        )->execute([$connectorId]);
        $liveId = (int) $pdo->lastInsertId();

        $reaped = (new ConnectorSyncService($pdo, $this->path('mirror')))->reapStaleRuns();

        self::assertGreaterThanOrEqual(1, $reaped);
        self::assertSame('failed', $pdo->query("SELECT status FROM connector_sync_runs WHERE id={$staleId}")->fetchColumn());
        self::assertSame('running', $pdo->query("SELECT status FROM connector_sync_runs WHERE id={$liveId}")->fetchColumn());
    }
}
