<?php
declare(strict_types=1);

namespace LogLens\Http;

use LogLens\Identity\Actor;

/**
 * Transport-agnostic view of an inbound request.
 *
 * The core dispatcher depends only on this DTO, never on PHP superglobals, so
 * the same controllers serve both the standalone front controller and a host
 * framework (e.g. a Laravel adapter) that builds this object from its own request.
 */
final class LogLensRequest
{
    /**
     * @param array<string,mixed> $query   Query parameters (the ?api=… string).
     * @param array<string,mixed> $body    Decoded JSON body (empty array when none).
     * @param array<string,string> $headers Header values keyed by lowercase name.
     * @param string|null $rawBody          The undecoded request body, when the
     *   transport can supply it. Required only for signature verification (e.g.
     *   Linear webhooks) where the exact received bytes matter.
     * @param string|null $baseUrl          Absolute base (scheme://host/path, no
     *   query) of this request's front controller, so absolute links can be
     *   derived from the actual deployment instead of a hardcoded host.
     * @param string|null $clientIp         Best-known source IP of the caller,
     *   used only for the optional per-IP ingest throttle. Null when the
     *   transport cannot supply it (the throttle then simply does not apply).
     * @param Actor|null $actor             The authenticated actor, supplied by
     *   the transport (e.g. the Laravel adapter from the host user). Never built
     *   from client headers, so it cannot be spoofed. Null ⇒ the default single
     *   local owner is used.
     * @param list<array{id:string,label:string}>|null $assignableUsers People an
     *   issue can be assigned to (C-2), supplied by the transport (e.g. the
     *   Laravel adapter's `log-lens.assignable-users` callable). Log Lens has no
     *   user table of its own, so this is opaque id/label pairs from the host,
     *   not a lookup. Null/empty ⇒ nobody assignable (standalone's default —
     *   see {@see \LogLens\Identity\SystemAssignableUsersResolver}).
     */
    public function __construct(
        public readonly string $method,
        public readonly array $query,
        public readonly array $body,
        public readonly array $headers = [],
        public readonly ?string $rawBody = null,
        public readonly ?string $baseUrl = null,
        public readonly ?string $clientIp = null,
        public readonly ?Actor $actor = null,
        public readonly ?array $assignableUsers = null,
    ) {
    }

    /** The transport-provided actor, if any (see {@see \LogLens\Identity\SystemIdentityResolver}). */
    public function actor(): ?Actor
    {
        return $this->actor;
    }

    /** @return list<array{id:string,label:string}> */
    public function assignableUsers(): array
    {
        return $this->assignableUsers ?? [];
    }

    /**
     * The absolute base URL this request arrived on (scheme://host/path, no
     * query string). Used to build copy-paste URLs — e.g. the ingest endpoint —
     * that point at wherever the app is actually served, so nothing assumes a
     * particular host, port, or TLD.
     */
    public function baseUrl(): string
    {
        return $this->baseUrl ?? '';
    }

    public function header(string $name): string
    {
        return trim((string) ($this->headers[strtolower($name)] ?? ''));
    }

    /**
     * The ingest key presented by the caller, checked in order: the
     * dedicated header, a Bearer authorization header, then the query
     * string — so a browser SDK, a server SDK, and a copy-pasted curl
     * command can each supply it the way that's easiest for them.
     *
     * The query-string form is not a convenience that could be dropped: the
     * browser SDK (`public/loglens.js`) posts via `navigator.sendBeacon` with a
     * `text/plain` body specifically so a cross-origin push needs no CORS
     * preflight, and neither of those can carry a custom header. The key is a
     * write-only push token by design (it can add events and nothing else), so
     * the trade — it appears in the web server's access log — is accepted, not
     * overlooked. Rotate it from Settings → Plugins → HTTP ingest if a log with
     * it in has been shared; the dashboard's own token never travels this way.
     */
    public function presentedIngestKey(): string
    {
        $header = $this->header('x-log-lens-ingest-key');
        if ($header !== '') {
            return $header;
        }
        $authorization = $this->header('authorization');
        if (stripos($authorization, 'Bearer ') === 0) {
            return trim(substr($authorization, 7));
        }
        return (string) ($this->query['key'] ?? '');
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function boolean(string $key): bool
    {
        return filter_var($this->query[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /** Build a request from PHP superglobals (standalone front controller). */
    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        $rawBody = file_get_contents('php://input');
        $rawBody = $rawBody === false ? '' : $rawBody;
        $decoded = json_decode($rawBody !== '' ? $rawBody : '{}', true);

        // Derive the absolute base from the actual request, honoring reverse
        // proxies. No assumption about host, port, or TLD.
        //
        // Host and X-Forwarded-Proto are taken at face value here, unlike
        // X-Forwarded-For below, and deliberately so: baseUrl()'s only consumer
        // is the copy-paste ingest URL shown in the settings panel, so forging
        // either header only changes a string displayed back to whoever sent it
        // — there is no redirect, cache key, or link in an outbound email built
        // from this. Requiring a trust flag would instead break the scheme for
        // every install that terminates TLS at a proxy. Set LOG_LENS_URL to pin
        // a canonical base and take the request out of it entirely.
        $secure = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443')
            || (strtolower($headers['x-forwarded-proto'] ?? '') === 'https');
        $host = $headers['host'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $baseUrl = ($secure ? 'https' : 'http') . '://' . $host . (is_string($path) && $path !== '' ? $path : '/');

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $_GET,
            is_array($decoded) ? $decoded : [],
            $headers,
            $rawBody,
            $baseUrl,
            self::resolveClientIp($_SERVER, $headers),
        );
    }

    /**
     * Peer IP of the caller. Uses REMOTE_ADDR — the only unspoofable source —
     * unless `ingest.trust_forwarded_for` is enabled, in which case the
     * left-most X-Forwarded-For entry is honored (set this only when a trusted
     * reverse proxy sits in front, or clients could forge the header).
     *
     * @param array<string,mixed> $server
     * @param array<string,string> $headers
     */
    private static function resolveClientIp(array $server, array $headers): ?string
    {
        if (\LogLens\Config::bool('ingest.trust_forwarded_for', false)) {
            $forwarded = trim((string) ($headers['x-forwarded-for'] ?? ''));
            if ($forwarded !== '') {
                $first = trim(explode(',', $forwarded)[0]);
                if ($first !== '') {
                    return $first;
                }
            }
        }
        $remote = trim((string) ($server['REMOTE_ADDR'] ?? ''));
        return $remote !== '' ? $remote : null;
    }
}
