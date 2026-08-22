<?php
declare(strict_types=1);

namespace LogLens\Http;

use InvalidArgumentException;
use LogLens\Domain\NotFoundException;
use LogLens\Http\Endpoints\ConnectorEndpoints;
use LogLens\Http\Endpoints\IssueEndpoints;
use LogLens\Http\Endpoints\MaintenanceEndpoints;
use LogLens\Http\Endpoints\ModuleEndpoints;
use LogLens\Http\Endpoints\TagEndpoints;
use LogLens\Plugins\PluginManager;
use LogLens\Services\IngestionSettingsService;
use PDO;
use RuntimeException;

/**
 * Transport-agnostic API dispatcher: resolves a `?api=` action to its
 * handler and maps exceptions to an HTTP status.
 *
 * Endpoint logic lives in one small class per resource group under
 * `Http\Endpoints\*` (this class used to be an 827-line
 * god-object owning routing, guarding, and every handler body itself). This
 * class now only routes, enforces the request guard, and translates
 * exceptions — it does not know how an issue list is assembled or a
 * connector sync is started. `ingestion-settings` and `plugins` stay here:
 * both are global workspace configuration, not a CRUD resource, so they
 * don't warrant their own class. Plugin-owned actions (alerts, HTTP ingest,
 * releases, access analytics, Linear) never appear here at all — see
 * {@see \LogLens\Plugins\PluginContract::routes()} and
 * {@see PluginManager::dispatch()} (F1).
 */
final class ApiController
{
    private readonly IssueEndpoints $issueEndpoints;
    private readonly TagEndpoints $tagEndpoints;
    private readonly ModuleEndpoints $moduleEndpoints;
    private readonly ConnectorEndpoints $connectorEndpoints;
    private readonly MaintenanceEndpoints $maintenanceEndpoints;
    private readonly PluginManager $plugins;
    private LogLensRequest $request;

    public function __construct(
        private readonly PDO $db,
        private readonly string $logsDirectory,
        private readonly string $processedDirectory,
        private readonly string $sourcesDirectory,
        private readonly string $projectRoot = '',
        private readonly string $applicationId = 'default',
    ) {
        $this->issueEndpoints = new IssueEndpoints($db);
        $this->tagEndpoints = new TagEndpoints($db);
        $this->moduleEndpoints = new ModuleEndpoints($db);
        $this->connectorEndpoints = new ConnectorEndpoints(
            $db,
            $logsDirectory,
            $processedDirectory,
            $sourcesDirectory,
            $projectRoot,
            $applicationId,
        );
        $this->maintenanceEndpoints = new MaintenanceEndpoints($db, $processedDirectory);
        $this->plugins = new PluginManager($db);
    }

