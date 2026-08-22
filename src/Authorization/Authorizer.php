<?php
declare(strict_types=1);

namespace LogLens\Authorization;

use LogLens\Identity\Actor;

/**
 * Decides whether an {@see Actor} may perform an ability (C-2). Abilities are
 * dotted strings like `issues.write` or `plugins.manage`; the `.read` suffix
 * marks a read-only ability. Kept as an interface so richer policies (per
 * application membership, custom roles) can replace the default.
 */
interface Authorizer
{
    public function can(Actor $actor, string $ability): bool;
}
