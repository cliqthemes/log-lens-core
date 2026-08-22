<?php
declare(strict_types=1);

namespace LogLens\Tests\Unit;

use LogLens\Support\HttpClient;
use LogLens\Tests\TestCase;
use RuntimeException;

/**
 * Low nit: HttpClient's retry/backoff logic was untested network glue. The
 * injectable transport drives it deterministically with no real I/O (and no
 * real backoff wait).
 */
final class HttpClientTest extends TestCase
{
    /** @param list<callable():array{status:int,body:string}> $steps */
    private function client(int &$calls, array $steps, int $retries = 2): HttpClient
    {
        $i = 0;
        $transport = function () use (&$calls, &$i, $steps): array {
            $calls++;
            $step = $steps[min($i, count($steps) - 1)];
            $i++;
            return $step();
        };
        return new HttpClient(timeout: 5, connectTimeout: 2, retries: $retries, transport: $transport);
    }

    public function testRetriesTransientTransportErrorsThenSucceeds(): void
    {
        $calls = 0;
        $throw = static function (): array { throw new RuntimeException('connection reset'); };
        $ok = static fn (): array => ['status' => 200, 'body' => 'ok'];
        $client = $this->client($calls, [$throw, $throw, $ok]);

        $response = $client->post('https://example.test', '{}');
        self::assertSame(200, $response['status']);
        self::assertSame(3, $calls, 'Two failures then success = three attempts.');
    }

    public function testRetries5xxThenReturnsSuccess(): void
    {
        $calls = 0;
        $fail = static fn (): array => ['status' => 503, 'body' => 'down'];
        $ok = static fn (): array => ['status' => 200, 'body' => 'ok'];
        $client = $this->client($calls, [$fail, $fail, $ok]);

        $response = $client->post('https://example.test', '{}');
        self::assertSame(200, $response['status']);
        self::assertSame(3, $calls);
    }

    public function testDoesNotRetryClientErrors(): void
    {
        $calls = 0;
        $client = $this->client($calls, [static fn (): array => ['status' => 400, 'body' => 'bad']]);

        $response = $client->post('https://example.test', '{}');
        self::assertSame(400, $response['status'], '4xx is returned to the caller, not retried.');
        self::assertSame(1, $calls);
    }

    public function testGivesUpAfterExhaustingRetries(): void
    {
        $calls = 0;
        $client = $this->client(
            $calls,
            [static function (): array { throw new RuntimeException('always down'); }],
            retries: 2,
        );

        $this->expectException(RuntimeException::class);
        try {
            $client->post('https://example.test', '{}');
        } finally {
            self::assertSame(3, $calls, 'Initial attempt + 2 retries.');
        }
    }
}
