<?php
declare(strict_types=1);

namespace LogLens\Tests\Unit;

use LogLens\Database;
use LogLens\Services\SourcePathRepairService;
use LogLens\Tests\TestCase;

/**
 * M-8: per-request work (schema check + workspace path-repair) is memoized per
 * process so a reused PHP-FPM worker stops re-hitting the database on every
 * request. These tests pin the short-circuit behaviour and its reset.
 */
final class HotPathCacheTest extends TestCase
{
    public function testReopeningTheSamePathStillYieldsAWorkingSchema(): void
    {
        $path = $this->path('cache.sqlite');
        $first = new Database($path);
        $first->pdo->exec("INSERT INTO app_settings(key,value) VALUES('probe','1')");

        // Second construction hits the per-process cache and skips the migration
        // round-trip, but the connection must still be fully usable.
        $second = new Database($path);
        $value = $second->pdo
            ->query("SELECT value FROM app_settings WHERE key='probe'")
            ->fetchColumn();
        self::assertSame('1', $value);
    }

    public function testWorkspaceRepairIsMemoizedAfterFirstVerification(): void
    {
        $database = $this->makeDatabase();
        $processed = $this->path('processed');
        $sources = $this->path('sources');
        mkdir($processed, 0775, true);
        mkdir($sources, 0775, true);

        $service = new SourcePathRepairService($database->pdo, $processed, $sources);
        // First call records the workspace fingerprint in app_settings + cache.
        self::assertSame(['source_files' => 0, 'source_streams' => 0], $service->repairMovedWorkspacePaths());

        // Delete the persisted marker: without the per-process cache the next
        // call would fall through to a full scan. The cache must short-circuit
        // instead, proving the settings lookup was skipped.
        $database->pdo->exec("DELETE FROM app_settings WHERE key='source_path_workspace'");
        self::assertSame(['source_files' => 0, 'source_streams' => 0], $service->repairMovedWorkspacePaths());

        // After a reset the marker is gone, so the fingerprint is re-persisted.
        SourcePathRepairService::resetCache();
        $service->repairMovedWorkspacePaths();
        $stored = $database->pdo
            ->query("SELECT value FROM app_settings WHERE key='source_path_workspace'")
            ->fetchColumn();
        self::assertNotFalse($stored);
    }
}
