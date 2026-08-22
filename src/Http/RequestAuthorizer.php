<?php
declare(strict_types=1);

namespace LogLens\Http;

use LogLens\Authorization\RoleAuthorizer;
use LogLens\Identity\Actor;
use LogLens\Identity\SystemIdentityResolver;

/**
 * Resolves the request's actor and enforces an ability against it —
 * extracted from `ApiController` so the per-resource
 * endpoint classes under `Http\Endpoints\*` can authorize a mutation without
 * the dispatcher doing it on their behalf.
 */
final class RequestAuthorizer
{
    public static function actor(LogLensRequest $request): Actor
    {
        return (new SystemIdentityResolver())->resolve($request);
    }

    /**
     * Resolve the actor and enforce $ability, or reject with 403. Returns
     * the actor so a caller that also needs e.g. `->label` (to attribute a
     * mutation) doesn't have to resolve it a second time.
     */
    public static function authorize(LogLensRequest $request, string $ability): Actor
    {
        $actor = self::actor($request);
        if (!(new RoleAuthorizer())->can($actor, $ability)) {
            throw new HttpError(403, 'You do not have permission to perform this action.');
        }
        return $actor;
    }
}
