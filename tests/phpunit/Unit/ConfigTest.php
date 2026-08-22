<?php
declare(strict_types=1);

namespace LogLens\Tests\Unit;

use LogLens\Config;
use LogLens\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(Config::class)]
final class ConfigTest extends TestCase
{
    private string $previousErrorLog = '';

    protected function setUp(): void
    {
        parent::setUp();
        // Config::pattern() reports a rejected pattern through error_log, which
        // would otherwise land in the test runner's output.
        $this->previousErrorLog = (string) ini_get('error_log');
        ini_set('error_log', $this->path('php-errors.log'));
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);
        parent::tearDown();
    }

    public function testDotPathIntGetterReturnsConfiguredValue(): void
    {
        Config::load(['pagination' => ['max_limit' => 25]]);
        self::assertSame(25, Config::int('pagination.max_limit', 200));
    }

    public function testIntGetterFallsBackOnNonNumericValue(): void
    {
        Config::load(['ingestion' => ['capture_limit' => 'nope']]);
        self::assertSame(4194304, Config::int('ingestion.capture_limit', 4194304));
    }

    public function testIntGetterFallsBackOnMissingKey(): void
    {
        Config::load([]);
        self::assertSame(8388608, Config::int('sync.chunk_size', 8388608));
    }

    public function testStringGetterFallsBackOnMissingKey(): void
    {
        Config::load([]);
        self::assertSame('fallback', Config::string('missing.key', 'fallback'));
    }

    public function testIntGetterEnforcesMinimum(): void
    {
        Config::load(['pagination' => ['max_limit' => 0]]);
        // Below the min of 1, the getter must fall back to the default.
        self::assertSame(50, Config::int('pagination.max_limit', 50));
    }

    public function testResetForcesReloadFromDisk(): void
    {
        Config::load(['pagination' => ['max_limit' => 25]]);
        Config::reset();
        // After reset the shipped config.php defaults are reloaded, not the override.
        self::assertSame(200, Config::int('pagination.max_limit', 999));
    }

    public function testPatternAcceptsAWidenedFileNameRegex(): void
    {
        Config::load(['ingestion' => ['log_file_pattern' => '/\\.(log|out|err)$/i']]);
        self::assertSame(
            '/\\.(log|out|err)$/i',
            Config::pattern('ingestion.log_file_pattern', '/\\.log$/i'),
        );
    }

    #[DataProvider('unusablePatterns')]
    public function testPatternFallsBackToTheDefault(string $configured, string $why): void
    {
        Config::load(['ingestion' => ['log_file_pattern' => $configured]]);
        self::assertSame('/\\.log$/i', Config::pattern('ingestion.log_file_pattern', '/\\.log$/i'), $why);
    }

    /** @return array<string,array{string,string}> */
    public static function unusablePatterns(): array
    {
        return [
            'no delimiters' => ['\\.log$', 'A bare regex does not compile as a PCRE.'],
            'unbalanced group' => ['/(\\.log$/i', 'A syntax error must not reach preg_match at the call site.'],
            'empty' => ['', 'An empty value is the same as not configuring one.'],
            'over the length cap' => ['/' . str_repeat('a', 600) . '/', 'Longer than any plausible file-name pattern.'],
            // The classic exponential shape: nested quantifiers over an
            // overlapping class. It compiles fine and then hangs the import.
            'catastrophic backtracking' => ['/^(a+)+$/', 'Blows the PCRE backtrack limit on the probe subject.'],
        ];
    }

    public function testPatternIsRecheckedAfterAReset(): void
    {
        Config::load(['ingestion' => ['log_file_pattern' => '/^(a+)+$/']]);
        self::assertSame('/x/', Config::pattern('ingestion.log_file_pattern', '/x/'));

        // The validated-pattern memo is per-process, so it must not survive a
        // reset — otherwise one test's rejected pattern would pin the next.
        Config::reset();
        Config::load(['ingestion' => ['log_file_pattern' => '/\\.out$/']]);
        self::assertSame('/\\.out$/', Config::pattern('ingestion.log_file_pattern', '/x/'));
    }
}
