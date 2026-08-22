<?php
declare(strict_types=1);

namespace LogLens\Tests\Unit;

use InvalidArgumentException;
use LogLens\Plugins\Releases\SourceMapResolver;
use LogLens\Plugins\Releases\SourceMapService;
use LogLens\Tests\TestCase;
use PDO;

/**
 * Source maps: base64-VLQ decoding to de-minify browser stack frames per
 * release, keyed by file basename, with validation.
 */
final class SourceMapTest extends TestCase
{
    private const MAP = '{"version":3,"sources":["Checkout.tsx"],"names":["submitOrder"],"mappings":"AASIA"}';

    private function seed(): PDO
    {
        $pdo = $this->makeDatabase()->pdo;
        (new SourceMapService($pdo))->upload([
            'release' => 'v9.0.0',
            'file' => 'https://app.test/assets/app.4f2a.js',
            'map' => self::MAP,
        ]);
        return $pdo;
    }

    public function testResolvesAMinifiedFrameToOriginalSource(): void
    {
        $frame = (new SourceMapResolver($this->seed()))->resolveFrame('v9.0.0', 'app.4f2a.js', 1, 1);
        self::assertNotNull($frame);
        self::assertSame('Checkout.tsx', $frame['source']);
        self::assertSame(10, $frame['line']);
        self::assertSame(5, $frame['column']);
        self::assertSame('submitOrder', $frame['name']);
    }

    public function testRewritesMappedStackFramesAndLeavesUnknownsUntouched(): void
    {
        $resolver = new SourceMapResolver($this->seed());
        self::assertStringContainsString(
            'Checkout.tsx:10:5',
            $resolver->resolveStack('at submitOrder (https://app.test/assets/app.4f2a.js:1:1)', 'v9.0.0')
        );
        self::assertStringContainsString(
            'vendor.js:1:1',
            $resolver->resolveStack('at other (https://app.test/assets/vendor.js:1:1)', 'v9.0.0')
        );
    }

    public function testMapsAreKeyedByBasenameAndListedWithoutContent(): void
    {
        $service = new SourceMapService($this->seed());
        $all = $service->all();
        self::assertGreaterThanOrEqual(1, count($all));
        self::assertSame('app.4f2a.js', $all[0]['file'] ?? null);
    }

    public function testInvalidSourceMapIsRejected(): void
    {
        $service = new SourceMapService($this->makeDatabase()->pdo);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Source Map/');
        $service->upload(['release' => 'v9', 'file' => 'a.js', 'map' => 'not-a-source-map']);
    }
}