    public function handle(LogLensRequest $request): LogLensResponse
    {
        $this->request = $request;
        $action = (string) ($request->query['api'] ?? '');
        // The Linear webhook (HMAC-signed) and the HTTP ingest endpoint (its own
        // push key) authenticate themselves, so they are exempt from the
        // dashboard API-key / same-origin guard — external senders present
        // neither a dashboard token nor an Origin header.
        if ($action !== 'linear-webhook' && $action !== 'ingest') {
            $blocked = RequestGuard::check($request);
            if ($blocked !== null) {
                return $blocked;
            }
        }
        try {
            return match ($action) {
                'summary' => $this->issueEndpoints->summary(),
                'sources' => $this->issueEndpoints->sources($request),
                'groups' => $this->issueEndpoints->groups($request),
                'errors', 'issues' => $this->issueEndpoints->errors($request),
                'group' => $this->issueEndpoints->group($request, publicShape: false),
                'error' => $this->issueEndpoints->group($request, publicShape: true),
                'source' => $this->issueEndpoints->source($request),
                'issue-status' => $this->issueEndpoints->issueStatus($request),
                'tags' => $this->tagEndpoints->tags($request),
                'issue-tags' => $this->issueEndpoints->issueTags($request),
                'assign-issue' => $this->issueEndpoints->assignIssue($request),
                'assignable-users' => $this->issueEndpoints->assignableUsers($request),
                'bulk-issues' => $this->issueEndpoints->bulkIssues($request),
                'modules' => $this->moduleEndpoints->modules($request),
                'connectors' => $this->connectorEndpoints->connectors($request),
                'connector-test' => $this->connectorEndpoints->test($request),
                'connector-preview' => $this->connectorEndpoints->preview($request),
                'connector-sync' => $this->connectorEndpoints->sync($request),
                'connector-runs' => $this->connectorEndpoints->runs($request),
                'import-incoming' => $this->connectorEndpoints->importIncoming($request),
                'ingestion-settings' => $this->ingestionSettings(),
                'plugins' => $this->pluginsEndpoint(),
                'reindex' => $this->maintenanceEndpoints->reindex($request),
                'log-deletion-preview' => $this->maintenanceEndpoints->logDeletionPreview($request),
                'delete-logs' => $this->maintenanceEndpoints->deleteLogs($request),
                'processed-retention' => $this->maintenanceEndpoints->processedRetention($request),
                // Every plugin-owned endpoint (alerts, HTTP ingest, releases,
                // access analytics, Linear) is dispatched from here rather than
                // hardcoded as its own arm — see PluginContract::routes()
                // and PluginManager::dispatch().
                default => $this->plugins->dispatch($action, $this->db, $this->request, $this->applicationId)
                    ?? LogLensResponse::json(['error' => 'Unknown endpoint.'], 404),
            };
        } catch (HttpError $exception) {
            return LogLensResponse::json(['error' => $exception->getMessage()], $exception->status);
        } catch (InvalidArgumentException $exception) {
            return LogLensResponse::json(['error' => $exception->getMessage()], 422);
        } catch (NotFoundException $exception) {
            return LogLensResponse::json(['error' => $exception->getMessage()], 404);
        } catch (\PDOException $exception) {
            // PDOException extends RuntimeException, so without this arm a database
            // fault fell through to the 400 below and echoed the driver's message —
            // failing SQL, table and column names, the database file path — straight
            // back to the caller. It is never a client error: log it, answer 500.
            error_log((string) $exception);
            return LogLensResponse::json(['error' => 'A database error occurred. Check the PHP error log.'], 500);
        } catch (RuntimeException $exception) {
            // Deliberately user-facing: the operational failures the endpoints and
            // services raise ("Connector is disabled.", "Linear rejected the API
            // key (HTTP 401).") are what the dashboard shows the operator.
            return LogLensResponse::json(['error' => $exception->getMessage()], 400);
        } catch (\Throwable $exception) {
            error_log((string) $exception);
            return LogLensResponse::json(['error' => 'Unexpected server error. Check the PHP error log.'], 500);
        }
    }

    /** Global workspace setting, not a CRUD resource — stays on the dispatcher. */
    private function ingestionSettings(): LogLensResponse
    {
        $settings = new IngestionSettingsService($this->db);
        if ($this->request->method === 'GET') {
            return LogLensResponse::json($settings->configuration());
        }
        if (in_array($this->request->method, ['PUT', 'PATCH', 'POST'], true)) {
            RequestAuthorizer::authorize($this->request, 'settings.write');
            $data = $this->request->body;
            if (!isset($data['severities']) || !is_array($data['severities'])) {
                throw new InvalidArgumentException('severities must be an array.');
            }
            return LogLensResponse::json($settings->update($data['severities']));
        }
        throw new HttpError(405, 'GET, POST, PATCH, or PUT required.');
    }

    /** Global workspace setting, not a CRUD resource — stays on the dispatcher. */
    private function pluginsEndpoint(): LogLensResponse
    {
        if ($this->request->method === 'GET') {
            return LogLensResponse::json(['data' => $this->plugins->catalog()]);
        }
        if (in_array($this->request->method, ['POST', 'PUT', 'PATCH'], true)) {
            RequestAuthorizer::authorize($this->request, 'plugins.manage');
            $data = $this->request->body;
            $id = (string) ($data['id'] ?? '');
            if ($id === '' || !array_key_exists('enabled', $data)) {
                throw new InvalidArgumentException('id and enabled are required.');
            }
            return LogLensResponse::json($this->plugins->setEnabled($id, (bool) $data['enabled']));
        }
        throw new HttpError(405, 'GET, POST, PATCH, or PUT required.');
    }
}
