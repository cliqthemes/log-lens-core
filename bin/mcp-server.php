#!/usr/bin/env php
<?php
declare(strict_types=1);

use LogLens\ErrorBoundary;
use LogLens\Mcp\McpServer;

$root = dirname(__DIR__);
$composer = $root . '/vendor/autoload.php';
require is_file($composer) ? $composer : $root . '/src/bootstrap.php';

foreach (array_slice($argv, 1) as $argument) {
    if (in_array($argument, ['-h', '--help'], true)) {
        fwrite(STDOUT, "Usage: php bin/mcp-server.php\n"
            . "Speaks MCP (JSON-RPC 2.0) over stdio: one JSON object per line on stdin,\n"
            . "one JSON object per line back on stdout. Point an MCP-capable client\n"
            . "(Claude Code, Claude Desktop, etc.) at this command directly — it takes\n"
            . "no arguments of its own; which application a tool call targets is an\n"
            . "argument on the call itself (see the applications_list tool).\n");
        exit(0);
    }
    fwrite(STDERR, "Unknown argument: {$argument}\n");
    exit(1);
}

// Same JSON-only guarantee the standalone HTTP front controller gets, minus
// its 300s time limit — an MCP server is a long-lived process that outlives
// any one tool call, so it must not be killed by max_execution_time.
ErrorBoundary::install(0);

$server = new McpServer($root);

// Line-delimited JSON-RPC over stdio (the MCP "stdio transport"): one
// message per line in each direction. STDOUT carries protocol traffic only —
// anything else (this script's own diagnostics) goes to STDERR, or a client
// reading STDOUT as a stream of JSON-RPC messages breaks.
while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    $message = json_decode($line, true);
    if (!is_array($message)) {
        // No request id to echo back — an unparseable line is the one case
        // the spec answers without one.
        fwrite(STDOUT, json_encode([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => -32700, 'message' => 'Parse error: invalid JSON.'],
        ]) . "\n");
        fflush(STDOUT);
        continue;
    }
    $response = $server->handleMessage($message);
    if ($response !== null) {
        fwrite(STDOUT, json_encode($response, JSON_UNESCAPED_SLASHES) . "\n");
        fflush(STDOUT);
    }
}
