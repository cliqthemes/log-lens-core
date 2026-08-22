<?php
declare(strict_types=1);

namespace LogLens\Plugins\Builtin;

use LogLens\Http\HttpError;
use LogLens\Http\LogLensRequest;
use LogLens\Http\LogLensResponse;
use LogLens\Http\RequestAuthorizer;
use LogLens\Plugins\AbstractPlugin;
use LogLens\Services\LinearSettingsService;
use LogLens\Services\LinearSyncService;
use PDO;

final class LinearPlugin extends AbstractPlugin
{
    public function id(): string
    {
        return 'linear';
    }

    public function name(): string
    {
        return 'Linear';
    }

    public function description(): string
    {
        return 'Pull Linear issues by label or assignee into Log Lens and mirror status changes back.';
    }

    public function category(): string
    {
        return 'Integration';
    }

    public function defaultEnabled(): bool
    {
        return true;
    }

    /**
     * Mirror a status change to the linked Linear issue, if write-back is
     * configured (this used to be inlined into the core
     * `issue-status` endpoint, coupling it directly to Linear).
     */
    public function onIssueStatusChanged(PDO $db, int $groupId, string $status, string $note): ?array
    {
        $settings = new LinearSettingsService($db);
        if (!$settings->statusWritebackEnabled()) {
            return null;
        }
        $writeback = (new LinearSyncService($db, $settings))->onStatusChanged($groupId, $status, $note);
        return ($writeback['attempted'] ?? false) === true ? $writeback : null;
    }

    public function routes(): array
    {
        return [
            'linear-settings' => fn (PDO $db, LogLensRequest $request, string $applicationId): LogLensResponse => $this->settings($db, $request),
            'linear-test' => fn (PDO $db, LogLensRequest $request, string $applicationId): LogLensResponse => $this->test($db, $request),
            'linear-sync' => fn (PDO $db, LogLensRequest $request, string $applicationId): LogLensResponse => $this->sync($db, $request),
            // Guard-exempt (HMAC-signed by Linear, not the dashboard token/same-origin
            // check) — see the action-name check in ApiController::handle().
            'linear-webhook' => fn (PDO $db, LogLensRequest $request, string $applicationId): LogLensResponse => $this->webhook($db, $request),
        ];
    }

    private function settings(PDO $db, LogLensRequest $request): LogLensResponse
    {
        $settings = new LinearSettingsService($db);
        if ($request->method === 'GET') {
            return LogLensResponse::json($settings->configuration());
        }
        if (in_array($request->method, ['PUT', 'PATCH', 'POST'], true)) {
            // Stores the Linear API key / webhook secret — administration.
            RequestAuthorizer::authorize($request, 'settings.write');
            return LogLensResponse::json($settings->update($request->body));
        }
        throw new HttpError(405, 'GET, POST, PATCH, or PUT required.');
    }

    private function test(PDO $db, LogLensRequest $request): LogLensResponse
    {
        if ($request->method !== 'POST') {
            throw new HttpError(405, 'POST required.');
        }
        RequestAuthorizer::authorize($request, 'settings.write');
        return LogLensResponse::json(
            (new LinearSyncService($db, new LinearSettingsService($db)))->test()
        );
    }

    private function sync(PDO $db, LogLensRequest $request): LogLensResponse
    {
        if ($request->method !== 'POST') {
            throw new HttpError(405, 'POST required.');
        }
        // A manual re-pull, not a configuration change — routine operational
        // action, open to editor+owner like other write actions.
        RequestAuthorizer::authorize($request, 'linear.write');
        return LogLensResponse::json(
            (new LinearSyncService($db, new LinearSettingsService($db)))->sync()
        );
    }

    private function webhook(PDO $db, LogLensRequest $request): LogLensResponse
    {
        if ($request->method !== 'POST') {
            throw new HttpError(405, 'POST required.');
        }
        $settings = new LinearSettingsService($db);
        if (!$settings->isEnabled()) {
            throw new HttpError(404, 'The Linear integration is not enabled.');
        }
        $secret = $settings->webhookSecret();
        if ($secret === '') {
            throw new HttpError(400, 'No Linear webhook secret is configured.');
        }
        $raw = $request->rawBody;
        if (!is_string($raw)) {
            throw new HttpError(400, 'The webhook payload could not be read for verification.');
        }
        $expected = hash_hmac('sha256', $raw, $secret);
        $presented = $request->header('linear-signature');
        if ($presented === '' || !hash_equals($expected, $presented)) {
            throw new HttpError(401, 'Invalid Linear webhook signature.');
        }
        // A verified webhook simply triggers a pull; Linear's payload types
        // (Issue create/update) all resolve to the same idempotent re-sync.
        $result = (new LinearSyncService($db, $settings))->sync();
        return LogLensResponse::json(['ok' => true, 'synced' => $result]);
    }
}
