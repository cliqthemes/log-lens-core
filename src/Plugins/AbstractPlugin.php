<?php
declare(strict_types=1);

namespace LogLens\Plugins;

use PDO;

/**
 * Convenience base for {@see PluginContract} implementations: identity methods
 * stay abstract (each plugin must state them) while the optional bits default
 * to the common case — an active, off-by-default feature with no ingest hook.
 */
abstract class AbstractPlugin implements PluginContract
{
    abstract public function id(): string;

    abstract public function name(): string;

    abstract public function description(): string;

    abstract public function category(): string;

    public function status(): string
    {
        return 'active';
    }

    public function defaultEnabled(): bool
    {
        return false;
    }

    public function onIngest(PDO $db): void
    {
        // No-op by default.
    }

    public function routes(): array
    {
        return [];
    }

    public function onResolveStack(PDO $db, string $stack, ?string $release): ?string
    {
        return null;
    }

    public function onIssueStatusChanged(PDO $db, int $groupId, string $status, string $note): ?array
    {
        return null;
    }
}
