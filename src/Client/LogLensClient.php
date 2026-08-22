<?php
declare(strict_types=1);

namespace LogLens\Client;

use Throwable;

/**
 * Dependency-free PHP client for reporting errors to a Log Lens HTTP ingest
 * endpoint from any script, worker, or framework. Copy this one file into a
 * project or install the package — it needs only ext-curl and ext-json.
 *
 *   $client = new LogLensClient('https://logs.example.com/?api=ingest&app=api', 'llk_…', [
 *       'environment' => 'production',
 *       'release' => 'v2.4.0',
 *   ]);
 *   $client->install();                 // capture uncaught exceptions + fatals
 *   $client->captureException($e);      // or report manually
 *   $client->captureMessage('Queue backlog high', 'WARNING', ['tags' => ['queue']]);
 */
final class LogLensClient
{
    /** Flush early so a long-lived worker that never calls flush() cannot grow
     *  the buffer without bound. */
    private const MAX_BUFFER = 50;

    /** @var list<array<string,mixed>> */
    private array $buffer = [];
    private bool $installed = false;
    /** @var (callable(string,string):void)|null */
    private $transport;

    /**
     * @param array<string,mixed> $options environment, release, channel, server_name, timeout
     * @param (callable(string,string):void)|null $transport Custom sender (url, json-body); defaults to cURL.
     */
    public function __construct(
        private readonly string $ingestUrl,
        private readonly string $ingestKey,
        private readonly array $options = [],
        ?callable $transport = null,
    ) {
        $this->transport = $transport;
    }

    /** @param array<string,mixed> $extra tags, user, request, context, fingerprint, module */
    public function captureException(Throwable $exception, array $extra = []): void
    {
        $this->buffer[] = $this->event([
            'message' => $exception->getMessage() !== '' ? $exception->getMessage() : $exception::class,
            'exception_class' => $exception::class,
            'severity' => 'ERROR',
            'stack' => sprintf(
                "#0 %s(%d): thrown\n%s",
                $exception->getFile(),
                $exception->getLine(),
                $exception->getTraceAsString(),
            ),
        ], $extra);
        $this->flushIfFull();
    }

    /** @param array<string,mixed> $extra */
    public function captureMessage(string $message, string $severity = 'INFO', array $extra = []): void
    {
        if (trim($message) === '') {
            return;
        }
        $this->buffer[] = $this->event(['message' => $message, 'severity' => strtoupper($severity)], $extra);
        $this->flushIfFull();
    }

    /** Send everything buffered. Best-effort: never throws. */
    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }
        $events = $this->buffer;
        $this->buffer = [];
        $this->post(['events' => $events]);
    }

    private function flushIfFull(): void
    {
        if (count($this->buffer) >= self::MAX_BUFFER) {
            $this->flush();
        }
    }

    /**
     * Register global handlers so uncaught exceptions, fatal errors, and the
     * remaining buffer are all reported automatically. Safe to call once.
     */
    public function install(): void
    {
        if ($this->installed) {
            return;
        }
        $this->installed = true;

        set_exception_handler(function (Throwable $exception): void {
            $this->captureException($exception);
            $this->flush();
        });
        register_shutdown_function(function (): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $this->captureMessage($error['message'], 'CRITICAL', [
                    'context' => ['file' => $error['file'], 'line' => $error['line']],
                ]);
            }
            $this->flush();
        });
    }

    /**
     * @param array<string,mixed> $base
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function event(array $base, array $extra): array
    {
        $event = $base + [
            'channel' => $this->options['channel'] ?? 'php',
            'environment' => $this->options['environment'] ?? 'production',
        ];
        if (!empty($this->options['release'])) {
            $event['release'] = $this->options['release'];
        }
        $event['server_name'] = $this->options['server_name'] ?? (gethostname() ?: null);
        foreach (['tags', 'user', 'request', 'context', 'fingerprint', 'module'] as $key) {
            if (isset($extra[$key])) {
                $event[$key] = $extra[$key];
            }
        }
        return array_filter($event, static fn ($value): bool => $value !== null && $value !== []);
    }

    /** @param array<string,mixed> $payload */
    private function post(array $payload): void
    {
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($this->transport !== null) {
            ($this->transport)($this->ingestUrl, $body);
            return;
        }
        if ($this->ingestUrl === '' || !function_exists('curl_init')) {
            return;
        }
        try {
            $handle = curl_init($this->ingestUrl);
            if ($handle === false) {
                return;
            }
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Log-Lens-Ingest-Key: ' . $this->ingestKey],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => (int) ($this->options['timeout'] ?? 4),
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);
            curl_exec($handle);
            curl_close($handle);
        } catch (Throwable) {
            // Reporting must never break the host application.
        }
    }
}
