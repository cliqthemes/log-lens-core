#!/usr/bin/env php
<?php
declare(strict_types=1);

use LogLens\Database;
use LogLens\Services\ApplicationRegistry;
use LogLens\Services\LinearSettingsService;
use LogLens\Services\LinearSyncService;

$root = dirname(__DIR__);
$composer = $root . '/vendor/autoload.php';
require is_file($composer) ? $composer : $root . '/src/bootstrap.php';

$applicationId = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--app=')) {
        $applicationId = substr($argument, 6);
    } elseif (in_array($argument, ['-h', '--help'], true)) {
        fwrite(STDOUT, "Usage: php bin/linear-sync.php [--app=application-id]\n"
            . "Pulls matching Linear issues into the given Log Lens application.\n");
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
    $settings = new LinearSettingsService($database->pdo);
    if (!$settings->isEnabled()) {
        fwrite(STDOUT, "Linear integration is disabled for this application; nothing to do.\n");
        exit(0);
    }
    $result = (new LinearSyncService($database->pdo, $settings))->sync();
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
} catch (\Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
