<?php
declare(strict_types=1);

namespace LogLens\Tests\Integration;

use LogLens\Config;
use LogLens\Http\ApiController;
use LogLens\Http\LogLensRequest;
use LogLens\Identity\Actor;
use LogLens\Plugins\PluginManager;
use LogLens\Tests\TestCase;
use PDO;

/**
 * C-2: the RoleAuthorizer abilities that were defined but never actually
 * enforced anywhere except issue-status/bulk-issues — tags and plugins now
 * gate through authorize() too — plus the new issue-assignment feature and
 * tag-mutation attribution built alongside it.
 */
final class AuthorizationGatingTest extends TestCase
{
    private function controller(PDO $pdo): ApiController
    {
        return new ApiController($pdo, $this->workspace, $this->workspace, $this->workspace);
    }

    private function request(string $method, array $query, array $body, Actor $actor): LogLensRequest
    {
        return new LogLensRequest($method, $query, $body, ['host' => 'log-lens.test'], null, null, null, $actor);
    }

    private function seedIssue(PDO $pdo): int
    {
        $pdo->exec(
            "INSERT INTO error_groups(fingerprint,severity,title,first_seen,last_seen,status,origin,kind)"
            . " VALUES('fp1','error','Boom','2026-01-01 00:00:00','2026-01-01 00:00:00','open','manual','issue')"
        );
        return (int) $pdo->lastInsertId();
    }

    public function testViewerIsRejectedFromEveryMutationEndpoint(): void
    {
        Config::load(['auth' => ['token' => '']]);
        $pdo = $this->makeDatabase()->pdo;
        $groupId = $this->seedIssue($pdo);
        $viewer = new Actor('v1', 'Val', [Actor::ROLE_VIEWER]);
        $controller = $this->controller($pdo);

        $cases = [
            ['POST', ['api' => 'issue-status'], ['id' => $groupId, 'status' => 'fixed']],
            ['POST', ['api' => 'bulk-issues'], ['ids' => [$groupId], 'action' => 'status', 'status' => 'fixed']],
            ['POST', ['api' => 'tags'], ['name' => 'Urgent']],
            ['POST', ['api' => 'assign-issue'], ['id' => $groupId, 'assigned_to_id' => 'u1', 'assigned_to_label' => 'Alice']],
            ['POST', ['api' => 'plugins'], ['id' => 'alerts', 'enabled' => true]],
        ];
        foreach ($cases as [$method, $query, $body]) {
            $response = $controller->handle($this->request($method, $query, $body, $viewer));
            self::assertSame(403, $response->status, "expected 403 for api={$query['api']}");
        }

        // Reads stay open to a viewer — only mutations are gated.
        self::assertSame(200, $controller->handle($this->request('GET', ['api' => 'tags'], [], $viewer))->status);
        self::assertSame(200, $controller->handle($this->request('GET', ['api' => 'plugins'], [], $viewer))->status);
    }

    public function testEditorCanWriteIssuesAndTagsButNotManagePlugins(): void
    {
        Config::load(['auth' => ['token' => '']]);
        $pdo = $this->makeDatabase()->pdo;
        $groupId = $this->seedIssue($pdo);
        $editor = new Actor('e1', 'Ed', [Actor::ROLE_EDITOR]);
        $controller = $this->controller($pdo);

        self::assertSame(200, $controller->handle(
            $this->request('POST', ['api' => 'issue-status'], ['id' => $groupId, 'status' => 'fixed'], $editor)
        )->status);
        self::assertSame(201, $controller->handle(
            $this->request('POST', ['api' => 'tags'], ['name' => 'Urgent'], $editor)
        )->status);
        self::assertSame(403, $controller->handle(
            $this->request('POST', ['api' => 'plugins'], ['id' => 'alerts', 'enabled' => true], $editor)
        )->status);
    }

