<?php
declare(strict_types=1);

namespace LogLens\Http\Endpoints;

use InvalidArgumentException;
use LogLens\Config;
use LogLens\Domain\NotFoundException;
use LogLens\Http\HttpError;
use LogLens\Http\LogLensRequest;
use LogLens\Http\LogLensResponse;
use LogLens\Http\RequestAuthorizer;
use LogLens\Identity\SystemAssignableUsersResolver;
use LogLens\Plugins\PluginManager;
use LogLens\Repositories\IssueQueryRepository;
use LogLens\Services\BulkIssueService;
use LogLens\Services\ManualIssueService;
use LogLens\Services\TagService;
use LogLens\Services\WorkflowService;
use LogLens\Support\StackTraceFormatter;
use PDO;
use RuntimeException;

/**
 * Everything about a single issue (an `error_groups` row) or the issue list:
 * read, manual creation, status, tags, assignment, and bulk mutation.
 *
 * Extracted from `ApiController` as its own resource-group class — the
 * dispatcher only routes to these methods, it no longer
 * knows how an issue list is assembled.
 */
final class IssueEndpoints
{
    private readonly IssueQueryRepository $issues;
    private readonly WorkflowService $workflow;
    private readonly TagService $tags;
    private readonly PluginManager $plugins;
    private readonly int $defaultLimit;
    private readonly int $maxLimit;

    public function __construct(private readonly PDO $db)
    {
        $this->issues = new IssueQueryRepository($db);
        $this->workflow = new WorkflowService($db);
        $this->tags = new TagService($db);
        $this->plugins = new PluginManager($db);
        $this->maxLimit = Config::int('pagination.max_limit', 200);
        $this->defaultLimit = min($this->maxLimit, Config::int('pagination.default_limit', 50));
    }

    public function summary(): LogLensResponse
    {
        return LogLensResponse::json($this->issues->summary());
    }

    public function sources(LogLensRequest $request): LogLensResponse
    {
        return LogLensResponse::json($this->issues->sources(
            (int) ($request->query['page'] ?? 1),
            (int) ($request->query['limit'] ?? 12),
        ));
    }

    public function groups(LogLensRequest $request): LogLensResponse
    {
        $page = max(1, (int) ($request->query['page'] ?? 1));
        $limit = min($this->maxLimit, max(1, (int) ($request->query['limit'] ?? $this->defaultLimit)));
        $result = $this->issues->list($request->query, $limit, $page, true, true);
        return LogLensResponse::json(['items' => $result['items'], 'meta' => [
            'total' => $result['total'],
            'page' => $page,
            'limit' => $limit,
            'pages' => (int) ceil($result['total'] / $limit),
        ]]);
    }

    public function errors(LogLensRequest $request): LogLensResponse
    {
        if ($request->method === 'POST') {
            $groupId = (new ManualIssueService($this->db))->create($request->body);
            $detail = $this->issues->detail($groupId, 100, 1);
            if ($detail === null) {
                throw new NotFoundException('Created issue not found.');
            }
            $detail['data'] = $detail['group'];
            unset($detail['group']);
            return LogLensResponse::json($detail, 201);
        }
        if ($request->method !== 'GET') {
            throw new HttpError(405, 'GET or POST required.');
        }
        $page = max(1, (int) ($request->query['page'] ?? 1));
        $limit = min($this->maxLimit, max(1, (int) ($request->query['limit'] ?? $this->defaultLimit)));
        $includeStack = $request->boolean('include_stack');
        $includeContext = $request->boolean('include_context');
        $skipVendor = $request->boolean('skip_vendor');
        $filters = $request->query;
        $filters['sort'] ??= 'newest';
        $result = $this->issues->list($filters, $limit, $page, $includeStack, $includeContext);
        $items = $result['items'];
        if ($includeStack && $skipVendor) {
            foreach ($items as &$item) {
                $item['sample_stack'] = StackTraceFormatter::withoutVendor($item['sample_stack'] ?? null);
            }
        }
        return LogLensResponse::json(['data' => $items, 'meta' => [
            'total' => $result['total'],
            'page' => $page,
            'limit' => $limit,
            'pages' => (int) ceil($result['total'] / $limit),
            'sort' => $request->query['sort'] ?? 'newest',
            'include_stack' => $includeStack,
            'include_context' => $includeContext,
            'skip_vendor' => $skipVendor,
        ]]);
    }

