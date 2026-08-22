#!/usr/bin/env php
<?php
declare(strict_types=1);

use LogLens\Database;
use LogLens\Services\ApplicationRegistry;
use LogLens\Services\ConnectorSyncService;

$root = dirname(__DIR__);
$composer = $root . '/vendor/autoload.php';
require is_file($composer) ? $composer : $root . '/src/bootstrap.php';

$applicationId = null;
$connectorId = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--app=')) {
        $applicationId = substr($argument, 6);
    } elseif (str_starts_with($argument, '--connector=')) {
        $connectorId = (int) substr($argument, 12);
    } elseif (in_array($argument, ['-h', '--help'], true)) {
        fwrite(STDOUT, "Usage: php bin/sync.php [--app=application-id] [--connector=id]\n");
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(1);
    }
}

try {
    $applications = new ApplicationRegistry($root);
    $application = $applications->resolve($applicationId);
    $database = new Database(
        $applications->absolutePath($application, 'database'),
        (string) $application['id'],
    );
    $service = new ConnectorSyncService(
        $database->pdo,
        $applications->absolutePath($application, 'sources'),
    );
    $queued = $service->queuedRunIds($connectorId !== null && $connectorId > 0 ? $connectorId : null);
    $result = $queued !== []
        ? $service->drainQueued($connectorId !== null && $connectorId > 0 ? $connectorId : null)
        : ($connectorId !== null && $connectorId > 0
            ? $service->sync($connectorId)
            : $service->syncAll());
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(isset($result['errors']) && $result['errors'] !== [] ? 2 : 0);
} catch (\Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