    /**
     * These endpoints skipped authorize() entirely until this fix — a
     * Laravel embed relying on the `editor`/`viewer` roles (RoleAuthorizer's
     * documented, supported RBAC) could otherwise let a read-only viewer
     * configure connectors (incl. SSH credentials), delete indexed logs,
     * point an alert channel's webhook at an arbitrary URL, or read/rotate
     * the HTTP ingest key.
     */
    public function testViewerIsRejectedFromNewlyGatedPluginAndMaintenanceEndpoints(): void
    {
        Config::load(['auth' => ['token' => '']]);
        $pdo = $this->makeDatabase()->pdo;
        (new PluginManager($pdo))->setEnabled('alerts', true);
        (new PluginManager($pdo))->setEnabled('releases', true);
        (new PluginManager($pdo))->setEnabled('http_ingest', true);
        $viewer = new Actor('v1', 'Val', [Actor::ROLE_VIEWER]);
        $controller = $this->controller($pdo);

        $cases = [
            ['POST', ['api' => 'connectors'], ['type' => 'local_directory', 'name' => 'x', 'path' => '/tmp']],
            ['POST', ['api' => 'reindex'], []],
            ['POST', ['api' => 'delete-logs'], ['confirmation' => 'DELETE']],
            ['POST', ['api' => 'processed-retention'], []],
            ['POST', ['api' => 'ingestion-settings'], ['severities' => ['ERROR']]],
            ['POST', ['api' => 'alert-channels'], ['name' => 'x', 'type' => 'webhook', 'url' => 'https://example.test']],
            ['POST', ['api' => 'alert-rules'], ['name' => 'x', 'trigger' => 'new_error', 'channel_id' => 1]],
            ['POST', ['api' => 'alert-test'], ['id' => 1]],
            ['PUT', ['api' => 'release-settings'], ['default_ref' => 'main']],
            ['POST', ['api' => 'source-maps'], ['release' => '1', 'file' => 'a.js.map', 'content' => '{}']],
            ['PUT', ['api' => 'linear-settings'], ['api_key' => 'x']],
            ['POST', ['api' => 'linear-test'], []],
            ['POST', ['api' => 'linear-sync'], []],
            ['POST', ['api' => 'ingest-settings'], ['action' => 'regenerate']],
        ];
        foreach ($cases as [$method, $query, $body]) {
            $response = $controller->handle($this->request($method, $query, $body, $viewer));
            self::assertSame(403, $response->status, "expected 403 for api={$query['api']}");
        }
    }

    /**
     * The connector/release/Linear-sync operational actions (not
     * configuration changes) stay open to editor+owner, same tier as
     * issue/tag writes — only the settings-tier actions above are
     * owner-only.
     */
    public function testEditorCanUseOperationalActionsButNotSettingsTierOnes(): void
    {
        Config::load(['auth' => ['token' => '']]);
        $pdo = $this->makeDatabase()->pdo;
        (new PluginManager($pdo))->setEnabled('releases', true);
        $editor = new Actor('e1', 'Ed', [Actor::ROLE_EDITOR]);
        $controller = $this->controller($pdo);

        // Settings-tier: rejected for editor.
        self::assertSame(403, $controller->handle(
            $this->request('POST', ['api' => 'reindex'], [], $editor)
        )->status);

        // Operational: allowed for editor.
        self::assertSame(201, $controller->handle(
            $this->request('POST', ['api' => 'deploys'], ['version' => 'v1.2.3'], $editor)
        )->status);
    }

    public function testOwnerCanDoEverything(): void
    {
        Config::load(['auth' => ['token' => '']]);
        $pdo = $this->makeDatabase()->pdo;
        $groupId = $this->seedIssue($pdo);
        $owner = new Actor('o1', 'Olivia', [Actor::ROLE_OWNER]);
        $controller = $this->controller($pdo);

        self::assertSame(200, $controller->handle(
            $this->request('POST', ['api' => 'issue-status'], ['id' => $groupId, 'status' => 'fixed'], $owner)
        )->status);
        self::assertSame(201, $controller->handle(
            $this->request('POST', ['api' => 'tags'], ['name' => 'Urgent'], $owner)
        )->status);
        self::assertSame(200, $controller->handle(
            $this->request('POST', ['api' => 'plugins'], ['id' => 'alerts', 'enabled' => true], $owner)
        )->status);
    }