    public function group(LogLensRequest $request, bool $publicShape): LogLensResponse
    {
        $id = (int) ($request->query['id'] ?? 0);
        $detail = $this->issues->detail(
            $id,
            (int) ($request->query['limit'] ?? ($publicShape ? 100 : 500)),
            (int) ($request->query['page'] ?? 1),
        );
        if ($detail === null) {
            throw new NotFoundException('Issue not found.');
        }
        $skipVendor = $request->boolean('skip_vendor');
        $includeContext = !isset($request->query['include_context']) || $request->boolean('include_context');
        if ($skipVendor) {
            $detail['group']['sample_stack'] = StackTraceFormatter::withoutVendor($detail['group']['sample_stack']);
        }
        if (!$includeContext) {
            unset($detail['group']['sample_context']);
            foreach ($detail['occurrences'] as &$occurrence) {
                unset($occurrence['context_preview']);
            }
        }
        if ($publicShape) {
            $detail['data'] = $detail['group'];
            unset($detail['group']);
        }
        $detail['meta']['skip_vendor'] = $skipVendor;
        $detail['meta']['include_context'] = $includeContext;
        return LogLensResponse::json($detail);
    }

    public function source(LogLensRequest $request): LogLensResponse
    {
        $source = $this->issues->rawOccurrence((int) ($request->query['occurrence'] ?? 0));
        if ($source === null) {
            throw new RuntimeException('The archived source file is unavailable.');
        }
        return LogLensResponse::json($source);
    }

    public function issueStatus(LogLensRequest $request): LogLensResponse
    {
        if ($request->method !== 'POST') {
            throw new HttpError(405, 'POST required.');
        }
        $actor = RequestAuthorizer::authorize($request, 'issues.write');
        $data = $request->body;
        $groupId = (int) ($data['id'] ?? 0);
        $status = (string) ($data['status'] ?? '');
        $note = (string) ($data['note'] ?? '');
        // "Also comment on Linear" — on by default, but the caller (e.g. the
        // Inspector's status control) can turn it off for a status-only
        // write-back with no comment.
        $comment = filter_var($data['comment'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        $result = $this->workflow->change($groupId, $status, $note, $actor->label);

        // Enabled plugins (e.g. Linear) may mirror the change externally
        // (no direct dependency on Linear here). A plugin
        // write-back failure never fails the local status change.
        foreach ($this->plugins->onIssueStatusChanged($groupId, $status, $note, $comment) as $pluginId => $writeback) {
            $result[$pluginId] = $writeback;
        }
        return LogLensResponse::json($result);
    }

    public function issueTags(LogLensRequest $request): LogLensResponse
    {
        if ($request->method !== 'POST') {
            throw new HttpError(405, 'POST required.');
        }
        RequestAuthorizer::authorize($request, 'issues.write');
        $data = $request->body;
        $groupId = (int) ($data['group_id'] ?? 0);
        $tagId = (int) ($data['tag_id'] ?? 0);
        if ($groupId < 1 || $tagId < 1) {
            throw new InvalidArgumentException('group_id and tag_id are required.');
        }
        $action = (string) ($data['action'] ?? 'add');
        $action === 'remove' ? $this->tags->remove($groupId, $tagId) : $this->tags->assign($groupId, $tagId);
        return LogLensResponse::json(compact('groupId', 'tagId', 'action'));
    }

    /** Assign (or, with a null/empty id, unassign) an issue to a person (C-2). */
    public function assignIssue(LogLensRequest $request): LogLensResponse
    {
        if ($request->method !== 'POST') {
            throw new HttpError(405, 'POST required.');
        }
        RequestAuthorizer::authorize($request, 'issues.write');
        $data = $request->body;
        $groupId = (int) ($data['id'] ?? 0);
        $assignedToId = isset($data['assigned_to_id']) && trim((string) $data['assigned_to_id']) !== ''
            ? trim((string) $data['assigned_to_id'])
            : null;
        $assignedToLabel = $assignedToId !== null ? trim((string) ($data['assigned_to_label'] ?? '')) : null;
        if ($assignedToId !== null && $assignedToLabel === '') {
            throw new InvalidArgumentException('assigned_to_label is required when assigning.');
        }
        return LogLensResponse::json($this->workflow->assign($groupId, $assignedToId, $assignedToLabel));
    }

    /** The people this issue can be assigned to — supplied by the host (Laravel's log-lens.assignable-users), empty in standalone (C-2). */
    public function assignableUsers(LogLensRequest $request): LogLensResponse
    {
        return LogLensResponse::json(['data' => (new SystemAssignableUsersResolver())->resolve($request)]);
    }

    public function bulkIssues(LogLensRequest $request): LogLensResponse
    {
        if ($request->method !== 'POST') {
            throw new HttpError(405, 'POST required.');
        }
        $actor = RequestAuthorizer::authorize($request, 'issues.write');
        return LogLensResponse::json(
            (new BulkIssueService($this->db))->execute($request->body, $actor->label)
        );
    }
}
