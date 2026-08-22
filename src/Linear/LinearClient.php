<?php
declare(strict_types=1);

namespace LogLens\Linear;

use LogLens\Support\HttpClient;
use RuntimeException;

/**
 * Minimal client for Linear's GraphQL API, authenticated with a personal API
 * key. Only the operations Log Lens needs are exposed: identify the key owner,
 * page through matching issues, and — for the optional status link — comment on
 * and transition an issue.
 *
 * The HTTP transport is injectable so the sync path can be exercised in tests
 * without network access. A transport receives the request body and returns
 * `['status' => int, 'body' => string]`; the default uses cURL.
 */
final class LinearClient
{
    public const DEFAULT_ENDPOINT = 'https://api.linear.app/graphql';

    /** @var callable(string,array<string,string>,string):array{status:int,body:string} */
    private $transport;

    /** @param callable(string,array<string,string>,string):array{status:int,body:string}|null $transport */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $endpoint = self::DEFAULT_ENDPOINT,
        private readonly int $timeout = 15,
        ?callable $transport = null,
    ) {
        $this->transport = $transport ?? function (string $endpoint, array $headers, string $body): array {
            return (new HttpClient($this->timeout, min($this->timeout, 10)))->post($endpoint, $body, $headers);
        };
    }

    /** @return array{id:string,name:string,email:string} */
    public function viewer(): array
    {
        $data = $this->query('query { viewer { id name email } }');
        $viewer = $data['viewer'] ?? null;
        if (!is_array($viewer) || !isset($viewer['id'])) {
            throw new RuntimeException('Linear did not return the authenticated user.');
        }
        return [
            'id' => (string) $viewer['id'],
            'name' => (string) ($viewer['name'] ?? ''),
            'email' => (string) ($viewer['email'] ?? ''),
        ];
    }

    /**
     * Fetch every issue matching an IssueFilter, following pagination up to a
     * safety ceiling on pages. Returns Linear's raw issue nodes.
     *
     * @param array<string,mixed> $filter
     * @return list<array<string,mixed>>
     */
    public function fetchIssues(array $filter, int $maxPages = 20, int $pageSize = 50): array
    {
        $query = <<<'GQL'
query Issues($filter: IssueFilter, $after: String, $first: Int) {
  issues(filter: $filter, after: $after, first: $first, orderBy: updatedAt) {
    pageInfo { hasNextPage endCursor }
    nodes {
      id identifier title description url priority priorityLabel createdAt updatedAt
      state { name type }
      assignee { id name email }
      team { key name }
      labels { nodes { name color } }
    }
  }
}
GQL;
        $issues = [];
        $after = null;
        for ($page = 0; $page < $maxPages; $page++) {
            $data = $this->query($query, [
                'filter' => (object) $filter,
                'after' => $after,
                'first' => max(1, min(100, $pageSize)),
            ]);
            $connection = $data['issues'] ?? [];
            foreach (($connection['nodes'] ?? []) as $node) {
                if (is_array($node)) {
                    $issues[] = $node;
                }
            }
            $pageInfo = $connection['pageInfo'] ?? [];
            if (empty($pageInfo['hasNextPage']) || empty($pageInfo['endCursor'])) {
                break;
            }
            $after = (string) $pageInfo['endCursor'];
        }
        return $issues;
    }

    /**
     * Load a single issue with the data needed to transition it: its team and
     * that team's workflow states. Returns null when the issue is absent.
     *
     * @return array<string,mixed>|null
     */
    public function issueForTransition(string $issueId): ?array
    {
        $data = $this->query(
            <<<'GQL'
query Issue($id: String!) {
  issue(id: $id) {
    id identifier
    team { id key states { nodes { id name type } } }
  }
}
GQL,
            ['id' => $issueId],
        );
        $issue = $data['issue'] ?? null;
        return is_array($issue) ? $issue : null;
    }

    public function createComment(string $issueId, string $body): void
    {
        $data = $this->query(
            <<<'GQL'
mutation Comment($issueId: String!, $body: String!) {
  commentCreate(input: { issueId: $issueId, body: $body }) { success }
}
GQL,
            ['issueId' => $issueId, 'body' => $body],
        );
        if (empty($data['commentCreate']['success'])) {
            throw new RuntimeException('Linear rejected the comment.');
        }
    }

    public function updateIssueState(string $issueId, string $stateId): void
    {
        $data = $this->query(
            <<<'GQL'
mutation Move($id: String!, $stateId: String!) {
  issueUpdate(id: $id, input: { stateId: $stateId }) { success }
}
GQL,
            ['id' => $issueId, 'stateId' => $stateId],
        );
        if (empty($data['issueUpdate']['success'])) {
            throw new RuntimeException('Linear rejected the state transition.');
        }
    }

    /**
     * Execute a GraphQL operation and return the `data` object. Throws a
     * RuntimeException carrying Linear's message on transport or GraphQL errors.
     *
     * @param array<string,mixed> $variables
     * @return array<string,mixed>
     */
    public function query(string $query, array $variables = []): array
    {
        $payload = json_encode(
            $variables === [] ? ['query' => $query] : ['query' => $query, 'variables' => $variables],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => $this->apiKey,
        ];
        $response = ($this->transport)($this->endpoint, $headers, $payload);
        $status = (int) ($response['status'] ?? 0);
        $decoded = json_decode((string) ($response['body'] ?? ''), true);

        if ($status === 401 || $status === 403) {
            throw new RuntimeException('Linear rejected the API key (HTTP ' . $status . ').');
        }
        if ($status === 429) {
            throw new RuntimeException('Linear rate limit reached. Try again shortly.');
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Linear returned an unreadable response (HTTP ' . $status . ').');
        }
        if (!empty($decoded['errors']) && is_array($decoded['errors'])) {
            $first = $decoded['errors'][0]['message'] ?? 'Unknown GraphQL error.';
            throw new RuntimeException('Linear API error: ' . $first);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Linear request failed (HTTP ' . $status . ').');
        }
        $data = $decoded['data'] ?? null;
        if (!is_array($data)) {
            throw new RuntimeException('Linear response contained no data.');
        }
        return $data;
    }
}
