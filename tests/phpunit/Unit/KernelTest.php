<?php
declare(strict_types=1);

namespace LogLens\Tests\Unit;

use LogLens\Config;
use LogLens\Http\LogLensRequest;
use LogLens\Identity\Actor;
use LogLens\Kernel;
use LogLens\Tests\TestCase;

/**
 * The Kernel end-to-end wiring: resolve application → open database → dispatch,
 * with no web server.
 */
final class KernelTest extends TestCase
{
    private function kernel(): Kernel
    {
        $root = $this->path('kernel-workspace');
        mkdir($root, 0775, true);
        return new Kernel($root);
    }

    public function testListsApplicationsAndResolvesTheDefaultWorkspace(): void
    {
        Config::load(['auth' => ['token' => '']]);
        $kernel = $this->kernel();

        $apps = $kernel->handle(new LogLensRequest('GET', ['api' => 'applications'], [], []));
        self::assertSame(200, $apps->status);
        self::assertArrayHasKey('data', $apps->data);

        $summary = $kernel->handle(new LogLensRequest('GET', ['api' => 'summary'], [], []));
        self::assertSame(200, $summary->status);
        self::assertArrayHasKey('total', $summary->data);
    }

    public function testUnknownApplicationIdReturns422(): void
    {
        Config::load(['auth' => ['token' => '']]);
        $response = $this->kernel()->handle(
            new LogLensRequest('GET', ['api' => 'summary', 'app' => 'does-not-exist'], [], [])
        );
        self::assertSame(422, $response->status);
    }

    /**
     * The ?api=applications route is dispatched before an application is
     * resolved, so it authorizes on its own (it used to authorize not at all —
     * any caller past the guard could provision a new tenant).
     */
    public function testProvisioningAnApplicationRequiresAnOwner(): void
    {
        Config::load(['auth' => ['token' => '']]);
        $kernel = $this->kernel();
        $editor = new Actor('u2', 'Ed', [Actor::ROLE_EDITOR]);

        $denied = $kernel->handle(new LogLensRequest(
            'POST',
            ['api' => 'applications'],
            ['name' => 'Sneaky', 'id' => 'sneaky'],
            [],
            null,
            null,
            null,
            $editor,
        ));
        self::assertSame(403, $denied->status);

        $allowed = $kernel->handle(new LogLensRequest(
            'POST',
            ['api' => 'applications'],
            ['name' => 'Second App', 'id' => 'second-app'],
            [],
            null,
            null,
            null,
            Actor::localOwner(),
        ));
        self::assertSame(201, $allowed->status);
    }

    public function testListingApplicationsIsAllowedForAViewerButNotAnUnknownRole(): void
    {
        Config::load(['auth' => ['token' => '']]);
        $kernel = $this->kernel();

        $viewer = $kernel->handle(new LogLensRequest(
            'GET',
            ['api' => 'applications'],
            [],
            [],
            null,
            null,
            null,
            new Actor('u3', 'Val', [Actor::ROLE_VIEWER]),
        ));
        self::assertSame(200, $viewer->status);

        $stranger = $kernel->handle(new LogLensRequest(
            'GET',
            ['api' => 'applications'],
            [],
            [],
            null,
            null,
            null,
            new Actor('u4', 'Nobody', ['guest']),
        ));
        self::assertSame(403, $stranger->status);
    }
}
