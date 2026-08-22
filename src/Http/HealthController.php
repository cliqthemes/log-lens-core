<?php
declare(strict_types=1);

namespace LogLens\Http;

use LogLens\Services\ApplicationRegistry;
use LogLens\Storage\Driver;
use LogLens\Storage\Drivers;
use LogLens\Support\ConfigValidator;
use PDO;
use Throwable;

/**
 * `?api=health` — a lightweight, application-agnostic readiness probe for
 * external monitoring (Low nit). It reports whether the runtime prerequisites
 * are met (PHP, the configured database engine, a resolvable application
 * store) and surfaces configuration warnings, without touching any
 * application's own database.
 *
 * The database probe (C-1) is driver-agnostic: it asks the active
 * {@see Driver} to `ping()` itself — a disposable in-memory open for SQLite
 * (the default), a plain connectivity check for Postgres/MySQL — rather than
 * assuming SQLite.
 *
 * It is intentionally unauthenticated so a monitor can hit it, and returns only
 * non-sensitive facts (versions and booleans) — never secrets or data.
 */
final class HealthController
{
    public function __construct(private readonly string $projectRoot)
    {
    }

    public function handle(LogLensRequest $request): LogLensResponse
    {
        if ($request->method !== 'GET') {
            return LogLensResponse::json(['error' => 'GET required.'], 405);
        }

        $driver = Drivers::active();
        $checks = [
            'php' => version_compare(PHP_VERSION, '8.2.0', '>='),
            'pdo_driver' => in_array($driver->name(), PDO::getAvailableDrivers(), true),
            'database_version' => false,
            'database' => false,
            'applications' => false,
        ];

        $probe = ['ok' => false, 'version' => null, 'minimum' => null];
        try {
            $probe = $driver->ping();
            $checks['database'] = $probe['ok'];
            // A version floor only applies to SQLite today; engines without
            // one (Postgres/MySQL) pass this check as soon as they connect.
            $checks['database_version'] = $probe['minimum'] === null
                ? $probe['ok']
                : $probe['ok'] && version_compare((string) $probe['version'], $probe['minimum'], '>=');
        } catch (Throwable) {
            // Leave the failed checks false; details are intentionally not leaked.
        }

        try {
            $checks['applications'] = is_array((new ApplicationRegistry($this->projectRoot))->all());
        } catch (Throwable) {
            $checks['applications'] = false;
        }

        $configWarnings = ConfigValidator::validate();
        $ok = !in_array(false, $checks, true);

        return LogLensResponse::json([
            'status' => $ok ? 'ok' : 'degraded',
            'checks' => $checks,
            'config_warnings' => $configWarnings,
            'versions' => [
                'php' => PHP_VERSION,
                'database_driver' => $driver->name(),
                'database_version' => $probe['version'],
                'database_minimum' => $probe['minimum'],
            ],
        ], $ok ? 200 : 503);
    }
}
