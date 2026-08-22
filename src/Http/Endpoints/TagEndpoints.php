<?php
declare(strict_types=1);

namespace LogLens\Http\Endpoints;

use InvalidArgumentException;
use LogLens\Http\HttpError;
use LogLens\Http\LogLensRequest;
use LogLens\Http\LogLensResponse;
use LogLens\Http\RequestAuthorizer;
use LogLens\Services\TagService;
use PDO;

/**
 * The global tag catalog (create/list/update/delete) — extracted from
 * `ApiController` as its own resource-group class.
 * Assigning/removing a tag on a specific issue is `IssueEndpoints::issueTags()`;
 * this is the catalog those assignments point into.
 */
final class TagEndpoints
{
    private readonly TagService $tags;

    public function __construct(PDO $db)
    {
        $this->tags = new TagService($db);
    }

    public function tags(LogLensRequest $request): LogLensResponse
    {
        $method = $request->method;
        if ($method === 'GET') {
            return LogLensResponse::json(['data' => $this->tags->all()]);
        }
        $actor = RequestAuthorizer::authorize($request, 'tags.write');
        $data = $request->body;
        if ($method === 'POST') {
            return LogLensResponse::json($this->tags->create(
                (string) ($data['name'] ?? ''),
                (string) ($data['color'] ?? '#64748b'),
                (string) ($data['icon'] ?? 'tag'),
                (string) ($data['match_word'] ?? ''),
                $actor->label,
            ), 201);
        }
        $tagId = (int) ($data['id'] ?? $request->query['id'] ?? 0);
        if ($tagId < 1) {
            throw new InvalidArgumentException('A valid tag id is required.');
        }
        if (in_array($method, ['PUT', 'PATCH'], true)) {
            return LogLensResponse::json($this->tags->update(
                $tagId,
                (string) ($data['name'] ?? ''),
                (string) ($data['color'] ?? '#64748b'),
                (string) ($data['icon'] ?? 'tag'),
                (string) ($data['match_word'] ?? ''),
                $actor->label,
            ));
        }
        if ($method === 'DELETE') {
            $this->tags->delete($tagId);
            return LogLensResponse::json(['id' => $tagId, 'deleted' => true]);
        }
        throw new HttpError(405, 'GET, POST, PATCH, PUT, or DELETE required.');
    }
}
