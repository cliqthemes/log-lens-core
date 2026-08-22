<?php
declare(strict_types=1);

namespace LogLens;

use InvalidArgumentException;
use LogLens\Http\ApiController;
use LogLens\Http\ApplicationApiController;
use LogLens\Http\HealthController;
use LogLens\Http\LogLensRequest;
use LogLens\Http\LogLensResponse;
use LogLens\Services\ApplicationRegistry;
use LogLens\Services\SourcePathRepairService;

/**
 * Wires an inbound request to the right controller: resolves the application,
 * opens its database, repairs relocated workspace paths, and dispatches.
 *
 * Transport-agnostic — the standalone front controller and any framework
 * adapter both build a LogLensRequest, call handle(), and emit the result.
 */
final class Kernel
{
    public function __construct(private readonly string $projectRoot)
    {
    }

    public function handle(LogLensRequest $request): LogLensResponse
    {
        $applications = new ApplicationRegistry($this->projectRoot);

        // Application-agnostic readiness probe, intentionally unauthenticated
        // (non-sensitive: versions + booleans) so external monitors can reach it.
        if (($request->query['api'] ?? null) === 'health') {
            return (new HealthController($this->projectRoot))->handle($request);
        }

        if (($request->query['api'] ?? null) === 'applications') {
            return (new ApplicationApiController($applications))->handle($request);
        }

        try {
            $application = $applications->resolve(
                isset($request->query['app']) ? (string) $request->query['app'] : null
            );
        } catch (InvalidArgumentException $exception) {
            return LogLensResponse::json(['error' => $exception->getMessage()], 422);
        }

        $database = new Database(
            $applications->absolutePath($application, 'database'),
            (string) $application['id'],
        );
        $processedDirectory = $applications->absolutePath($application, 'processed');
        $sourcesDirectory = $applications->absolutePath($application, 'sources');
        (new SourcePathRepairService(
            $database->pdo,
            $processedDirectory,
            $sourcesDirectory,
        ))->repairMovedWorkspacePaths();

        return (new ApiController(
            $database->pdo,
            $applications->absolutePath($application, 'logs'),
            $processedDirectory,
            $sourcesDirectory,
            $this->projectRoot,
            (string) $application['id'],
        ))->handle($request);
    }
}
