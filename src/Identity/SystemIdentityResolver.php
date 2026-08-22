<?php
declare(strict_types=1);

namespace LogLens\Identity;

use LogLens\Http\LogLensRequest;

/**
 * Default resolver: uses the actor the transport attached to the request (the
 * Laravel adapter sets it from the host user), falling back to the implied
 * single local owner when none is supplied (standalone). A future backend can
 * implement {@see IdentityResolver} differently without changing callers.
 */
final class SystemIdentityResolver implements IdentityResolver
{
    public function resolve(LogLensRequest $request): Actor
    {
        return $request->actor() ?? Actor::localOwner();
    }
}
