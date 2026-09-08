<?php
declare(strict_types=1);

namespace LogLens\Tests\Unit\Mcp;

use LogLens\Config;
use LogLens\Database;
use LogLens\Mcp\McpServer;
use LogLens\Plugins\PluginManager;
use LogLens\Services\ApplicationRegistry;
use LogLens\Services\LinearSettingsService;
use LogLens\Tests\TestCase;
use PDO;

/**
 * The MCP server: JSON-RPC 2.0 plumbing ({@see McpServer::handleMessage()})
 * and every tool in {@see McpServer::TOOLS} dispatched through the real
 * {@see \LogLens\Kernel} — no stdio involved, per the class's own
 * testability note. This 0.3 tool set is deliberately narrow (issues,
 * applications, Linear sync/test) — see the class docblock for why the
 * planning surface isn't here.
 */
final class McpServerTest extends TestCase
{
    /** @return array{0:McpServer,1:PDO} */
    private function server(): array
    {
        Config::load(['auth' => ['token' => '']]);
        $root = $this->path('mcp-workspace');
        mkdir($root, 0775, true);
        $applications = new ApplicationRegistry($root);
        $application = $applications->resolve(null);
        $database = new Database($applications->absolutePath($application, 'database'), (string) $application['id']);

        return [new McpServer($root), $database->pdo];
    }

    private function call(McpServer $server, string $name, array $arguments = []): array
    {
        $response = $server->handleMessage([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ]);
        return $response['result'];
    }

    /** Decode a successful tool call's single text content block. */
    private function decode(array $result): mixed
    {
        self::assertFalse($result['isError'], $result['content'][0]['text'] ?? '');
        return json_decode($result['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
    }

    public function testInitializeReportsProtocolAndServerInfo(): void
    {
        [$server] = $this->server();
        $response = $server->handleMessage(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]);

        self::assertSame('2.0', $response['jsonrpc']);
        self::assertSame(1, $response['id']);
        self::assertSame('2024-11-05', $response['result']['protocolVersion']);
        self::assertSame('log-lens', $response['result']['serverInfo']['name']);
        self::assertArrayHasKey('tools', $response['result']['capabilities']);
    }

    public function testNotificationsAndCancelledNeverReceiveAResponse(): void
    {
        [$server] = $this->server();
        self::assertNull($server->handleMessage(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));
        self::assertNull($server->handleMessage(['jsonrpc' => '2.0', 'method' => 'notifications/cancelled']));
    }

    public function testPingRespondsWithAnEmptyResult(): void
    {
        [$server] = $this->server();
        $response = $server->handleMessage(['jsonrpc' => '2.0', 'id' => 'x', 'method' => 'ping']);
        self::assertSame('x', $response['id']);
        self::assertEquals((object) [], $response['result']);
    }

    public function testUnknownMethodIsAJsonRpcMethodNotFoundError(): void
    {
        [$server] = $this->server();
        $response = $server->handleMessage(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'not/a/method']);
        self::assertSame(-32601, $response['error']['code']);
    }

