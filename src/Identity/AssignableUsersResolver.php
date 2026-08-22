<?php
declare(strict_types=1);

namespace LogLens\Identity;

use LogLens\Http\LogLensRequest;

/**
 * Resolves who an issue can be assigned to for a request (C-2). Log Lens has
 * no user table of its own, so this is an opaque id/label list from whoever
 * owns identity — a Laravel host's users, scoped to the current application —
 * not a lookup Log Lens performs itself. Standalone has no meaningful list
 * (it has only the local owner), so it resolves to empty; assignment there is
 * effectively unavailable, matching C-2's Laravel-hosted-only scope for
 * multi-user collaboration.
 */
interface AssignableUsersResolver
{
    /** @return list<array{id:string,label:string}> */
    public function resolve(LogLensRequest $request): array;
}
