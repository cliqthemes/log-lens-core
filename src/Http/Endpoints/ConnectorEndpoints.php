<?php
declare(strict_types=1);

namespace LogLens\Http\Endpoints;

use LogLens\Http\HttpError;
use LogLens\Http\LogLensRequest;
use LogLens\Http\LogLensResponse;
use LogLens\Http\RequestAuthorizer;
use LogLens\Services\BackgroundSyncService;
use LogLens\Services\ConnectorService;
use LogLens\Services\ConnectorSyncService;
use LogLens\Services\LogImportService;
use PDO;
use RuntimeException;

/**
 * Log source connectors: CRUD, connection test, and (background-worker-driven)
 * sync/preview/history — extracted from `ApiController` as its own
 * resource-group class.
 */
final class ConnectorEndpoints
{
    private readonly ConnectorService $connectors;
    private readonly ConnectorSyncService $sync;
    private readonly LogImportService $importer;

    public function __construct(
        private readonly PDO $db,
        private readonly string $logsDirectory,
        private readonly string $processedDirectory,
        private readonly string $sourcesDirectory,
        private readonly string $projectRoot,
        private readonly string $applicationId,
    ) {
        $this->connectors = new ConnectorService($db, stateDirectory: $sourcesDirectory);
        $this->sync = new ConnectorSyncService($db, $sourcesDirectory);
        $this->importer = new LogImportService($db);
    }

    public function connectors(LogLensRequest $request): LogLensResponse
    {
        $method = $request->method;
        if ($method === 'GET') {
            return LogLensResponse::json(['data' => $this->connectors->all()]);
        }
        // Connector CRUD stores SSH host/credentials — treat it as
        // configuration, same tier as plugin/settings administration.
        RequestAuthorizer::authorize($request, 'settings.write');
        $data = $request->body;
        if ($method === 'POST') {
            return LogLensResponse::json($this->connectors->create($data), 201);
        }
        $id = (int) ($data['id'] ?? $request->query['id'] ?? 0);
        if (in_array($method, ['PATCH', 'PUT'], true)) {
            return LogLensResponse::json($this->connectors->update($id, $data));
        }
        if ($method === 'DELETE') {
            $this->connectors->delete($id);
            return LogLensResponse::json(['id' => $id, 'deleted' => true]);
        }
        throw new HttpError(405, 'GET, POST, PATCH, PUT, or DELETE required.');
    }

    public function test(LogLensRequest $request): LogLensResponse
    {
        if ($request->method !== 'POST') {
            throw new HttpError(405, 'POST required.');
        }
        RequestAuthorizer::authorize($request, 'connectors.write');
        $data = $request->body;
        return LogLensResponse::json($this->connectors->test((int) ($data['id'] ?? 0)));
    }

    public function sync(LogLensRequest $request): LogLensResponse
    {
        if ($request->method !== 'POST') {
            throw new HttpError(405, 'POST required.');
        }
        RequestAuthorizer::authorize($request, 'connectors.write');
        $data = $request->body;
        $id = (int) ($data['id'] ?? 0);
        $result = (new BackgroundSyncService(
            $this->projectRoot,
            $this->applicationId,
            $this->sourcesDirectory,
            $this->sync,
        ))->start(
            $id > 0 ? $id : null,
            isset($data['snapshot']) ? (string) $data['snapshot'] : null,
            isset($data['snapshots']) && is_array($data['snapshots']) ? $data['snapshots'] : null,
        );
        return LogLensResponse::json($result, 202);
    }

    public function preview(LogLensRequest $request): LogLensResponse
    {
        if ($request->method !== 'POST') {
            throw new HttpError(405, 'POST required.');
        }
        RequestAuthorizer::authorize($request, 'connectors.write');
        $data = $request->body;
        $id = (int) ($data['id'] ?? 0);
        return LogLensResponse::json($id > 0 ? $this->sync->preview($id) : $this->sync->previewAll());
    }

    public function runs(LogLensRequest $request): LogLensResponse
    {
        if ($request->method !== 'GET') {
            throw new HttpError(405, 'GET required.');
        }
        return LogLensResponse::json([
            'data' => $this->sync->history((int) ($request->query['limit'] ?? 25)),
        ]);
    }

    public function importIncoming(LogLensRequest $request): LogLensResponse
    {
        if ($request->method !== 'POST') {
            throw new HttpError(405, 'POST required.');
        }
        RequestAuthorizer::authorize($request, 'connectors.write');
        $lock = $this->acquireImportLock();
        try {
            $result = $this->importer->importIncoming($this->logsDirectory, $this->processedDirectory);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return LogLensResponse::json($result);
    }

    /** @return resource */
    private function acquireImportLock()
    {
        $directory = rtrim($this->sourcesDirectory, '/') . '/.locks';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the import lock directory.');
        }
        $handle = fopen($directory . '/import-incoming.lock', 'c');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('An incoming import is already running for this application.');
        }
        return $handle;
    }
}
