<?php
declare(strict_types=1);

namespace LogLens\Services;

use LogLens\Config;
use RuntimeException;

final class BackgroundSyncService
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly string $applicationId,
        private readonly string $sourcesDirectory,
        private readonly ConnectorSyncService $sync,
    ) {
    }

    /** @return array{accepted:bool,worker_started:bool,run_ids:list<int>,warning?:string} */
    public function start(?int $connectorId, ?string $snapshot, ?array $snapshots): array
    {
        $jobPath = null;
        $runIds = $connectorId !== null
            ? [$this->sync->enqueue($connectorId, $snapshot)]
            : $this->sync->enqueueAll($snapshots);

        if ($runIds === []) {
            return ['accepted' => true, 'worker_started' => false, 'run_ids' => []];
        }

        try {
            $jobPath = $this->writeJob($runIds);
            $this->launch($jobPath);
        } catch (\Throwable $exception) {
            if ($jobPath !== null) {
                @unlink($jobPath);
            }
            return [
                'accepted' => true,
                'worker_started' => false,
                'run_ids' => $runIds,
                'warning' => 'Automatic worker launch is unavailable. The run remains queued for the scheduled CLI worker. '
                    . $exception->getMessage(),
            ];
        }

        return ['accepted' => true, 'worker_started' => true, 'run_ids' => $runIds];
    }

    /** @param list<int> $runIds */
    private function writeJob(array $runIds): string
    {
        $directory = rtrim($this->sourcesDirectory, '/') . '/.jobs';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the connector job directory.');
        }
        $path = tempnam($directory, 'sync-');
        if ($path === false) {
            throw new RuntimeException('Could not create a connector synchronization job.');
        }
        $payload = json_encode([
            'project_root' => $this->projectRoot,
            'application_id' => $this->applicationId,
            'run_ids' => $runIds,
            'config' => Config::subset(['sync', 'ssh', 'database', 'ingestion']),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $payload, LOCK_EX) === false) {
            @unlink($path);
            throw new RuntimeException('Could not write the connector synchronization job.');
        }
        @chmod($path, 0600);
        return $path;
    }

    private function launch(string $jobPath): void
    {
        $worker = dirname(__DIR__, 2) . '/bin/background-sync.php';
        if (!is_file($worker)) {
            throw new RuntimeException('The background synchronization worker is unavailable.');
        }
        $logPath = rtrim($this->sourcesDirectory, '/') . '/background-sync.log';
        // Cap the detached worker's append-only log so an install that runs for
        // months never accumulates an unbounded file (one previous copy is kept).
        self::rotateLogFile($logPath, Config::int('sync.worker_log_max_bytes', 5_242_880, 0));
        $arguments = [$this->phpBinary(), $worker, '--job=' . $jobPath];
        if (PHP_OS_FAMILY === 'Windows') {
            $this->launchWindows($arguments, $logPath);
            return;
        }
        $this->launchUnix($arguments, $logPath);
    }

    /** @param list<string> $arguments */
    private function launchUnix(array $arguments, string $logPath): void
    {
        $shell = Config::string('sync.shell_binary');
        $candidates = $shell !== '' ? [$shell] : ['/bin/sh', '/usr/bin/sh'];
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            if ($directory !== '') {
                $candidates[] = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sh';
            }
        }
        $shell = $this->firstExecutable($candidates);
        if ($shell === null) {
            throw new RuntimeException('Could not locate a POSIX shell; configure sync.shell_binary.');
        }
        $command = [
            $shell,
            '-c',
            'log="$1"; shift; "$@" </dev/null >>"$log" 2>&1 &',
            'log-lens-background-sync',
            $logPath,
            ...$arguments,
        ];
        $this->runLauncher($command, ['bypass_shell' => true]);
    }

    /** @param list<string> $arguments */
    private function launchWindows(array $arguments, string $logPath): void
    {
        $commandInterpreter = (string) (getenv('ComSpec') ?: 'cmd.exe');
        $workerCommand = 'start "" /B '
            . implode(' ', array_map('escapeshellarg', $arguments))
            . ' >> ' . escapeshellarg($logPath) . ' 2>&1';
        $this->runLauncher(
            [$commandInterpreter, '/D', '/S', '/C', $workerCommand],
            [
                'bypass_shell' => true,
                'suppress_errors' => true,
                'create_process_group' => true,
            ],
        );
    }

    /** @param list<string> $command @param array<string,bool> $options */
    private function runLauncher(array $command, array $options): void
    {
        $process = proc_open($command, [
            0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
            1 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'a'],
            2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'a'],
        ], $pipes, null, null, $options);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not launch the background synchronization worker.');
        }
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException("Background synchronization launcher exited with code {$exitCode}.");
        }
    }

    private function phpBinary(): string
    {
        $configured = Config::string('sync.php_binary');
        if ($configured !== '') {
            if (!$this->isCliBinary($configured)) {
                throw new RuntimeException('The configured sync.php_binary is not an executable PHP CLI.');
            }
            return $configured;
        }

        $executable = PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php';
        $candidates = [PHP_BINDIR . DIRECTORY_SEPARATOR . $executable];
        $binaryName = basename(PHP_BINARY);
        if (preg_match('/(?:-fpm|fpm|php-cgi)(?:\.exe)?$/i', $binaryName) === 1) {
            $replacement = str_ends_with(strtolower($binaryName), '.exe') ? 'php.exe' : 'php';
            if (preg_match('/^php(?:-fpm|-cgi)(?:\.exe)?$/i', $binaryName) === 1) {
                $candidates[] = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . $replacement;
            } else {
                $candidates[] = preg_replace('/(?:-fpm|fpm)(?:\.exe)?$/i', PHP_OS_FAMILY === 'Windows' ? '.exe' : '', PHP_BINARY);
            }
        } else {
            $candidates[] = PHP_BINARY;
        }
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            if ($directory !== '') {
                $candidates[] = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $executable;
            }
        }
        foreach (array_unique(array_filter($candidates, 'is_string')) as $candidate) {
            if ($this->isCliBinary($candidate)) {
                return $candidate;
            }
        }
        throw new RuntimeException('Could not locate the PHP CLI; configure sync.php_binary.');
    }

    private function isCliBinary(string $candidate): bool
    {
        if (!is_file($candidate) || (!is_executable($candidate) && PHP_OS_FAMILY !== 'Windows')) {
            return false;
        }
        try {
            return trim((new ProcessRunner())->run([$candidate, '-r', 'echo PHP_SAPI;'], 5)) === 'cli';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Rotate an append-only log once it reaches $maxBytes: the current file
     * becomes `<log>.1` (replacing any previous one) and the worker starts a
     * fresh log on its next append. Best-effort; 0 or a negative cap disables
     * it. Static and side-effect-light so it is trivially testable.
     */
    public static function rotateLogFile(string $path, int $maxBytes): bool
    {
        if ($maxBytes <= 0 || !is_file($path)) {
            return false;
        }
        clearstatcache(true, $path);
        if ((int) filesize($path) < $maxBytes) {
            return false;
        }
        @unlink($path . '.1');
        return @rename($path, $path . '.1');
    }

    /** @param list<string> $candidates */
    private function firstExecutable(array $candidates): ?string
    {
        foreach (array_unique($candidates) as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

}
