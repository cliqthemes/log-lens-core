<?php
declare(strict_types=1);

namespace LogLens\Tests\Unit;

use LogLens\Config;
use LogLens\Http\SecurityHeaders;
use LogLens\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SecurityHeaders::class)]
final class SecurityHeadersTest extends TestCase
{
    public function testDefaultPolicyLocksDownScriptsToSelf(): void
    {
        $csp = SecurityHeaders::contentSecurityPolicy();
        self::assertStringContainsString("script-src 'self'", $csp);
        self::assertStringContainsString("default-src 'self'", $csp);
        self::assertStringContainsString("object-src 'none'", $csp);
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
    }

    public function testScriptSourceNeverAllowsInlineOrEval(): void
    {
        $csp = SecurityHeaders::contentSecurityPolicy();
        // Extract just the script-src directive and assert it has no escape hatch.
        $scriptSrc = '';
        foreach (explode(';', $csp) as $directive) {
            if (str_starts_with(trim($directive), 'script-src')) {
                $scriptSrc = trim($directive);
            }
        }
        self::assertNotSame('', $scriptSrc);
        self::assertStringNotContainsString("'unsafe-inline'", $scriptSrc);
        self::assertStringNotContainsString("'unsafe-eval'", $scriptSrc);
    }

    public function testGoogleFontsAreAllowedForTheAppearancePicker(): void
    {
        $csp = SecurityHeaders::contentSecurityPolicy();
        self::assertStringContainsString('https://fonts.googleapis.com', $csp);
        self::assertStringContainsString('https://fonts.gstatic.com', $csp);
    }

    public function testPolicyCanBeOverriddenFromConfig(): void
    {
        Config::load(['security' => ['content_security_policy' => "default-src 'none'"]]);
        self::assertSame("default-src 'none'", SecurityHeaders::contentSecurityPolicy());
    }

    public function testHeaderSetIncludesTheStandardHardeningHeaders(): void
    {
        $headers = SecurityHeaders::headers(false);
        self::assertSame('nosniff', $headers['X-Content-Type-Options']);
        self::assertSame('DENY', $headers['X-Frame-Options']);
        self::assertArrayHasKey('Referrer-Policy', $headers);
        self::assertArrayHasKey('Content-Security-Policy', $headers);
        self::assertSame('same-origin', $headers['Cross-Origin-Opener-Policy']);
        self::assertStringContainsString('camera=()', $headers['Permissions-Policy']);
        self::assertStringContainsString('microphone=()', $headers['Permissions-Policy']);
    }

    /**
     * HSTS is a promise about the whole host, so it stays off unless an
     * operator opts in — and is never offered over plaintext.
     */
    public function testHstsIsOffByDefault(): void
    {
        Config::load([]);
        self::assertNull(SecurityHeaders::strictTransportSecurity(true));
        self::assertArrayNotHasKey('Strict-Transport-Security', SecurityHeaders::headers(true));
    }

    public function testHstsIsNotSentOverPlaintextEvenWhenConfigured(): void
    {
        Config::load(['security' => ['hsts_max_age' => 31536000]]);
        self::assertNull(SecurityHeaders::strictTransportSecurity(false));
        self::assertArrayNotHasKey('Strict-Transport-Security', SecurityHeaders::headers(false));
    }

    public function testConfiguredHstsIsSentOverTls(): void
    {
        Config::load(['security' => ['hsts_max_age' => 31536000]]);
        self::assertSame('max-age=31536000', SecurityHeaders::headers(true)['Strict-Transport-Security']);
    }

    public function testSubdomainAndPreloadDirectivesAreOptedInSeparately(): void
    {
        Config::load(['security' => [
            'hsts_max_age' => 63072000,
            'hsts_include_subdomains' => true,
            'hsts_preload' => true,
        ]]);
        self::assertSame(
            'max-age=63072000; includeSubDomains; preload',
            SecurityHeaders::strictTransportSecurity(true),
        );
    }
}
