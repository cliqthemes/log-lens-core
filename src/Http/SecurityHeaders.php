<?php
declare(strict_types=1);

namespace LogLens\Http;

use LogLens\Config;

/**
 * Hardening headers for the standalone front controller (M-10).
 *
 * The dashboard is a self-contained SPA served same-origin, so the policy can
 * be strict: scripts and XHR only from 'self'. Inline styles are permitted
 * because React/Radix set element style attributes, and the optional Google
 * Fonts appearance feature pulls stylesheets from fonts.googleapis.com and
 * font files from fonts.gstatic.com — the only cross-origin the UI ever needs.
 *
 * The Laravel embed inherits its host application's response headers, so this
 * is only applied by the standalone entry point.
 */
final class SecurityHeaders
{
    /** Send the hardening headers unless output already started or disabled. */
    public static function apply(): void
    {
        if (headers_sent() || !Config::bool('security.headers_enabled', true)) {
            return;
        }
        foreach (self::headers(self::isHttps()) as $name => $value) {
            header($name . ': ' . $value);
        }
    }

    /**
     * @param  bool|null  $https  Whether this request arrived over TLS; null
     *                            detects it from the server environment. Only
     *                            affects whether HSTS is emitted.
     * @return array<string,string>
     */
    public static function headers(?bool $https = null): array
    {
        $headers = [
            'Content-Security-Policy' => self::contentSecurityPolicy(),
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            // The dashboard uses none of these capabilities, so deny them
            // outright rather than inheriting the browser's permissive default.
            'Permissions-Policy' => 'accelerometer=(), camera=(), display-capture=(), '
                . 'geolocation=(), gyroscope=(), magnetometer=(), microphone=(), '
                . 'midi=(), payment=(), usb=()',
            // The SPA opens no cross-origin popups and needs no window handle
            // from an opener, so severing the relationship costs nothing and
            // closes cross-origin window references (XS-Leaks).
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];

        $hsts = self::strictTransportSecurity($https ?? self::isHttps());
        if ($hsts !== null) {
            $headers['Strict-Transport-Security'] = $hsts;
        }

        return $headers;
    }

    /**
     * The Content-Security-Policy string. An operator behind their own proxy
     * (or one embedding the dashboard) can replace it wholesale via
     * `security.content_security_policy` in config.php.
     */
    public static function contentSecurityPolicy(): string
    {
        $override = Config::string('security.content_security_policy', '');
        if ($override !== '') {
            return $override;
        }
        return implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "img-src 'self' data:",
            "font-src 'self' https://fonts.gstatic.com",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "script-src 'self'",
            "connect-src 'self'",
            "form-action 'self'",
        ]);
    }

    /**
     * HSTS, or null when it must not be sent.
     *
     * Deliberately opt-in (`security.hsts_max_age`, 0 = off) and only ever sent
     * over TLS. HSTS is a promise the *host* keeps, not a per-app setting: once
     * a browser has seen it, every plaintext request to that host — including
     * every other site sharing it, and every subdomain under `includeSubDomains`
     * — is refused for the whole max-age, with no way to retract it early. Log
     * Lens is very often mounted on a shared or local-development host, so
     * turning that on by default could take a developer's entire `.test` domain
     * offline. An operator who terminates TLS for the whole host opts in.
     */
    public static function strictTransportSecurity(bool $https): ?string
    {
        if (!$https) {
            return null;
        }
        $maxAge = Config::int('security.hsts_max_age', 0);
        if ($maxAge <= 0) {
            return null;
        }
        $value = 'max-age=' . $maxAge;
        if (Config::bool('security.hsts_include_subdomains', false)) {
            $value .= '; includeSubDomains';
        }
        if (Config::bool('security.hsts_preload', false)) {
            $value .= '; preload';
        }
        return $value;
    }

    /**
     * Whether this request arrived over TLS. X-Forwarded-Proto is honoured
     * because a TLS-terminating proxy is the normal way Log Lens is served over
     * HTTPS; a forged value can only cause HSTS to be *offered* on a plaintext
     * response, which a browser receiving it over plaintext ignores.
     */
    private static function isHttps(): bool
    {
        $https = (string) ($_SERVER['HTTPS'] ?? '');
        if ($https !== '' && strtolower($https) !== 'off') {
            return true;
        }
        if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }
        return (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }
}
