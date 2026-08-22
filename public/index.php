<?php
declare(strict_types=1);

use LogLens\ErrorBoundary;
use LogLens\Http\JsonResponse;
use LogLens\Http\LogLensRequest;
use LogLens\Http\SecurityHeaders;
use LogLens\Kernel;

$root = dirname(__DIR__);
$composer = $root . '/vendor/autoload.php';
require is_file($composer) ? $composer : $root . '/src/bootstrap.php';

// Standalone hardening headers (CSP etc.) for every branch below. Must run
// before any output. The Laravel embed inherits its host's headers instead.
SecurityHeaders::apply();

if (!isset($_GET['api'])) {
    $index = __DIR__ . '/ui/index.html';
    if (!is_file($index)) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Log Lens UI has not been built yet.\n"
            . "Run: cd frontend && npm install && npm run build\n";
        exit;
    }
    readfile($index);
    exit;
}

ErrorBoundary::install();

JsonResponse::emit((new Kernel($root))->handle(LogLensRequest::fromGlobals()));
