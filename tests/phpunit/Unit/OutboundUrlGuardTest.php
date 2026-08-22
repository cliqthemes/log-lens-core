<?php
declare(strict_types=1);

namespace LogLens\Tests\Unit;

use LogLens\Support\OutboundUrlGuard;
use LogLens\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * The SSRF guard for server-fetched URLs. Every case here uses a literal IP so
 * the test never touches DNS.
 */
#[CoversClass(OutboundUrlGuard::class)]
final class OutboundUrlGuardTest extends TestCase
{
    /** @return array<string,array{0:string}> */
    public static function internalUrls(): array
    {
        return [
            'loopback' => ['https://127.0.0.1/hook'],
            'ipv6 loopback' => ['https://[::1]/hook'],
            'rfc1918 ten' => ['https://10.0.0.5/hook'],
            'rfc1918 172' => ['https://172.16.4.9/hook'],
            'rfc1918 192' => ['https://192.168.1.20/hook'],
            'link-local / cloud metadata' => ['https://169.254.169.254/latest/meta-data/'],
        ];
    }

    #[DataProvider('internalUrls')]
    public function testInternalAddressesAreRejected(string $url): void
    {
        $this->expectException(RuntimeException::class);
        OutboundUrlGuard::publicIps($url);
    }

    public function testPublicAddressIsAccepted(): void
    {
        self::assertSame(['93.184.216.34'], OutboundUrlGuard::publicIps('https://93.184.216.34/hook'));
    }

    public function testUrlWithoutAHostIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        OutboundUrlGuard::publicIps('not-a-url');
    }

    /**
     * The pin is what actually defeats rebinding: cURL connects to these
     * addresses instead of resolving the host a second time.
     */
    public function testPinsCarryTheHostPortAndEveryValidatedAddress(): void
    {
        self::assertSame(
            ['hooks.example.com:443:93.184.216.34,93.184.216.35'],
            OutboundUrlGuard::pins('https://hooks.example.com/services/x', ['93.184.216.34', '93.184.216.35']),
        );
    }

    public function testPinsUseAnExplicitPortWhenTheUrlStatesOne(): void
    {
        self::assertSame(
            ['hooks.example.com:8443:93.184.216.34'],
            OutboundUrlGuard::pins('https://hooks.example.com:8443/x', ['93.184.216.34']),
        );
    }

    public function testPinsDefaultToPort80ForPlainHttp(): void
    {
        self::assertSame(
            ['example.com:80:93.184.216.34'],
            OutboundUrlGuard::pins('http://example.com/x', ['93.184.216.34']),
        );
    }

    public function testNoPinsWithoutValidatedAddresses(): void
    {
        self::assertSame([], OutboundUrlGuard::pins('https://example.com/x', []));
    }
}
