<?php
declare(strict_types=1);

namespace LogLens\Tests\Integration;

use InvalidArgumentException;
use LogLens\Config;
use LogLens\Http\ApiController;
use LogLens\Http\LogLensRequest;
use LogLens\Linear\LinearClient;
use LogLens\Repositories\IssueQueryRepository;
use LogLens\Services\LinearSettingsService;
use LogLens\Services\LinearSyncService;
use LogLens\Services\ManualIssueService;
use LogLens\Services\WorkflowService;
use LogLens\Tests\TestCase;
use PDO;

/**
 * The optional Linear integration driven through an injected fake GraphQL
 * transport (no network): settings/secrets, the pull+mapping, status-preserving
 * re-sync, write-back, explicit-assignee filters, and the HMAC webhook guard.
 */
final class LinearIntegrationTest extends TestCase
{
    /** @return list<array<string,mixed>> */
    private function issues(): array
    {
        return [
            [
                'id' => 'lin-1', 'identifier' => 'ENG-1', 'title' => 'Payment webhook returns 500',
                'description' => 'Stripe webhook handler throws on refunds.', 'priority' => 1,
                'priorityLabel' => 'Urgent', 'url' => 'https://linear.app/acme/issue/ENG-1',
                'createdAt' => '2026-08-01T10:00:00.000Z', 'updatedAt' => '2026-08-10T10:00:00.000Z',
                'state' => ['name' => 'In Progress', 'type' => 'started'],
                'assignee' => ['id' => 'me', 'name' => 'cliqthemes', 'email' => 'cliqthemes@example.test'],
                'team' => ['key' => 'ENG', 'name' => 'Engineering'],
                'labels' => ['nodes' => [['name' => 'billing', 'color' => '#ff0000'], ['name' => 'urgent', 'color' => '#00ff00']]],
            ],
            [
                'id' => 'lin-2', 'identifier' => 'ENG-2', 'title' => 'Add dark mode', 'description' => '',
                'priority' => 4, 'priorityLabel' => 'Low', 'url' => 'https://linear.app/acme/issue/ENG-2',
                'createdAt' => '2026-08-02T10:00:00.000Z', 'updatedAt' => '2026-08-09T10:00:00.000Z',
                'state' => ['name' => 'Backlog', 'type' => 'backlog'], 'assignee' => null,
                'team' => ['key' => 'ENG', 'name' => 'Engineering'],
                'labels' => ['nodes' => [['name' => 'billing', 'color' => '#ff0000']]],
            ],
        ];
    }

