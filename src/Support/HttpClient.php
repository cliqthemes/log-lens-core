<?php
declare(strict_types=1);

namespace LogLens\Support;

use RuntimeException;

/**
 * The single outbound-HTTP transport used by every integration (Linear,
 * Alerting, HTTP-ingest clients). Kept deliberately dependency-free so the core
 * stays "just works" on any host, while offering the robustness an enterprise
 * expects:
 *
 *   - TLS certificate verification is always on.
 *   - Connect + total timeouts.
 *   - An optional public-address-only mode ($publicOnly) for URLs an operator
 *     supplied, which re-validates and pins the destination on every attempt.
 *   - Automatic retries with exponential backoff on transient failures
 *     (connection errors, 408/429/5xx).
 *   - cURL when available, otherwise a stream (allow_url_fopen) fallback.
 *
 * Every client also accepts an injectable transport callable, so an application
 * that prefers a full HTTP stack (Guzzle, Symfony HttpClient, any PSR-18 client)
 * can wrap it in a closure and pass it in instead of this default.
 *
 * post() returns the HTTP response for any status (including 4xx/5xx); it throws
 * only when no response could be obtained after retries.
 */
final class HttpClient
{
    /**
     * @param (callable(string,string,array<string,string>):array{status:int,body:string})|null $transport
     *   Optional low-level sender used instead of the built-in cURL/stream
     *   transport. Lets an app plug in its own HTTP stack, and lets tests drive
     *   the retry/backoff logic deterministically without real network I/O.
     */
    public function __construct(
        private readonly int $timeout = 15,
        private readonly int $connectTimeout = 10,
        private readonly int $retries = 2,
        private $transport = null,
        private readonly bool $publicOnly = false,
    ) {
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string}
     */
    public function post(string $url, string $body, array $headers = []): array
    {
        $attempt = 0;
        while (true) {
            try {
                $response = $this->send($url, $body, $headers);
            } catch (RuntimeException $exception) {
                if ($attempt++ < $this->retries) {
                    $this->backoff($attempt);
                    continue;
                }
                throw $exception;
            }
            // Retry only transient HTTP conditions; return everything else
            // (including 4xx) so the caller can act on the status.
            if (($response['status'] === 408 || $response['status'] === 429 || $response['status'] >= 500)
                && $attempt++ < $this->retries) {
                $this->backoff($attempt);
                continue;
            }
            return $response;
        }
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string}
     */
    private function send(string $url, string $body, array $headers): array
    {
        // In public-only mode the destination is re-validated here rather than
        // once when it was configured, so a DNS answer that changed in between
        // (rebinding) is caught — and every retry re-checks, because each one
        // is a fresh connection.
        $pins = [];
        if ($this->publicOnly) {
            $pins = OutboundUrlGuard::pins($url, OutboundUrlGuard::publicIps($url));
        }
        if ($this->transport !== null) {
            return ($this->transport)($url, $body, $headers);
        }
        if (function_exists('curl_init')) {
            return $this->viaCurl($url, $body, $headers, $pins);
        }
        if (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            return $this->viaStream($url, $body, $headers);
        }
        throw new RuntimeException('No HTTP transport available: enable ext-curl or allow_url_fopen.');
    }

    /**
     * @param array<string,string> $headers
     * @param list<string> $pins CURLOPT_RESOLVE entries; see OutboundUrlGuard.
     * @return array{status:int,body:string}
     */
    private function viaCurl(string $url, string $body, array $headers, array $pins = []): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Could not initialize the HTTP client.');
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $this->headerLines($headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($pins !== []) {
            // Connect to the addresses that were just validated. Without this,
            // cURL would resolve the host itself and could reach a different —
            // internal — address than the one the guard approved.
            curl_setopt($handle, CURLOPT_RESOLVE, $pins);
        }
        $responseBody = curl_exec($handle);
        if ($responseBody === false) {
            $error = curl_error($handle);
            curl_close($handle);
            throw new RuntimeException('HTTP request failed: ' . $error);
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        return ['status' => $status, 'body' => (string) $responseBody];
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string}
     */
    private function viaStream(string $url, string $body, array $headers): array
    {
        // Note for public-only mode: send() has already rejected a URL that
        // resolves anywhere private, but the stream wrapper resolves the host
        // again itself and offers no equivalent of CURLOPT_RESOLVE, so a
        // rebinding window remains on this fallback path. Install ext-curl to
        // close it.
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $this->headerLines($headers)),
                'content' => $body,
                'timeout' => $this->timeout,
                'ignore_errors' => true, // capture body + status even on 4xx/5xx
                'follow_location' => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $responseBody = @file_get_contents($url, false, $context);
        if ($responseBody === false) {
            throw new RuntimeException('HTTP request failed (stream transport).');
        }
        // $http_response_header is populated by the stream wrapper.
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
                $status = (int) $match[1];
            }
        }
        return ['status' => $status, 'body' => (string) $responseBody];
    }

    /**
     * @param array<string,string> $headers
     * @return list<string>
     */
    private function headerLines(array $headers): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        return $lines;
    }

    private function backoff(int $attempt): void
    {
        // An injected transport is the test/custom-stack path; skip the real
        // wait there so retry behaviour can be exercised without wall-clock delay.
        if ($this->transport !== null) {
            return;
        }
        // 100ms, 300ms, 700ms … capped at 2s.
        usleep((int) min(2_000_000, 100_000 * ((2 ** $attempt) - 1)));
    }
}
