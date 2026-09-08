<?php
declare(strict_types=1);

namespace LogLens\Mcp;

use LogLens\Config;
use LogLens\Http\LogLensRequest;
use LogLens\Http\LogLensResponse;
use LogLens\Kernel;
use Throwable;

/**
 * A Model Context Protocol server (JSON-RPC 2.0, protocol `2024-11-05`)
 * exposing a subset of the Log Lens API as MCP tools for a coding agent.
 *
 * Deliberately thin: every tool call becomes a synthetic
 * {@see LogLensRequest} dispatched through the exact same
 * {@see Kernel::handle()} path an HTTP client hits under `?api=...` — the
 * same routing, authorization, validation, and persistence as the dashboard
 * API, not a parallel copy of any of it. {@see TOOLS} is the whole mapping: a
 * tool name to the `?api=` action it calls, the HTTP method that implies, and
 * which arguments become query parameters vs. JSON body fields.
 *
 * No actor is attached to the synthetic request, so
 * {@see \LogLens\Identity\SystemIdentityResolver} falls back to the implied
 * single local owner (every ability) — appropriate for a same-machine coding
 * agent, exactly as a CLI skill hitting the HTTP API with the configured
 * token already gets. When an API token is configured, it is attached
 * automatically (this process already has trusted access to `config.php`,
 * i.e. it already holds the token) so {@see \LogLens\Http\RequestGuard} does
 * not reject the in-process call; no Origin header is ever set, so the
 * same-origin half of that guard never applies here regardless.
 *
 * Scope: this server only covers what already exists in 0.3 — issues,
 * applications, and Linear sync/test. It deliberately does not expose the
 * planning surface (plans/tasks/decisions/memory/namespaces/skills/guide) —
 * that ships as its own tool set alongside the planning feature itself.
 *
 * Testable independently of stdio: {@see handleMessage()} takes and returns
 * plain arrays, so a test can drive the whole protocol without touching
 * STDIN or STDOUT — `bin/mcp-server.php` is the only piece that does that.
 */
final class McpServer
{
    private const PROTOCOL_VERSION = '2024-11-05';
    private const SERVER_NAME = 'log-lens';
    private const SERVER_VERSION = '1.0.0';

    /**
     * name => { description, action ('?api=' value), method, application
     * (whether an `application` argument is accepted — false only for the
     * one application-agnostic tool), params: list of
     * { name, in: 'query'|'body', type, required?, enum?, items?, description? }.
     *
     * `type`/`items` follow JSON Schema vocabulary directly, since that's
     * exactly what {@see inputSchema()} turns each entry into.
     */
    private const TOOLS = [
        'issues_list' => [
            'description' => 'List error-tracker issues (fingerprinted, recurring log events), with the same filters the dashboard issue list supports.',
            'action' => 'errors',
            'method' => 'GET',
            'params' => [
                ['name' => 'page', 'in' => 'query', 'type' => 'integer'],
                ['name' => 'limit', 'in' => 'query', 'type' => 'integer'],
                ['name' => 'q', 'in' => 'query', 'type' => 'string', 'description' => 'Free-text search across title, message, exception class, source frame, module, and matching source files.'],
                ['name' => 'status', 'in' => 'query', 'type' => 'string'],
                ['name' => 'severity', 'in' => 'query', 'type' => 'string'],
                ['name' => 'origin', 'in' => 'query', 'type' => 'string'],
                ['name' => 'kind', 'in' => 'query', 'type' => 'string'],
                ['name' => 'log_type', 'in' => 'query', 'type' => 'string'],
                ['name' => 'module', 'in' => 'query', 'type' => 'string'],
                ['name' => 'tag', 'in' => 'query', 'type' => 'string'],
                ['name' => 'date', 'in' => 'query', 'type' => 'string', 'description' => 'YYYY-MM-DD.'],
                ['name' => 'sort', 'in' => 'query', 'type' => 'string'],
                ['name' => 'include_stack', 'in' => 'query', 'type' => 'boolean'],
                ['name' => 'include_context', 'in' => 'query', 'type' => 'boolean'],
                ['name' => 'skip_vendor', 'in' => 'query', 'type' => 'boolean'],
            ],
        ],
        'issues_get' => [
            'description' => 'Fetch a single issue with its recent occurrences.',
            'action' => 'error',
            'method' => 'GET',
            'params' => [
                ['name' => 'id', 'in' => 'query', 'type' => 'integer', 'required' => true],
                ['name' => 'limit', 'in' => 'query', 'type' => 'integer'],
                ['name' => 'page', 'in' => 'query', 'type' => 'integer'],
                ['name' => 'skip_vendor', 'in' => 'query', 'type' => 'boolean'],
                ['name' => 'include_context', 'in' => 'query', 'type' => 'boolean'],
            ],
        ],
        'issues_update_status' => [
            'description' => 'Change an issue\'s workflow status (e.g. resolve it). For a Linear-sourced issue with status write-back enabled, this also updates Linear unless comment is false suppresses only the write-back comment (the status transition/write-back itself still happens).',
            'action' => 'issue-status',
            'method' => 'POST',
            'params' => [
                ['name' => 'id', 'in' => 'body', 'type' => 'integer', 'required' => true],
                ['name' => 'status', 'in' => 'body', 'type' => 'string', 'required' => true],
                ['name' => 'note', 'in' => 'body', 'type' => 'string'],
                ['name' => 'comment', 'in' => 'body', 'type' => 'boolean', 'description' => 'Default true. Whether a Linear write-back (if applicable) also leaves a comment, vs. moving status silently.'],
            ],
        ],
        'applications_list' => [
            'description' => 'List every application this Log Lens instance knows about. Each application is a fully isolated database and directory tree — no aggregated view exists.',
            'action' => 'applications',
            'method' => 'GET',
            'application' => false,
            'params' => [],
        ],
        'linear_sync' => [
            'description' => 'Pull one bounded batch of Linear issues matching the configured filter and upsert them now — the same call the dashboard\'s "Sync now" button makes. Returns has_more:true if this filter still has more to pull; call again to continue a backfill (it resumes from a persisted cursor, not page one).',
            'action' => 'linear-sync',
            'method' => 'POST',
            'params' => [],
        ],
        'linear_test' => [
            'description' => 'Verify the configured Linear API key and report the authenticated account.',
            'action' => 'linear-test',
            'method' => 'POST',
            'params' => [],
        ],
    ];

