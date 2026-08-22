<?php
declare(strict_types=1);

namespace LogLens\Plugins\Builtin;

use InvalidArgumentException;
use LogLens\Http\HttpError;
use LogLens\Http\LogLensRequest;
use LogLens\Http\LogLensResponse;
use LogLens\Http\RequestAuthorizer;
use LogLens\Plugins\AbstractPlugin;
use LogLens\Plugins\Releases\ReleaseService;
use LogLens\Plugins\Releases\SourceMapResolver;
use LogLens\Plugins\Releases\SourceMapService;
use PDO;

final class ReleasesPlugin extends AbstractPlugin
{
    public function id(): string
    {
        return 'releases';
    }

    public function name(): string
    {
        return 'Release tracking';
    }

    public function description(): string
    {
        return 'Tag occurrences with a version or commit for regressions and deploy markers.';
    }

    public function category(): string
    {
        return 'Insights';
    }

    public function onResolveStack(PDO $db, string $stack, ?string $release): ?string
    {
        if ($release === null || $release === '') {
            return null;
        }
        return (new SourceMapResolver($db))->resolveStack($stack, $release);
    }

    public function routes(): array
    {
        return [
            'deploys' => fn (PDO $db, LogLensRequest $request, string $applicationId): LogLensResponse => $this->deploys($db, $request),
            'release-settings' => fn (PDO $db, LogLensRequest $request, string $applicationId): LogLensResponse => $this->releaseSettings($db, $request),
            'issue-releases' => fn (PDO $db, LogLensRequest $request, string $applicationId): LogLensResponse => $this->issueReleases($db, $request),
            'source-maps' => fn (PDO $db, LogLensRequest $request, string $applicationId): LogLensResponse => $this->sourceMaps($db, $request),
        ];
    }

    private function deploys(PDO $db, LogLensRequest $request): LogLensResponse
    {
        $service = new ReleaseService($db);
        $method = $request->method;
        if ($method === 'GET') {
            return LogLensResponse::json([
                'data' => $service->deploys((int) ($request->query['limit'] ?? 50)),
                'markers' => $service->markers((int) ($request->query['days'] ?? 30)),
            ]);
        }
        RequestAuthorizer::authorize($request, 'releases.write');
        $data = $request->body;
        if ($method === 'POST') {
            return LogLensResponse::json($service->createDeploy($data), 201);
        }
        if ($method === 'DELETE') {
            $id = (int) ($data['id'] ?? $request->query['id'] ?? 0);
            $service->deleteDeploy($id);
            return LogLensResponse::json(['id' => $id, 'deleted' => true]);
        }
        throw new HttpError(405, 'GET, POST, or DELETE required.');
    }

    private function releaseSettings(PDO $db, LogLensRequest $request): LogLensResponse
    {
        $service = new ReleaseService($db);
        if ($request->method === 'GET') {
            return LogLensResponse::json($service->settings());
        }
        if (in_array($request->method, ['PUT', 'PATCH', 'POST'], true)) {
            // The code-host link template is app-wide configuration, not a
            // per-deploy operational action — gate it like other settings.
            RequestAuthorizer::authorize($request, 'settings.write');
            return LogLensResponse::json($service->updateSettings($request->body));
        }
        throw new HttpError(405, 'GET, POST, PATCH, or PUT required.');
    }

    private function issueReleases(PDO $db, LogLensRequest $request): LogLensResponse
    {
        if ($request->method !== 'GET') {
            throw new HttpError(405, 'GET required.');
        }
        $id = (int) ($request->query['id'] ?? 0);
        if ($id < 1) {
            throw new InvalidArgumentException('A valid issue id is required.');
        }
        return LogLensResponse::json((new ReleaseService($db))->forGroup($id));
    }

    private function sourceMaps(PDO $db, LogLensRequest $request): LogLensResponse
    {
        $service = new SourceMapService($db);
        $method = $request->method;
        if ($method === 'GET') {
            return LogLensResponse::json(['data' => $service->all()]);
        }
        RequestAuthorizer::authorize($request, 'releases.write');
        if ($method === 'POST') {
            return LogLensResponse::json($service->upload($request->body), 201);
        }
        if ($method === 'DELETE') {
            $id = (int) ($request->body['id'] ?? $request->query['id'] ?? 0);
            $service->delete($id);
            return LogLensResponse::json(['id' => $id, 'deleted' => true]);
        }
        throw new HttpError(405, 'GET, POST, or DELETE required.');
    }
}