    public function testTagMutationsAreAttributedToTheActingUser(): void
    {
        Config::load(['auth' => ['token' => '']]);
        $pdo = $this->makeDatabase()->pdo;
        $owner = new Actor('o1', 'Olivia', [Actor::ROLE_OWNER]);

        // A fresh ApiController per call, matching real usage (Kernel builds
        // one per request) — the resolved actor is memoized per instance, so
        // reusing one across differently-actored calls would leak the first.
        $created = $this->controller($pdo)->handle($this->request('POST', ['api' => 'tags'], ['name' => 'Urgent'], $owner));
        self::assertSame(201, $created->status);
        $tagId = (int) $created->data['id'];
        self::assertSame('Olivia', $pdo->query("SELECT created_by FROM tags WHERE id={$tagId}")->fetchColumn());
        self::assertNull($pdo->query("SELECT updated_by FROM tags WHERE id={$tagId}")->fetchColumn());

        $other = new Actor('e1', 'Ed', [Actor::ROLE_EDITOR]);
        $this->controller($pdo)->handle($this->request('PUT', ['api' => 'tags'], ['id' => $tagId, 'name' => 'Urgent!!'], $other));
        self::assertSame('Ed', $pdo->query("SELECT updated_by FROM tags WHERE id={$tagId}")->fetchColumn());
        self::assertSame('Olivia', $pdo->query("SELECT created_by FROM tags WHERE id={$tagId}")->fetchColumn(), 'created_by is untouched by an update.');
    }

    public function testIssueAssignmentSetsAndClears(): void
    {
        Config::load(['auth' => ['token' => '']]);
        $pdo = $this->makeDatabase()->pdo;
        $groupId = $this->seedIssue($pdo);
        $owner = new Actor('o1', 'Olivia', [Actor::ROLE_OWNER]);
        $controller = $this->controller($pdo);

        $assigned = $controller->handle($this->request(
            'POST', ['api' => 'assign-issue'], ['id' => $groupId, 'assigned_to_id' => 'u42', 'assigned_to_label' => 'Bob'], $owner
        ));
        self::assertSame(200, $assigned->status);
        self::assertSame('u42', $pdo->query("SELECT assigned_to_id FROM error_groups WHERE id={$groupId}")->fetchColumn());
        self::assertSame('Bob', $pdo->query("SELECT assigned_to_label FROM error_groups WHERE id={$groupId}")->fetchColumn());

        // The list/detail read models surface it.
        $detail = $controller->handle($this->request('GET', ['api' => 'error', 'id' => $groupId], [], $owner));
        self::assertSame('Bob', $detail->data['data']['assigned_to_label']);

        // Clearing (no assigned_to_id) unassigns.
        $cleared = $controller->handle($this->request('POST', ['api' => 'assign-issue'], ['id' => $groupId], $owner));
        self::assertSame(200, $cleared->status);
        self::assertNull($pdo->query("SELECT assigned_to_id FROM error_groups WHERE id={$groupId}")->fetchColumn());
    }

    public function testAssignableUsersEndpointReflectsTheTransportSuppliedList(): void
    {
        Config::load(['auth' => ['token' => '']]);
        $pdo = $this->makeDatabase()->pdo;
        $owner = new Actor('o1', 'Olivia', [Actor::ROLE_OWNER]);
        $request = new LogLensRequest(
            'GET', ['api' => 'assignable-users'], [], ['host' => 'log-lens.test'], null, null, null, $owner,
            [['id' => 'u1', 'label' => 'Alice'], ['id' => 'u2', 'label' => 'Bob']],
        );
        $response = $this->controller($pdo)->handle($request);
        self::assertSame(200, $response->status);
        self::assertSame([['id' => 'u1', 'label' => 'Alice'], ['id' => 'u2', 'label' => 'Bob']], $response->data['data']);

        // Standalone default (no transport-supplied list): empty, not an error.
        $bare = new LogLensRequest('GET', ['api' => 'assignable-users'], [], ['host' => 'log-lens.test']);
        self::assertSame([], $this->controller($pdo)->handle($bare)->data['data']);
    }
}
