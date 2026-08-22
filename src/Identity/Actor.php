<?php
declare(strict_types=1);

namespace LogLens\Identity;

/**
 * Who is performing a request (C-2).
 *
 * Log Lens has no built-in login: standalone is gated by one shared token, and
 * the Laravel embed sits behind the host's auth. This value object is the seam
 * that lets identity be attributed and authorized uniformly regardless — the
 * standalone transport yields a single local owner, while the Laravel adapter
 * builds an Actor from the host's authenticated user. Because it is a
 * transport-provided object (never derived from client-supplied headers), it
 * cannot be spoofed by a caller.
 */
final class Actor
{
    public const ROLE_OWNER = 'owner';
    public const ROLE_EDITOR = 'editor';
    public const ROLE_VIEWER = 'viewer';

    /**
     * @param list<string> $roles
     * @param string $source Where the identity came from: 'system' | 'host'.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly array $roles,
        public readonly string $source = 'system',
    ) {
    }

    /**
     * The implied single owner used when no richer identity is available (the
     * standalone default: whoever holds the token is the owner).
     */
    public static function localOwner(): self
    {
        return new self('local', 'Local', [self::ROLE_OWNER], 'system');
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function isOwner(): bool
    {
        return $this->hasRole(self::ROLE_OWNER);
    }
}
