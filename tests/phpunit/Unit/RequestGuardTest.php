<?php
declare(strict_types=1);

namespace LogLens\Tests\Unit;

use LogLens\Config;
use LogLens\Http\LogLensRequest;
use LogLens\Http\LogLensResponse;
use LogLens\Http\RequestGuard;
use LogLens\Tests\TestCase;

/**
 * The same-origin CSRF guard and the optional API-key check. `check()` returns
 * null to allow a request or a 403/401 response to block it.
 */
final class RequestGuardTest extends TestCase
{
    private function check(string $method, array $headers): ?LogLensResponse
    {
        return RequestGuard::check(new LogLensRequest($method, [], [], $headers));
    }

    public function testSafeMethodsAreNeverOriginBlocked(): void
    {
        Config::load(['auth' => ['token' => '']]);
        self::assertNull($this->check('GET', ['origin' => 'https://evil.example', 'host' => 'log-lens.test']));
    }

    public function testNonBrowserPostWithoutOriginIsAllowed(): void
    {
        Config::load(['auth' => ['token' => '']]);
        self::assertNull($this->check('POST', ['host' => 'log-lens.test']));
    }

    public function testSameOriginPostIsAllowed(): void
    {
        Config::load(['auth' => ['token' => '']]);
        self::assertNull($this->check('POST', ['origin' => 'http://log-lens.test', 'host' => 'log-lens.test']));
        self::assertNull($this->check('DELETE', ['origin' => 'https://log-lens.test:8443', 'host' => 'log-lens.test:8443']));
    }

    /**
     * The scheme is not compared: a proxy that terminates TLS without setting
     * X-Forwarded-Proto leaves the server believing it serves `http` while the
     * browser correctly reports an `https` Origin. Nothing is lost — a page
     * cannot be served over `http` by a host:port that answers `https`.
     */
    public function testSchemeMismatchOnTheSameHostAndPortIsAllowed(): void
    {
        Config::load(['auth' => ['token' => '']]);
        self::assertNull($this->check('POST', ['origin' => 'https://log-lens.test', 'host' => 'log-lens.test']));
    }

    /**
     * A different port is a different origin. This is the realistic development
     * machine case the host-only comparison used to wave through: every local
     * site is `localhost`, so a page on `localhost:3000` could drive a Log Lens
     * on `localhost:8080`.
     */
    public function testCrossPortPostOnTheSameHostIsBlocked(): void
    {
        Config::load(['auth' => ['token' => '']]);
        self::assertSame(403, $this->check('POST', ['origin' => 'http://localhost:3000', 'host' => 'localhost:8080'])?->status);
        self::assertSame(403, $this->check('DELETE', ['origin' => 'https://log-lens.test:8443', 'host' => 'log-lens.test:8787'])?->status);
    }

    /** A pinned LOG_LENS_URL is an accepted origin even when Host says otherwise. */
    public function testPinnedBaseUrlIsAnAcceptedOrigin(): void
    {
        Config::load(['auth' => ['token' => ''], 'LOG_LENS_URL' => 'https://logs.example.com']);
        self::assertNull($this->check('POST', ['origin' => 'https://logs.example.com', 'host' => 'internal-8080.local']));
        self::assertSame(403, $this->check('POST', ['origin' => 'https://evil.example', 'host' => 'internal-8080.local'])?->status);
    }

    public function testForgedCrossOriginPostIsBlockedWith403(): void
    {
        Config::load(['auth' => ['token' => '']]);
        $blocked = $this->check('POST', ['origin' => 'https://evil.example', 'host' => 'log-lens.test']);
        self::assertInstanceOf(LogLensResponse::class, $blocked);
        self::assertSame(403, $blocked->status);
    }

    public function testApiKeyEnforcement(): void
    {
        $auth = fn (array $headers): ?LogLensResponse =>
            RequestGuard::check(new LogLensRequest('POST', [], [], $headers + ['host' => 'log-lens.test']));

        Config::load(['auth' => ['token' => '']]);
        self::assertNull($auth([]), 'No configured token leaves the API unauthenticated.');

        Config::load(['auth' => ['token' => 's3cret']]);
        self::assertSame(401, $auth([])?->status, 'A missing key is rejected.');
        self::assertSame(401, $auth(['x-log-lens-token' => 'wrong'])?->status, 'A wrong key is rejected.');
        self::assertNull($auth(['x-log-lens-token' => 's3cret']), 'The correct key via header is accepted.');
        self::assertNull($auth(['authorization' => 'Bearer s3cret']), 'The correct key via Bearer is accepted.');
    }
}
