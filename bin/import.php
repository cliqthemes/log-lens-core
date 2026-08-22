#!/usr/bin/env php
<?php
declare(strict_types=1);

use LogLens\Database;
use LogLens\Services\ApplicationRegistry;
use LogLens\Services\LogImportService;

$root = dirname(__DIR__);
$composer = $root . '/vendor/autoload.php';
require is_file($composer) ? $composer : $root . '/src/bootstrap.php';

$arguments = array_slice($argv, 1);
$applicationId = null;
$paths = [];
foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--app=')) {
        $applicationId = substr($argument, 6);
    } else {
        $paths[] = $argument;
    }
}
if ($paths === []) {
    fwrite(STDERR, "Usage: php bin/import.php [--app=application-id] <log file or folder> [...]\n");
    exit(1);
}

$applications = new ApplicationRegistry($root);
$application = $applications->resolve($applicationId);
$database = new Database(
    $applications->absolutePath($application, 'database'),
    (string) $application['id'],
);
$result = (new LogImportService($database->pdo))->importPaths($paths);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