    public function __construct(private readonly string $projectRoot)
    {
    }

    /**
     * Handle one decoded JSON-RPC message. Returns null for a notification —
     * a message with no `id` — since the spec forbids replying to those,
     * success or failure alike.
     *
     * @param array<string,mixed> $message
     * @return array<string,mixed>|null
     */
    public function handleMessage(array $message): ?array
    {
        $id = array_key_exists('id', $message) ? $message['id'] : null;
        $method = (string) ($message['method'] ?? '');
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        $result = null;
        $error = null;
        try {
            switch ($method) {
                case 'initialize':
                    $result = $this->initialize();
                    break;
                case 'notifications/initialized':
                case 'notifications/cancelled':
                    return null;
                case 'ping':
                    $result = (object) [];
                    break;
                case 'tools/list':
                    $result = $this->toolsList();
                    break;
                case 'tools/call':
                    $result = $this->toolsCall($params);
                    break;
                default:
                    $error = ['code' => -32601, 'message' => "Unknown method \"{$method}\"."];
            }
        } catch (Throwable $exception) {
            $error = ['code' => -32603, 'message' => $exception->getMessage()];
        }

        if ($id === null) {
            return null;
        }
        return $error !== null
            ? ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error]
            : ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function initialize(): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => ['tools' => (object) []],
            'serverInfo' => ['name' => self::SERVER_NAME, 'version' => self::SERVER_VERSION],
        ];
    }

    private function toolsList(): array
    {
        $tools = [];
        foreach (self::TOOLS as $name => $tool) {
            $tools[] = [
                'name' => $name,
                'description' => $tool['description'],
                'inputSchema' => $this->inputSchema($tool),
            ];
        }
        return ['tools' => $tools];
    }

    /** @param array<string,mixed> $tool */
    private function inputSchema(array $tool): array
    {
        $properties = [];
        $required = [];
        if ($tool['application'] ?? true) {
            $properties['application'] = [
                'type' => 'string',
                'description' => 'Application id to operate on (omit to use the default/only application).',
            ];
        }
        foreach ($tool['params'] as $param) {
            $property = ['type' => $param['type']];
            foreach (['enum', 'items', 'properties', 'description'] as $key) {
                if (isset($param[$key])) {
                    $property[$key] = $param[$key];
                }
            }
            if (isset($param['required']) && $param['type'] === 'object' && is_array($param['required'])) {
                $property['required'] = $param['required'];
            }
            $properties[$param['name']] = $property;
            if (($param['required'] ?? false) === true) {
                $required[] = $param['name'];
            }
        }
        $schema = ['type' => 'object', 'properties' => (object) $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }
        return $schema;
    }

    /** @param array<string,mixed> $params */
    private function toolsCall(array $params): array
    {
        $name = (string) ($params['name'] ?? '');
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        if (!isset(self::TOOLS[$name])) {
            return $this->toolError("Unknown tool \"{$name}\".");
        }

        $response = $this->dispatch(self::TOOLS[$name], $arguments);
        if ($response->status >= 400) {
            $message = is_array($response->data) && isset($response->data['error'])
                ? (string) $response->data['error']
                : "Request failed with status {$response->status}.";
            return $this->toolError($message);
        }
        return [
            'content' => [
                ['type' => 'text', 'text' => json_encode($response->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)],
            ],
            'isError' => false,
        ];
    }

    /** @param array<string,mixed> $tool @param array<string,mixed> $arguments */
    private function dispatch(array $tool, array $arguments): LogLensResponse
    {
        $query = ['api' => $tool['action']];
        $body = [];

        if (($tool['application'] ?? true) && isset($arguments['application']) && $arguments['application'] !== '') {
            $query['app'] = (string) $arguments['application'];
        }

        foreach ($tool['params'] as $param) {
            $name = $param['name'];
            if (!array_key_exists($name, $arguments)) {
                continue;
            }
            if ($param['in'] === 'query') {
                $query[$name] = $arguments[$name];
            } else {
                $body[$name] = $arguments[$name];
            }
        }

        // Attach the configured API key ourselves: this process already has
        // trusted access to config.php, i.e. it already holds the token a
        // remote caller would have to present. No Origin header is ever set,
        // so RequestGuard's same-origin check never triggers here regardless.
        $headers = [];
        $token = Config::string('auth.token');
        if ($token !== '') {
            $headers['x-log-lens-token'] = $token;
        }

        $request = new LogLensRequest($tool['method'], $query, $body, $headers);
        return (new Kernel($this->projectRoot))->handle($request);
    }

    /** @return array{content:list<array{type:string,text:string}>,isError:bool} */
    private function toolError(string $message): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $message]],
            'isError' => true,
        ];
    }
}
