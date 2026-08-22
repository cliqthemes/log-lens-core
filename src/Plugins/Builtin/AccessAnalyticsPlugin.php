<?php
declare(strict_types=1);

namespace LogLens\Plugins\Builtin;

use LogLens\Http\HttpError;
use LogLens\Http\LogLensRequest;
use LogLens\Http\LogLensResponse;
use LogLens\Plugins\AbstractPlugin;
use LogLens\Plugins\Analytics\AnalyticsService;
use PDO;

final class AccessAnalyticsPlugin extends AbstractPlugin
{
    public function id(): string
    {
        return 'access_analytics';
    }

    public function name(): string
    {
        return 'Access-log analytics';
    }

    public function description(): string
    {
        return 'Turn parsed nginx access logs into a traffic, status-code, and top-path view.';
    }

    public function category(): string
    {
        return 'Insights';
    }

    public function routes(): array
    {
        return [
            'access-analytics' => fn (PDO $db, LogLensRequest $request, string $applicationId): LogLensResponse => $this->summary($db, $request),
        ];
    }

    private function summary(PDO $db, LogLensRequest $request): LogLensResponse
    {
        if ($request->method !== 'GET') {
            throw new HttpError(405, 'GET required.');
        }
        return LogLensResponse::json(
            (new AnalyticsService($db))->summary((int) ($request->query['days'] ?? 7))
        );
    }
}
