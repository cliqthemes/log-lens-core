<?php
declare(strict_types=1);

namespace LogLens\Tests;

use LogLens\Config;
use LogLens\Connectors\ConnectorFactory;
use LogLens\Database;
use LogLens\Plugins\PluginRegistry;
use LogLens\Services\SourcePathRepairService;
use LogLens\Storage\Dialects;
use LogLens\Storage\Drivers;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base class giving every test full isolation: a private temp directory, a
 * fresh SQLite database, and a clean global {@see Config} restored to the
 * shipped defaults. Nothing bleeds between tests, so ordering never matters —
 * the core problem with the legacy procedural `run.php` suite (H-3).
 */
abstract class TestCase extends BaseTestCase
{
    protected string $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = sys_get_temp_dir() . '/log-lens-test-' . bin2hex(\random_bytes(6));
        if (!mkdir($this->workspace, 0775, true) && !is_dir($this->workspace)) {
            self::fail("Could not create the test workspace: {$this->workspace}");
        }
        // Start each test from the shipped config.php defaults; a test that needs
        // different values calls Config::load([...]) itself.
        Config::reset();
        Config::load();
        // Clear per-process memoization so a reused path/workspace in one test
        // never lets a prior test's cached "verified" flag leak into the next.
        Database::resetSchemaCache();
        SourcePathRepairService::resetCache();
        PluginRegistry::reset();
        ConnectorFactory::reset();
        Dialects::reset();
        Drivers::reset();
    }

    protected function tearDown(): void
    {
        Config::reset();
        $this->removeTree($this->workspace);
        parent::tearDown();
    }

    /** A fresh, fully-migrated database under this test's private workspace. */
    protected function makeDatabase(string $name = 'test.sqlite'): Database
    {
        return new Database($this->path($name));
    }

    /** Absolute path to $name inside this test's private workspace. */
    protected function path(string $name): string
    {
        return $this->workspace . '/' . ltrim($name, '/');
    }

    /** Write $contents to $name inside the workspace and return its absolute path. */
    protected function writeFile(string $name, string $contents): string
    {
        $path = $this->path($name);
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        file_put_contents($path, $contents);
        return $path;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        $entries = scandir($path) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->removeTree($child) : @unlink($child);
        }
        @rmdir($path);
    }
}
