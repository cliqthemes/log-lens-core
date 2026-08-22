<?php
declare(strict_types=1);

namespace LogLens\Identity;

use LogLens\Http\LogLensRequest;

/**
 * Default resolver: whatever list the transport attached to the request (the
 * Laravel adapter sets it from `log-lens.assignable-users`), or empty when
 * none is supplied (standalone).
 */
final class SystemAssignableUsersResolver implements AssignableUsersResolver
{
    public function resolve(LogLensRequest $request): array
    {
        return $request->assignableUsers();
    }
}