    public function testToolsListEnumeratesExactlyTheZeroPointThreeToolSetWithASchema(): void
    {
        [$server] = $this->server();
        $response = $server->handleMessage(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
        $tools = $response['result']['tools'];

        self::assertCount(6, $tools);
        $byName = array_column($tools, null, 'name');
        self::assertSame(
            ['issues_list', 'issues_get', 'issues_update_status', 'applications_list', 'linear_sync', 'linear_test'],
            array_keys($byName),
        );

        // Every application-scoped tool exposes the "application" property...
        self::assertTrue(property_exists($byName['issues_get']['inputSchema']['properties'], 'application'));
        // ...except the one application-agnostic tool.
        self::assertFalse(property_exists($byName['applications_list']['inputSchema']['properties'], 'application'));
        self::assertEquals((object) [], $byName['applications_list']['inputSchema']['properties']);

        // Required scalars surface in the schema's top-level "required" list.
        self::assertSame(['id'], $byName['issues_get']['inputSchema']['required']);
        self::assertContains('id', $byName['issues_update_status']['inputSchema']['required']);
        self::assertContains('status', $byName['issues_update_status']['inputSchema']['required']);
    }

    public function testToolsCallRejectsAnUnknownToolName(): void
    {
        [$server] = $this->server();
        $result = $this->call($server, 'not.a.tool');
        self::assertTrue($result['isError']);
        self::assertStringContainsString('Unknown tool', $result['content'][0]['text']);
    }

    public function testToolsCallSurfacesA404AsAnIsErrorResultNotAProtocolError(): void
    {
        [$server, $pdo] = $this->server();
        // Linear ships enabled by default (unlike most plugins) — disable it
        // here so linear_sync's route 404s, same as the HTTP endpoint would.
        (new PluginManager($pdo))->setEnabled('linear', false);

        $response = $server->handleMessage([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'linear_sync', 'arguments' => []],
        ]);

        // The 404 never becomes a JSON-RPC protocol error — it's a normal result.
        self::assertArrayNotHasKey('error', $response);
        self::assertTrue($response['result']['isError']);
    }

    public function testIssuesListGetAndUpdateStatusReachTheErrorTracker(): void
    {
        [$server, $pdo] = $this->server();
        $pdo->exec(
            "INSERT INTO error_groups(fingerprint,severity,title,first_seen,last_seen,status,origin,kind)"
            . " VALUES('fp-mcp-1','error','Boom','2026-01-01 00:00:00','2026-01-01 00:00:00','open','ingested','issue')"
        );
        $issueId = (int) $pdo->lastInsertId();

        $list = $this->decode($this->call($server, 'issues_list', ['status' => 'open']));
        self::assertGreaterThanOrEqual(1, count($list['data']));

        $issue = $this->decode($this->call($server, 'issues_get', ['id' => $issueId]));
        self::assertSame($issueId, $issue['data']['id']);

        $updated = $this->decode($this->call($server, 'issues_update_status', ['id' => $issueId, 'status' => 'fixed', 'comment' => false]));
        self::assertSame('fixed', $updated['status']);
    }

    public function testApplicationsListNeedsNoApplicationArgument(): void
    {
        [$server] = $this->server();
        $applications = $this->decode($this->call($server, 'applications_list'));
        self::assertArrayHasKey('data', $applications);
        self::assertNotEmpty($applications['data']);
    }

    public function testLinearSyncReachesTheRealValidationWithoutAnyNetworkCall(): void
    {
        [$server, $pdo] = $this->server();
        // Enabling the integration (api key + enabled) but configuring no
        // filter reaches LinearSyncService::sync()'s "never pull an entire
        // workspace by accident" guard — which fires before any client is
        // even constructed, so this exercises the full tool -> ?api=linear-sync
        // -> LinearSyncService dispatch path with zero network dependency.
        Config::load(['linear' => ['secret' => 'deployment-secret']]);
        (new LinearSettingsService($pdo))->update(['api_key' => 'a-fake-key', 'enabled' => true]);

        $result = $this->call($server, 'linear_sync');
        self::assertTrue($result['isError']);
        self::assertStringContainsString('Configure at least one Linear filter', $result['content'][0]['text']);
    }

    public function testLinearTestIsGatedByThePluginTheSameWayTheHttpEndpointIs(): void
    {
        [$server, $pdo] = $this->server();
        (new PluginManager($pdo))->setEnabled('linear', false);

        $result = $this->call($server, 'linear_test');
        self::assertTrue($result['isError']);
    }

    public function testAutoAttachesTheConfiguredAuthTokenSoTheGuardNeverRejectsInProcessCalls(): void
    {
        $root = $this->path('mcp-workspace-token');
        mkdir($root, 0775, true);
        Config::load(['auth' => ['token' => 'super-secret']]);
        $server = new McpServer($root);

        $result = $this->call($server, 'applications_list');
        self::assertFalse($result['isError'] ?? true);
    }
}
