<?php
declare(strict_types=1);

namespace LogLens\Tests\Unit;

use InvalidArgumentException;
use LogLens\Connectors\SshConnector;
use LogLens\Services\ConnectorService;
use LogLens\Tests\TestCase;

/**
 * SSH connector path handling: safe glob discovery/sort/dedup over a fake `ssh`
 * binary, and rejection of unsafe recursive patterns.
 */
final class SshConnectorTest extends TestCase
{
    /** @return array{0:array<string,mixed>,1:string} config + the pattern root */
    private function fakeSshEnvironment(): array
    {
        $fakeBin = $this->path('fake-bin');
        $root = $this->path('ssh-patterns');
        mkdir($fakeBin, 0775, true);
        mkdir($root . '/daily', 0775, true);
        mkdir($root . '/rotated', 0775, true);
        file_put_contents($root . '/daily/laravel-2026-01-03.log', "daily\n");
        file_put_contents($root . '/daily/laravel-2026-01-04.log', "daily\n");
        file_put_contents($root . '/rotated/laravel.log.1', "rotated\n");
        file_put_contents($root . '/rotated/laravel.log.gz', "compressed\n");
        file_put_contents($fakeBin . '/ssh', "#!/bin/sh\nfor argument do\n  remote_command=\$argument\ndone\nexec /bin/sh -c \"\$remote_command\"\n");
        chmod($fakeBin . '/ssh', 0755);
        return [[
            'host' => 'example.test',
            'port' => 22,
            'user' => 'deploy',
            'paths' => [
                $root . '/daily/laravel-*.log',
                $root . '/rotated/laravel.log.[0-9]*',
                $root . '/daily/laravel-2026-01-03.log',
            ],
        ], $root, $fakeBin];
    }

    public function testDiscoversSortsAndDeduplicatesPatterns(): void
    {
        [$config, $root, $fakeBin] = $this->fakeSshEnvironment();
        $originalPath = getenv('PATH') ?: '';
        putenv('PATH=' . $fakeBin . ':' . $originalPath);
        try {
            $files = (new SshConnector($config))->discover();
        } finally {
            putenv('PATH=' . $originalPath);
        }

        self::assertSame([
            $root . '/daily/laravel-2026-01-03.log',
            $root . '/daily/laravel-2026-01-04.log',
            $root . '/rotated/laravel.log.1',
        ], array_map(static fn ($file): string => $file->path, $files));
    }

    public function testUnsafeRecursivePatternIsRejected(): void
    {
        [$config] = $this->fakeSshEnvironment();
        $connectors = new ConnectorService($this->makeDatabase()->pdo);
        $this->expectException(InvalidArgumentException::class);
        $connectors->create([
            'name' => 'Unsafe pattern',
            'type' => 'ssh',
            'config' => [...$config, 'paths' => ['/var/log/**/laravel.log']],
        ]);
    }
    /**
     * The connector form used to answer whether an arbitrary path existed on
     * the server's filesystem. It no longer looks: a well-formed path is
     * accepted and ssh reports a missing key itself.
     */
    public function testAMissingKeyPathIsNotAnExistenceOracle(): void
    {
        [$config, , $fakeBin] = $this->fakeSshEnvironment();
        $config['key_path'] = '/etc/definitely-not-here/id_ed25519';
        $originalPath = getenv('PATH') ?: '';
        putenv('PATH=' . $fakeBin . ':' . $originalPath);
        try {
            // Discovery runs the fake ssh; the point is that nothing rejected
            // the non-existent key path before we ever got here.
            self::assertNotSame([], (new SshConnector($config))->discover());
        } finally {
            putenv('PATH=' . $originalPath);
        }
    }

    public function testRelativeKeyPathIsRejected(): void
    {
        [$config] = $this->fakeSshEnvironment();
        $config['key_path'] = '../../.ssh/id_ed25519';
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('absolute path');
        (new SshConnector($config))->discover();
    }

    /**
     * `-o UserKnownHostsFile=…` is one config directive; a newline in the value
     * would hand ssh a second one of the caller's choosing.
     */
    public function testKnownHostsPathWithANewlineIsRejected(): void
    {
        [$config] = $this->fakeSshEnvironment();
        $config['known_hosts_file'] = "/tmp/known_hosts\nProxyCommand=/bin/sh";
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('control characters');
        (new SshConnector($config))->discover();
    }
}
