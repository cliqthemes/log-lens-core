#!/usr/bin/env php
<?php
declare(strict_types=1);

use LogLens\Config;
use LogLens\Database;
use LogLens\Services\ApplicationRegistry;
use LogLens\Services\ConnectorSyncService;

$root = dirname(__DIR__);
$composer = $root . '/vendor/autoload.php';
require is_file($composer) ? $composer : $root . '/src/bootstrap.php';

$jobPath = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--job=')) {
        $jobPath = substr($argument, 6);
    }
}

if ($jobPath === null || !is_file($jobPath)) {
    fwrite(STDERR, "A readable --job file is required.\n");
    exit(1);
}

$exitCode = 0;
try {
    $job = json_decode((string) file_get_contents($jobPath), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($job) || !is_array($job['run_ids'] ?? null)) {
        throw new RuntimeException('The background synchronization job is invalid.');
    }
    Config::load(is_array($job['config'] ?? null) ? $job['config'] : []);
    $projectRoot = (string) ($job['project_root'] ?? '');
    $applications = new ApplicationRegistry($projectRoot);
    $application = $applications->resolve((string) ($job['application_id'] ?? ''));
    $database = new Database(
        $applications->absolutePath($application, 'database'),
        (string) $application['id'],
    );
    $service = new ConnectorSyncService(
        $database->pdo,
        $applications->absolutePath($application, 'sources'),
    );
    foreach ($job['run_ids'] as $runId) {
        try {
            $service->syncQueuedRun((int) $runId);
        } catch (\Throwable $exception) {
            fwrite(STDERR, "Run {$runId}: {$exception->getMessage()}\n");
        }
    }
} catch (\Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    $exitCode = 1;
} finally {
    @unlink($jobPath);
}
exit($exitCode);
