<?php
declare(strict_types=1);

namespace LogLens\Plugins\Builtin;

use InvalidArgumentException;
use LogLens\Config;
use LogLens\Http\HttpError;
use LogLens\Http\LogLensRequest;
use LogLens\Http\LogLensResponse;
use LogLens\Http\RequestAuthorizer;
use LogLens\Plugins\AbstractPlugin;
use LogLens\Plugins\Ingest\IngestService;
use PDO;

final class HttpIngestPlugin extends AbstractPlugin
{
    public function id(): string
    {
        return 'http_ingest';
    }

    public function name(): string
    {
        return 'HTTP ingest';
    }

    public function description(): string
    {
        return 'Accept pushed log events over HTTP so any app or SDK can report directly.';
    }

    public function category(): string
    {
        return 'Ingestion';
    }

    public function routes(): array
    {
        return [
            // Guard-exempt (its own ingest key, not the dashboard token/same-origin
            // check) — see the action-name check in ApiController::handle().
            'ingest' => fn (PDO $db, LogLensRequest $request, string $applicationId): LogLensResponse => $this->ingest($db, $request),
            'ingest-settings' => fn (PDO $db, LogLensRequest $request, string $applicationId): LogLensResponse => $this->ingestSettings($db, $request, $applicationId),
        ];
    }

    private function ingest(PDO $db, LogLensRequest $request): LogLensResponse
    {
        if ($request->method !== 'POST') {
            throw new HttpError(405, 'POST required.');
        }
        $service = new IngestService($db);
        if (!$service->verify($request->presentedIngestKey())) {
            throw new HttpError(401, 'A valid ingest key is required (X-Log-Lens-Ingest-Key).');
        }
        $result = $service->ingest($request->body, $request->clientIp);
        // A fully rate-limited batch is rejected with 429; a partial one is
        // accepted (202) with a dropped count.
        return LogLensResponse::json($result, !empty($result['rate_limited']) ? 429 : 202);
    }

    private function ingestSettings(PDO $db, LogLensRequest $request, string $applicationId): LogLensResponse
    {
        $service = new IngestService($db);
        if ($request->method === 'GET') {
            // Explicit LOG_LENS_URL wins (e.g. behind a proxy / canonical host);
            // otherwise derive from the actual request so nothing is hardcoded.
            $base = Config::string('LOG_LENS_URL', '') !== ''
                ? Config::string('LOG_LENS_URL', '')
                : $request->baseUrl();
            return LogLensResponse::json($service->settings($applicationId, $base));
        }
        if ($request->method === 'POST') {
            RequestAuthorizer::authorize($request, 'settings.write');
            if ((string) ($request->body['action'] ?? '') !== 'regenerate') {
                throw new InvalidArgumentException('Unsupported action.');
            }
            return LogLensResponse::json($service->regenerateKey());
        }
        throw new HttpError(405, 'GET or POST required.');
    }
}
