<?php
declare(strict_types=1);

namespace LogLens\Plugins\Builtin;

use LogLens\Http\HttpError;
use LogLens\Http\LogLensRequest;
use LogLens\Http\LogLensResponse;
use LogLens\Http\RequestAuthorizer;
use LogLens\Plugins\AbstractPlugin;
use LogLens\Plugins\Alerts\AlertService;
use PDO;

final class AlertsPlugin extends AbstractPlugin
{
    public function id(): string
    {
        return 'alerts';
    }

    public function name(): string
    {
        return 'Alerting';
    }

    public function description(): string
    {
        return 'Notify Slack, Discord, or a webhook on new errors, reoccurrences, and spikes.';
    }

    public function category(): string
    {
        return 'Notifications';
    }

    public function onIngest(PDO $db): void
    {
        (new AlertService($db))->scan();
    }

    public function routes(): array
    {
        return [
            'alert-channels' => fn (PDO $db, LogLensRequest $request, string $applicationId): LogLensResponse => $this->channels($db, $request),
            'alert-rules' => fn (PDO $db, LogLensRequest $request, string $applicationId): LogLensResponse => $this->rules($db, $request),
            'alert-test' => fn (PDO $db, LogLensRequest $request, string $applicationId): LogLensResponse => $this->test($db, $request),
            'alert-events' => fn (PDO $db, LogLensRequest $request, string $applicationId): LogLensResponse => $this->events($db, $request),
        ];
    }

    private function channels(PDO $db, LogLensRequest $request): LogLensResponse
    {
        $service = new AlertService($db);
        $method = $request->method;
        if ($method === 'GET') {
            return LogLensResponse::json(['data' => $service->channels()]);
        }
        // Channel config carries an outbound webhook URL (potential
        // exfil/SSRF vector) — treat it as administration.
        RequestAuthorizer::authorize($request, 'settings.write');
        $data = $request->body;
        if ($method === 'POST') {
            return LogLensResponse::json($service->createChannel($data), 201);
        }
        $id = (int) ($data['id'] ?? $request->query['id'] ?? 0);
        if (in_array($method, ['PATCH', 'PUT'], true)) {
            return LogLensResponse::json($service->updateChannel($id, $data));
        }
        if ($method === 'DELETE') {
            $service->deleteChannel($id);
            return LogLensResponse::json(['id' => $id, 'deleted' => true]);
        }
        throw new HttpError(405, 'GET, POST, PATCH, PUT, or DELETE required.');
    }

    private function rules(PDO $db, LogLensRequest $request): LogLensResponse
    {
        $service = new AlertService($db);
        $method = $request->method;
        if ($method === 'GET') {
            return LogLensResponse::json(['data' => $service->rules()]);
        }
        RequestAuthorizer::authorize($request, 'settings.write');
        $data = $request->body;
        if ($method === 'POST') {
            return LogLensResponse::json($service->createRule($data), 201);
        }
        $id = (int) ($data['id'] ?? $request->query['id'] ?? 0);
        if (in_array($method, ['PATCH', 'PUT'], true)) {
            return LogLensResponse::json($service->updateRule($id, $data));
        }
        if ($method === 'DELETE') {
            $service->deleteRule($id);
            return LogLensResponse::json(['id' => $id, 'deleted' => true]);
        }
        throw new HttpError(405, 'GET, POST, PATCH, PUT, or DELETE required.');
    }

    private function test(PDO $db, LogLensRequest $request): LogLensResponse
    {
        if ($request->method !== 'POST') {
            throw new HttpError(405, 'POST required.');
        }
        RequestAuthorizer::authorize($request, 'settings.write');
        return LogLensResponse::json(
            (new AlertService($db))->testChannel((int) ($request->body['id'] ?? 0))
        );
    }

    private function events(PDO $db, LogLensRequest $request): LogLensResponse
    {
        if ($request->method !== 'GET') {
            throw new HttpError(405, 'GET required.');
        }
        return LogLensResponse::json([
            'data' => (new AlertService($db))->events((int) ($request->query['limit'] ?? 25)),
        ]);
    }
}
