<?php
declare(strict_types=1);

namespace LogLens\Services;

use RuntimeException;

final class ProcessRunner
{
    /** How much of a failing command's stderr may reach the HTTP response. */
    private const MESSAGE_LIMIT = 200;

    /** @param list<string> $command */
    public function run(array $command, int $timeoutSeconds = 30): string
    {
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, null, PHP_OS_FAMILY === 'Windows' ? ['bypass_shell' => true] : null);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the connector process.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + max(1, $timeoutSeconds);
        while (true) {
            $status = proc_get_status($process);
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';
            if (!$status['running']) {
                break;
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($process);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                throw new RuntimeException('Connector command timed out.');
            }
            usleep(20_000);
        }
        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = $status['exitcode'];
        proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException($this->failureMessage($command, $stderr, (int) $exitCode));
        }
        return $stdout;
    }

    /**
     * Turn a non-zero exit into an operator-readable message.
     *
     * The whole of stderr goes to the PHP error log, where it belongs; the
     * message the caller (and so the dashboard, over HTTP) receives is only the
     * first line, trimmed of control characters and length-capped. Returning
     * raw stderr verbatim meant an `ssh`/`rsync` failure could echo the full
     * argument vector, absolute key paths, and pages of remote output through a
     * `400` response body.
     *
     * @param list<string> $command
     */
    private function failureMessage(array $command, string $stderr, int $exitCode): string
    {
        $stderr = trim($stderr);
        $binary = basename($command[0] ?? 'command');
        if ($stderr === '') {
            return "The {$binary} command failed with exit code {$exitCode}.";
        }

        error_log("[log-lens] {$binary} exited {$exitCode}: {$stderr}");

        $firstLine = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', explode("\n", $stderr)[0]));
        if ($firstLine === '') {
            return "The {$binary} command failed with exit code {$exitCode}. Check the PHP error log.";
        }
        if (mb_strlen($firstLine) > self::MESSAGE_LIMIT) {
            $firstLine = mb_substr($firstLine, 0, self::MESSAGE_LIMIT) . '…';
        }
        return "The {$binary} command failed with exit code {$exitCode}: {$firstLine}";
    }
}
