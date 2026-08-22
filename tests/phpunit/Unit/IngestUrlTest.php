<?php
declare(strict_types=1);

namespace LogLens\Tests\Unit;

use LogLens\Config;
use LogLens\Plugins\Ingest\IngestService;
use LogLens\Tests\TestCase;

/**
 * The push URL shown in Settings → Plugins → HTTP ingest. It is derived from the
 * request (never a hardcoded host), unless the transport mounts the receiver
 * somewhere else — the Laravel adapter does, because there the receiver needs
 * its own route outside the host's access gate and CSRF check.
 */
final class IngestUrlTest extends TestCase
{
    private function service(): IngestService
    {
        return new IngestService($this->makeDatabase()->pdo);
    }

    public function testDerivesTheUrlFromTheRequestBase(): void
    {
        Config::load([]);
        self::assertSame(
            'https://logs.example.com/?api=ingest&app=my-app',
            $this->service()->settings('my-app', 'https://logs.example.com/')['ingest_url'],
        );
    }

    public function testConfiguredReceiverUrlWins(): void
    {
        Config::load(['ingest' => ['url' => 'https://app.test/log-lens/ingest']]);
        self::assertSame(
            'https://app.test/log-lens/ingest?app=my-app',
            $this->service()->settings('my-app', 'https://app.test/log-lens')['ingest_url'],
        );
    }

    public function testApplicationIdIsUrlEncoded(): void
    {
        Config::load([]);
        self::assertStringEndsWith(
            'app=my%2Fapp',
            $this->service()->settings('my/app', 'https://logs.example.com/')['ingest_url'],
        );
    }
}
