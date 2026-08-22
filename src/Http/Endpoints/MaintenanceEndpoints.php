<?php
declare(strict_types=1);

namespace LogLens\Http\Endpoints;

use LogLens\Http\HttpError;
use LogLens\Http\LogLensRequest;
use LogLens\Http\LogLensResponse;
use LogLens\Http\RequestAuthorizer;
use LogLens\Services\LogImportService;
use LogLens\Services\MaintenanceService;
use LogLens\Services\ProcessedRetentionService;
use PDO;

/**
 * Reindexing and log/processed-file retention — extracted from
 * `ApiController` as its own resource-group class.
 */
final class MaintenanceEndpoints
{
    private readonly LogImportService $importer;
    private readonly MaintenanceService $maintenance;
    private readonly ProcessedRetentionService $retention;

    public function __construct(PDO $db, string $processedDirectory)
    {
        $this->importer = new LogImportService($db);
        $this->maintenance = new MaintenanceService($db);
        $this->retention = new ProcessedRetentionService($processedDirectory);
    }

    public function reindex(LogLensRequest $request): LogLensResponse
    {
        if ($request->method !== 'POST') {
            throw new HttpError(405, 'POST required.');
        }
        // Destructive/irreversible bulk operations are treated as
        // administration, same tier as plugin/settings changes.
        RequestAuthorizer::authorize($request, 'settings.write');
        return LogLensResponse::json($this->importer->rebuildAll());
    }

    public function logDeletionPreview(LogLensRequest $request): LogLensResponse
    {
        if ($request->method !== 'GET') {
            throw new HttpError(405, 'GET required.');
        }
        return LogLensResponse::json($this->maintenance->previewLogDeletion(
            isset($request->query['date']) ? (string) $request->query['date'] : null,
            isset($request->query['source_id']) ? (int) $request->query['source_id'] : null,
        ));
    }

    public function deleteLogs(LogLensRequest $request): LogLensResponse
    {
        if ($request->method !== 'POST') {
            throw new HttpError(405, 'POST required.');
        }
        RequestAuthorizer::authorize($request, 'settings.write');
        $data = $request->body;
        return LogLensResponse::json($this->maintenance->deleteLogs(
            isset($data['date']) ? (string) $data['date'] : null,
            isset($data['source_id']) ? (int) $data['source_id'] : null,
            (string) ($data['confirmation'] ?? ''),
        ));
    }

    public function processedRetention(LogLensRequest $request): LogLensResponse
    {
        if ($request->method === 'GET') {
            return LogLensResponse::json($this->retention->status());
        }
        if ($request->method === 'POST') {
            RequestAuthorizer::authorize($request, 'settings.write');
            return LogLensResponse::json(['status' => $this->retention->status(), 'pruned' => $this->retention->prune()]);
        }
        throw new HttpError(405, 'GET or POST required.');
    }
}
