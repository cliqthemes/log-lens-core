<?php
declare(strict_types=1);

namespace LogLens\Connectors;

use InvalidArgumentException;
use LogLens\Contracts\LogSourceConnectorInterface;

/**
 * Builds the right connector for a stored connector row.
 *
 * The two built-in types (`local`, `ssh`) stay a plain `match` — no reason
 * to indirect through the registry for connectors that will always exist —
 * but a host or package can add a new type via {@see register()} without
 * touching this class, mirroring how {@see \LogLens\Plugins\PluginRegistry}
 * lets a third-party plugin register itself.
 */
final class ConnectorFactory
{
    /** @var array<string, callable(array<string,mixed>): LogSourceConnectorInterface> */
    private static array $registered = [];

    /** Register (or override, by type) a connector type at runtime. */
    public static function register(string $type, callable $factory): void
    {
        self::$registered[$type] = $factory;
    }

    /** Drop all programmatic registrations (tests). */
    public static function reset(): void
    {
        self::$registered = [];
    }

    /**
     * @param array<string,mixed> $connector
     * @param ?string $stateDirectory Writable per-application directory the connector may keep state in (e.g. SSH known hosts).
     */
    public function make(array $connector, ?string $stateDirectory = null): LogSourceConnectorInterface
    {
        $config = json_decode((string) ($connector['config_json'] ?? '{}'), true);
        if (!is_array($config)) {
            throw new InvalidArgumentException('Connector configuration is invalid JSON.');
        }
        $type = (string) ($connector['type'] ?? '');
        $factory = self::$registered[$type] ?? match ($type) {
            'local' => static fn (array $config): LogSourceConnectorInterface => new LocalDirectoryConnector($config),
            'ssh' => static fn (array $config): LogSourceConnectorInterface => new SshConnector(
                $config,
                stateDirectory: $stateDirectory,
            ),
            default => null,
        };
        if ($factory === null) {
            throw new InvalidArgumentException('Unsupported connector type.');
        }
        return $factory($config, $stateDirectory);
    }
}
