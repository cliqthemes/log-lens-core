<?php
declare(strict_types=1);

namespace LogLens\Http;

use LogLens\Config;

/**
 * Request-level access controls: an optional API key and a cross-origin guard.
 *
 * check() returns a LogLensResponse to short-circuit a blocked request, or null
 * when the request may proceed — it performs no output itself.
 *
 * A browser always attaches an Origin header to POST/PUT/PATCH/DELETE requests
 * and forbids page JavaScript from forging or suppressing it, so a mismatched
 * Origin is a reliable CSRF signal. Non-browser clients (curl, the Claude/Codex
 * skills, cron) send no Origin and are allowed through unchanged.
 *
 * "Same origin" here means the same host and — when both sides state one — the
 * same port; see {@see sameOrigin()} for why the port matters and the scheme
 * deliberately does not.
 */
final class RequestGuard
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public static function check(LogLensRequest $request): ?LogLensResponse
    {
        return self::authFailure($request) ?? self::originFailure($request);
    }

    /**
     * Enforce the configured API key. When no token is configured the API is
     * unauthenticated (the local-first default).
     */
    private static function authFailure(LogLensRequest $request): ?LogLensResponse
    {
        $expected = Config::string('auth.token');
        if ($expected === '') {
            return null;
        }
        $presented = self::presentedToken($request);
        if ($presented === null || !hash_equals($expected, $presented)) {
            return LogLensResponse::json(
                ['error' => 'A valid API key is required. Send it as X-Log-Lens-Token or Authorization: Bearer.'],
                401,
            );
        }
        return null;
    }

    private static function presentedToken(LogLensRequest $request): ?string
    {
        $header = $request->header('x-log-lens-token');
        if ($header !== '') {
            return $header;
        }
        $authorization = $request->header('authorization');
        if (stripos($authorization, 'Bearer ') === 0) {
            $token = trim(substr($authorization, 7));
            return $token !== '' ? $token : null;
        }
        return null;
    }

    private static function originFailure(LogLensRequest $request): ?LogLensResponse
    {
        if (in_array($request->method, self::SAFE_METHODS, true)) {
            return null;
        }
        $origin = $request->header('origin');
        if ($origin === '') {
            return null;
        }
        foreach (self::allowedOrigins($request) as $allowed) {
            if (self::sameOrigin($origin, $allowed)) {
                return null;
            }
        }
        return LogLensResponse::json(
            ['error' => 'Cross-origin request blocked. State-changing requests must come from the same origin.'],
            403,
        );
    }

    /**
     * The origins a state-changing request may come from: the pinned
     * LOG_LENS_URL when one is configured, and the base this request itself
     * arrived on (Host header, or the front controller's derived base).
     *
     * @return list<string>
     */
    private static function allowedOrigins(LogLensRequest $request): array
    {
        $origins = [];
        $pinned = Config::string('LOG_LENS_URL', '');
        if ($pinned !== '') {
            $origins[] = $pinned;
        }
        $host = $request->header('host');
        if ($host !== '') {
            // Scheme is irrelevant to the comparison below, so any is fine here.
            $origins[] = 'http://' . $host;
        }
        $base = $request->baseUrl();
        if ($base !== '') {
            $origins[] = $base;
        }
        return $origins;
    }

    /**
     * Same-origin test on host and, when both sides state one, port.
     *
     * Host alone is not enough: on a development machine every local site is
     * `localhost`, so a page on `localhost:3000` could drive a Log Lens on
     * `localhost:8080` with the old comparison. Comparing the port closes that.
     *
     * The scheme is deliberately not compared. A reverse proxy that terminates
     * TLS without setting X-Forwarded-Proto makes the server believe it is
     * serving `http` while the browser correctly reports an `https` Origin —
     * comparing schemes would break those deployments for no gain, since a page
     * cannot be served over `http` from a host:port that answers `https`.
     *
     * A side with no explicit port compares on host only: the common production
     * shape (Origin `https://app.example.com`, Host `app.example.com`) states no
     * port on either side, and inventing 443/80 defaults there would reject it.
     */
    private static function sameOrigin(string $origin, string $allowed): bool
    {
        $originHost = parse_url($origin, PHP_URL_HOST);
        $allowedHost = parse_url($allowed, PHP_URL_HOST);
        if (!is_string($originHost) || !is_string($allowedHost) || $originHost === '' || $allowedHost === '') {
            return false;
        }
        if (strcasecmp($originHost, $allowedHost) !== 0) {
            return false;
        }
        $originPort = parse_url($origin, PHP_URL_PORT);
        $allowedPort = parse_url($allowed, PHP_URL_PORT);
        if (is_int($originPort) && is_int($allowedPort)) {
            return $originPort === $allowedPort;
        }
        return true;
    }
}
