<?php
declare(strict_types=1);

namespace LogLens\Tests\Unit;

use LogLens\Services\ProcessRunner;
use LogLens\Tests\TestCase;
use RuntimeException;

/**
 * A failing connector command's stderr used to become the HTTP response body
 * verbatim — for `ssh`/`rsync` that can be the full argument vector, absolute
 * key paths, and pages of remote output. It is now summarized for the caller
 * and logged in full.
 */
final class ProcessRunnerTest extends TestCase
{
    private string $originalErrorLog = '';
    private string $logFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        // Capture error_log() so the assertions can inspect it — and so the
        // suite's output stays clean.
        $this->originalErrorLog = (string) ini_get('error_log');
        $this->logFile = $this->path('process-runner.log');
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->originalErrorLog);
        parent::tearDown();
    }

    private function logged(): string
    {
        return is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
    }

    public function testStdoutIsReturnedOnSuccess(): void
    {
        $output = (new ProcessRunner())->run(['/bin/echo', 'hello']);
        self::assertSame('hello', trim($output));
    }

    public function testFailureMessageNamesTheBinaryAndExitCodeAndKeepsOnlyTheFirstStderrLine(): void
    {
        try {
            (new ProcessRunner())->run([
                '/bin/sh',
                '-c',
                'echo "Permission denied (publickey)." >&2; echo "/home/deploy/.ssh/id_ed25519" >&2; exit 255',
            ]);
            self::fail('A non-zero exit must throw.');
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
            self::assertStringContainsString('sh', $message);
            self::assertStringContainsString('255', $message);
            self::assertStringContainsString('Permission denied (publickey).', $message);
            self::assertStringNotContainsString(
                'id_ed25519',
                $message,
                'Only the first stderr line may reach the caller; the rest goes to the error log.',
            );
            self::assertStringContainsString('id_ed25519', $this->logged(), 'Full stderr must still be logged.');
        }
    }

    public function testLongStderrIsTruncated(): void
    {
        $this->expectException(RuntimeException::class);
        try {
            (new ProcessRunner())->run([
                '/bin/sh',
                '-c',
                'printf "%0.sA" $(seq 1 5000) >&2; exit 1',
            ]);
        } catch (RuntimeException $exception) {
            // 200-character cap plus the ellipsis, on top of the fixed prefix.
            self::assertLessThan(320, mb_strlen($exception->getMessage()));
            self::assertStringEndsWith('…', $exception->getMessage());
            throw $exception;
        }
    }

    public function testSilentFailureStillReportsTheExitCode(): void
    {
        $this->expectExceptionMessage('exit code 7');
        (new ProcessRunner())->run(['/bin/sh', '-c', 'exit 7']);
    }
}