    /**
     * Build a sync service over a fake transport. $captured and $issues are held
     * by the caller so it can inspect what was sent and mutate the upstream set.
     */
    private function makeSync(PDO $pdo, LinearSettingsService $settings, array &$captured, array &$issues): LinearSyncService
    {
        $transport = static function (string $endpoint, array $headers, string $body) use (&$captured, &$issues): array {
            $payload = json_decode($body, true);
            $query = (string) ($payload['query'] ?? '');
            $variables = $payload['variables'] ?? [];
            if (str_contains($query, 'viewer {')) {
                $data = ['viewer' => ['id' => 'me', 'name' => 'cliqthemes', 'email' => 'cliqthemes@example.test']];
            } elseif (str_contains($query, 'issues(')) {
                $captured['filter'] = $variables['filter'] ?? null;
                $data = ['issues' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'nodes' => $issues]];
            } elseif (str_contains($query, 'states { nodes')) {
                $data = ['issue' => ['id' => $variables['id'], 'identifier' => 'ENG-1', 'team' => [
                    'id' => 'team-eng', 'key' => 'ENG', 'states' => ['nodes' => [
                        ['id' => 'st-todo', 'name' => 'Todo', 'type' => 'unstarted'],
                        ['id' => 'st-done', 'name' => 'Done', 'type' => 'completed'],
                    ]],
                ]]];
            } elseif (str_contains($query, 'commentCreate')) {
                $captured['comment'] = $variables['body'] ?? null;
                $data = ['commentCreate' => ['success' => true]];
            } elseif (str_contains($query, 'issueUpdate')) {
                $captured['stateId'] = $variables['stateId'] ?? null;
                $data = ['issueUpdate' => ['success' => true]];
            } else {
                $data = [];
            }
            return ['status' => 200, 'body' => (string) json_encode(['data' => $data])];
        };
        $client = new LinearClient('stored-linear-key', LinearClient::DEFAULT_ENDPOINT, 15, $transport);
        return new LinearSyncService($pdo, $settings, $client);
    }

    private function storedSettings(PDO $pdo, array $overrides = []): LinearSettingsService
    {
        Config::load(['linear' => ['secret' => 'deployment-secret']]);
        $settings = new LinearSettingsService($pdo);
        $settings->update(array_merge([
            'api_key' => 'stored-linear-key',
            'labels' => ['Billing', 'urgent'],
            'assigned_to_me' => true,
            'enabled' => true,
        ], $overrides));
        return $settings;
    }

    public function testEnvironmentKeyLocksTheUi(): void
    {
        Config::load(['linear' => ['api_key' => 'env-provided-key']]);
        $settings = new LinearSettingsService($this->makeDatabase()->pdo);
        $config = $settings->configuration();
        self::assertSame('env', $config['api_key_source']);
        self::assertTrue($config['env_key_locked']);
        self::assertSame('env-provided-key', $settings->apiKey());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/environment variable/');
        $settings->update(['api_key' => 'override-attempt']);
    }

    public function testStoresEncryptedConfiguration(): void
    {
        $stored = $this->storedSettings($this->makeDatabase()->pdo)->configuration();
        self::assertSame('stored', $stored['api_key_source']);
        self::assertTrue($stored['api_key_configured']);
        self::assertTrue($stored['at_rest_encrypted']);
        self::assertSame(['Billing', 'urgent'], $stored['labels']);
    }

    public function testPullMapsIssuesAndMirrorsLabels(): void
    {
        $pdo = $this->makeDatabase()->pdo;
        $captured = ['filter' => null, 'comment' => null, 'stateId' => null];
        $issues = $this->issues();
        $sync = $this->makeSync($pdo, $this->storedSettings($pdo), $captured, $issues);

        self::assertSame('cliqthemes', $sync->test()['viewer']['name']);
        $first = $sync->sync();
        self::assertSame(2, $first['pulled']);
        self::assertSame(2, $first['created']);
        self::assertSame(2, $first['tags_created']);
        self::assertArrayHasKey('or', $captured['filter'], 'label + assigned-to-me must combine with OR.');

        $groupId = (int) $pdo->query("SELECT id FROM error_groups WHERE external_id='lin-1'")->fetchColumn();
        $row = $pdo->query("SELECT severity,status,channel,assignee,external_source,log_type,kind,origin,environment FROM error_groups WHERE id={$groupId}")->fetch();
        self::assertSame('CRITICAL', $row['severity']);
        self::assertSame('in_progress', $row['status']);
        self::assertSame('ENG', $row['channel']);
        self::assertSame('cliqthemes', $row['assignee']);
        self::assertSame('linear', $row['origin']);
        self::assertSame('linear', $row['log_type']);
        self::assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM error_group_tags WHERE group_id={$groupId} AND source='linear'")->fetchColumn());
        self::assertSame(2, (new IssueQueryRepository($pdo))->list(['origin' => 'linear'], 20, 1, false, false)['total']);
    }

    public function testResyncPreservesLocalStatusButRefreshesFields(): void
    {
        $pdo = $this->makeDatabase()->pdo;
        $captured = ['filter' => null, 'comment' => null, 'stateId' => null];
        $issues = $this->issues();
        $sync = $this->makeSync($pdo, $this->storedSettings($pdo), $captured, $issues);
        $sync->sync();

        $groupId = (int) $pdo->query("SELECT id FROM error_groups WHERE external_id='lin-1'")->fetchColumn();
        (new WorkflowService($pdo))->change($groupId, 'fixed', 'Patched the handler.');
        $issues[0]['title'] = 'Payment webhook returns 500 (updated)';
        $reSync = $sync->sync();

        $after = $pdo->query("SELECT status,title FROM error_groups WHERE id={$groupId}")->fetch();
        self::assertSame(0, $reSync['created']);
        self::assertSame('fixed', $after['status'], 'Re-sync clobbered the local workflow status.');
        self::assertSame('Payment webhook returns 500 (updated)', $after['title']);
    }

    public function testStatusWriteBackCommentsAndTransitions(): void
    {
        $pdo = $this->makeDatabase()->pdo;
        $captured = ['filter' => null, 'comment' => null, 'stateId' => null];
        $issues = $this->issues();
        $settings = $this->storedSettings($pdo);
        $sync = $this->makeSync($pdo, $settings, $captured, $issues);
        $sync->sync();
        $settings->update(['status_writeback' => true]);
        $groupId = (int) $pdo->query("SELECT id FROM error_groups WHERE external_id='lin-1'")->fetchColumn();

        $writeback = $sync->onStatusChanged($groupId, 'fixed', 'Patched the handler.');
        self::assertTrue($writeback['attempted']);
        self::assertTrue($writeback['commented']);
        self::assertTrue($writeback['transitioned']);
        self::assertSame('st-done', $captured['stateId']);
        self::assertStringContainsString('Fixed', (string) $captured['comment']);

        $manualId = (new ManualIssueService($pdo))->create(['title' => 'Local only', 'kind' => 'task', 'severity' => 'INFO']);
        self::assertFalse($sync->onStatusChanged($manualId, 'fixed')['attempted'], 'Write-back must skip non-Linear issues.');
    }

    public function testExplicitAssigneesAreNormalizedIntoTheFilter(): void
    {
        $pdo = $this->makeDatabase()->pdo;
        $captured = ['filter' => null, 'comment' => null, 'stateId' => null];
        $issues = $this->issues();
        $settings = $this->storedSettings($pdo);
        $config = $settings->update(['labels' => [], 'assigned_to_me' => false, 'assignees' => ['Teammate@Example.test', 'b1c2d3e4-user-id']]);
        self::assertSame(['teammate@example.test', 'b1c2d3e4-user-id'], $config['assignees']);

        $this->makeSync($pdo, $settings, $captured, $issues)->sync();
        $filter = (string) json_encode($captured['filter']);
        self::assertStringContainsString('teammate@example.test', $filter);
        self::assertStringContainsString('b1c2d3e4-user-id', $filter);
    }

    public function testWebhookRejectsForgedSignature(): void
    {
        $pdo = $this->makeDatabase()->pdo;
        $this->storedSettings($pdo); // enable the integration for this application
        Config::load(['auth' => ['token' => 'dashboard-key'], 'linear' => ['secret' => 'deployment-secret', 'webhook_secret' => 'wh-secret']]);
        $controller = new ApiController($pdo, $this->workspace, $this->workspace, $this->workspace);
        $body = '{"action":"update","type":"Issue"}';
        $response = $controller->handle(new LogLensRequest(
            'POST',
            ['api' => 'linear-webhook'],
            (array) json_decode($body, true),
            ['host' => 'log-lens.test', 'linear-signature' => 'deadbeef'],
            $body,
        ));
        self::assertSame(401, $response->status);
        self::assertStringContainsString('signature', (string) $response->data['error']);
    }
}
