<?php
declare(strict_types=1);

namespace LogLens\Tests\Unit;

use LogLens\Config;
use LogLens\Http\ApiController;
use LogLens\Http\LogLensRequest;
use LogLens\Http\LogLensResponse;
use LogLens\Tests\TestCase;

/**
 * ApiController::handle returns transport-agnostic responses and enforces the
 * guard/API key.
 */
final class DispatcherTest extends TestCase
{
    private function controller(): ApiController
    {
        $pdo = $this->makeDatabase()->pdo;
        return new ApiController($pdo, $this->workspace, $this->workspace, $this->workspace);
    }

    private function dispatch(ApiController $controller, string $method, array $query, array $headers = []): LogLensResponse
    {
        return $controller->handle(new LogLensRequest($method, $query, [], $headers));
    }

    public function testRoutesToJsonPayloads(): void
    {
        Config::load(['auth' => ['token' => '']]);
        $controller = $this->controller();
        $summary = $this->dispatch($controller, 'GET', ['api' => 'summary']);
        self::assertSame(200, $summary->status);
        self::assertArrayHasKey('total', $summary->data);
    }

    public function testUnknownEndpointIs404AndWrongMethodIs405(): void
    {
        Config::load(['auth' => ['token' => '']]);
        $controller = $this->controller();
        self::assertSame(404, $this->dispatch($controller, 'GET', ['api' => 'no-such-endpoint'])->status);
        self::assertSame(405, $this->dispatch($controller, 'GET', ['api' => 'issue-status'])->status);
    }

    public function testDispatcherEnforcesTheApiKey(): void
    {
        $controller = $this->controller();
        Config::load(['auth' => ['token' => 'k']]);
        self::assertSame(401, $this->dispatch($controller, 'GET', ['api' => 'summary'])->status);
        self::assertSame(200, $this->dispatch($controller, 'GET', ['api' => 'summary'], ['x-log-lens-token' => 'k'])->status);
    }

    /**
     * PDOException extends RuntimeException, so it used to land in the `400`
     * arm and echo the driver's message — failing SQL, table and column names,
     * the database file path — straight back to the caller.
     */
    public function testDatabaseFaultsAre500WithoutLeakingTheDriverMessage(): void
    {
        Config::load(['auth' => ['token' => '']]);
        $pdo = $this->makeDatabase()->pdo;
        $controller = new ApiController($pdo, $this->workspace, $this->workspace, $this->workspace);
        $pdo->exec('DROP TABLE error_groups');

        $originalErrorLog = (string) ini_get('error_log');
        ini_set('error_log', $this->path('dispatcher-errors.log'));
        try {
            $response = $controller->handle(new LogLensRequest('GET', ['api' => 'summary'], [], []));
        } finally {
            ini_set('error_log', $originalErrorLog);
        }

        self::assertSame(500, $response->status);
        $error = (string) ($response->data['error'] ?? '');
        self::assertStringNotContainsString('error_groups', $error);
        self::assertStringNotContainsString('SQLSTATE', $error);
        self::assertStringNotContainsString('SELECT', strtoupper($error));
    }
}
