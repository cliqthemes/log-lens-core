<?php
declare(strict_types=1);

namespace LogLens\Authorization;

use LogLens\Identity\Actor;

/**
 * The default role policy:
 *   - owner  — everything;
 *   - editor — every ability except administration, plus reads of everything;
 *   - viewer — read-only abilities (those whose name ends in `.read`).
 *
 * "Administration" is the set of abilities that reconfigure the instance rather
 * than working inside it: plugin enablement, settings, and provisioning an
 * application (which creates a whole new tenant — its own database and
 * directories). Those stay owner-only; reading them does not.
 *
 * An actor is granted an ability if any of its roles grants it.
 */
final class RoleAuthorizer implements Authorizer
{
    /** Ability prefixes reserved to owners for writes. */
    private const ADMINISTRATIVE = ['plugins.', 'settings.', 'applications.'];

    public function can(Actor $actor, string $ability): bool
    {
        foreach ($actor->roles as $role) {
            if ($this->roleGrants($role, $ability)) {
                return true;
            }
        }
        return false;
    }

    private function roleGrants(string $role, string $ability): bool
    {
        return match ($role) {
            Actor::ROLE_OWNER => true,
            Actor::ROLE_EDITOR => $this->isRead($ability) || !$this->isAdministrative($ability),
            Actor::ROLE_VIEWER => $this->isRead($ability),
            default => false,
        };
    }

    private function isRead(string $ability): bool
    {
        return str_ends_with($ability, '.read');
    }

    private function isAdministrative(string $ability): bool
    {
        foreach (self::ADMINISTRATIVE as $prefix) {
            if (str_starts_with($ability, $prefix)) {
                return true;
            }
        }
        return false;
    }
}
