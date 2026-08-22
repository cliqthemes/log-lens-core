<?php
declare(strict_types=1);

namespace LogLens\Identity;

use LogLens\Http\LogLensRequest;

/**
 * Resolves the {@see Actor} for a request. The seam that makes a real
 * identity backend (a user table, SSO, per-application membership) a drop-in
 * later, without touching call sites (C-2).
 */
interface IdentityResolver
{
    public function resolve(LogLensRequest $request): Actor;
}
