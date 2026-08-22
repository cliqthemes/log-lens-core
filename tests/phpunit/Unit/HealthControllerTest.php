<?php
declare(strict_types=1);

namespace LogLens\Tests\Unit;

use LogLens\Http\HealthController;
use LogLens\Http\LogLensRequest;
use LogLens\Tests\TestCase;

final class HealthControllerTest extends TestCase
{
    private function get(): LogLensRequest
    {
        return new LogLensRequest('GET', ['api' => 'health'], []);
    }

    public function testReportsOkWhenPrerequisitesAreMet(): void
    {
        $response = (new HealthController($this->workspace))->handle($this->get());

        self::assertSame(200, $response->status);
        self::assertSame('ok', $response->data['status']);
        self::assertTrue($response->data['checks']['database']);
        self::assertTrue($response->data['checks']['database_version']);
        self::assertTrue($response->data['checks']['applications']);
        self::assertSame('sqlite', $response->data['versions']['database_driver']);
        self::assertNotNull($response->data['versions']['database_version']);
        self::assertSame([], $response->data['config_warnings']);
    }

    public function testSurfacesConfigWarnings(): void
    {
        \LogLens\Config::load(['bogus_section' => true]);
        $response = (new HealthController($this->workspace))->handle($this->get());
        self::assertNotEmpty($response->data['config_warnings']);
    }

    public function testRejectsNonGet(): void
    {
        $response = (new HealthController($this->workspace))->handle(new LogLensRequest('POST', ['api' => 'health'], []));
        self::assertSame(405, $response->status);
    }
}
