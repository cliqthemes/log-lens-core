<?php
declare(strict_types=1);

namespace LogLens\Tests\Unit;

use Generator;
use InvalidArgumentException;
use LogLens\Config;
use LogLens\Connectors\ConnectorFactory;
use LogLens\Connectors\LocalDirectoryConnector;
use LogLens\Contracts\LogParserInterface;
use LogLens\Contracts\LogSourceConnectorInterface;
use LogLens\Domain\RemoteLogFile;
use LogLens\Parsing\ParserRegistry;
use LogLens\Tests\TestCase;

/**
 * Connectors and parsers are extensible the same way plugins are: a new
 * type/format is a new file registered in one place, not an
 * edit to ConnectorFactory's or ParserRegistry's own source.
 */
final class ExtensibleRegistriesTest extends TestCase
{
    public function testBuiltInConnectorTypesStillResolveUnchanged(): void
    {
        $factory = new ConnectorFactory();
        self::assertInstanceOf(
            LocalDirectoryConnector::class,
            $factory->make(['type' => 'local', 'config_json' => json_encode(['path' => $this->path('logs')])]),
        );
    }

    public function testUnknownConnectorTypeStillRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ConnectorFactory())->make(['type' => 'no-such-type', 'config_json' => '{}']);
    }

    public function testProgrammaticallyRegisteredConnectorTypeResolves(): void
    {
        ConnectorFactory::register('recording', static fn (array $config): LogSourceConnectorInterface => new RecordingConnector($config));
        $connector = (new ConnectorFactory())->make(['type' => 'recording', 'config_json' => json_encode(['label' => 'test'])]);
        self::assertInstanceOf(RecordingConnector::class, $connector);
        self::assertSame(['label' => 'test'], $connector->config);
    }

    public function testConfigDeclaredParserIsTriedBeforeTheCatchAllDefault(): void
    {
        Config::load(['parsing' => ['register' => [RecordingParser::class]]]);
        $path = $this->writeFile('weird.log', "anything\n");
        $parser = (new ParserRegistry())->forFile($path);
        self::assertInstanceOf(RecordingParser::class, $parser, 'A registered parser must get first refusal, ahead of the built-in catch-all.');
    }

    public function testConstructorInjectedParserListStillTakesPrecedenceOverConfig(): void
    {
        // Explicit injection (existing behavior) bypasses config discovery entirely.
        Config::load(['parsing' => ['register' => [RecordingParser::class]]]);
        $path = $this->writeFile('weird.log', "anything\n");
        $parser = (new ParserRegistry([new RecordingParser()]))->forFile($path);
        self::assertInstanceOf(RecordingParser::class, $parser);
    }
}

final class RecordingConnector implements LogSourceConnectorInterface
{
    public function __construct(public readonly array $config)
    {
    }

    public function discover(): array
    {
        return [];
    }

    public function readRange(RemoteLogFile $file, int $offset, int $length): string
    {
        return '';
    }

    public function prefixHash(RemoteLogFile $file, int $length): string
    {
        return '';
    }

    public function prefixHashes(array $requests): array
    {
        return [];
    }

    public function test(): array
    {
        return ['ok' => true, 'message' => 'ok'];
    }
}

final class RecordingParser implements LogParserInterface
{
    public function type(): string
    {
        return 'recording';
    }

    public function supports(string $path, string $sample): bool
    {
        return true;
    }

    public function parse(string $path, int $offset = 0): Generator
    {
        yield from [];
    }
}
