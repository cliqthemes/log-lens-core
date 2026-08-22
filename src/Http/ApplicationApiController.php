<?php
declare(strict_types=1);

namespace LogLens\Http;

use InvalidArgumentException;
use LogLens\Services\ApplicationRegistry;

/**
 * The one application-agnostic authenticated route: list the applications, or
 * create one. Dispatched by {@see \LogLens\Kernel} *before* an application is
 * resolved, so the actor is resolved with a null application id — an identity
 * callable that scopes roles per application is told "no application yet" and
 * should answer with the caller's instance-wide roles (see the Laravel
 * adapter's `log-lens.identity` docblock).
 */
final class ApplicationApiController
{
    public function __construct(private readonly ApplicationRegistry $applications)
    {
    }

    public function handle(LogLensRequest $request): LogLensResponse
    {
        $blocked = RequestGuard::check($request);
        if ($blocked !== null) {
            return $blocked;
        }
        try {
            if ($request->method === 'GET') {
                // Read-only: a viewer needs the list to populate the app switcher.
                RequestAuthorizer::authorize($request, 'applications.read');
                return LogLensResponse::json(['data' => $this->applications->all()]);
            }
            if ($request->method === 'POST') {
                // Creating an application provisions a whole new tenant (its own
                // database and directories), so it is owner-only — see
                // RoleAuthorizer's administrative-ability rule.
                RequestAuthorizer::authorize($request, 'applications.write');
                return LogLensResponse::json($this->applications->create(
                    (string) ($request->body['name'] ?? ''),
                    (string) ($request->body['id'] ?? ''),
                ), 201);
            }
            return LogLensResponse::json(['error' => 'GET or POST required.'], 405);
        } catch (HttpError $exception) {
            return LogLensResponse::json(['error' => $exception->getMessage()], $exception->status);
        } catch (InvalidArgumentException $exception) {
            // Deliberate: every InvalidArgumentException the registry raises on
            // this path is a validation message written to be shown.
            return LogLensResponse::json(['error' => $exception->getMessage()], 422);
        } catch (\Throwable $exception) {
            error_log((string) $exception);
            return LogLensResponse::json(['error' => 'Unexpected server error. Check the PHP error log.'], 500);
        }
    }
}
