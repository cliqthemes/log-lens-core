<?php
declare(strict_types=1);

namespace LogLens\Support;

use RuntimeException;

/**
 * SSRF guard for URLs the server itself fetches (today: alert channel
 * webhooks).
 *
 * An operator who can configure an alert channel could otherwise point it at a
 * service only the server can reach — loopback, RFC1918, link-local, the cloud
 * metadata endpoint — and use "Test connection" or a live alert as a blind SSRF
 * probe. So the URL's host is resolved and every answer must be a public
 * address.
 *
 * Validating once, when the channel is saved, is not enough on its own: DNS can
 * answer differently by the time delivery happens (DNS rebinding), and even a
 * check immediately before the request leaves a gap between the check's lookup
 * and the transport's own. {@see pins()} closes that gap for the cURL transport
 * by pinning the hostname to the exact addresses that were validated, so the
 * connection cannot land anywhere else. The check therefore runs twice: at
 * configuration time for a clear error message, and again at delivery time as
 * the control that actually holds.
 */
final class OutboundUrlGuard
{
    /**
     * Resolve $url's host and return its addresses, all of which are public.
     *
     * @return list<string>
     * @throws RuntimeException when the URL is unusable or resolves anywhere
     *                          that is not a public address.
     */
    public static function publicIps(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new RuntimeException('The URL must include a host.');
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : self::resolve($host);
        if ($ips === []) {
            throw new RuntimeException('The URL host could not be resolved.');
        }
        foreach ($ips as $ip) {
            if (!self::isPublic($ip)) {
                throw new RuntimeException(
                    'The URL must not point at a private, loopback, or link-local address.'
                );
            }
        }
        return $ips;
    }

    /**
     * CURLOPT_RESOLVE entries pinning $url's host:port to $ips, so cURL uses
     * the addresses that were just validated instead of resolving again.
     *
     * @param  list<string>  $ips
     * @return list<string>
     */
    public static function pins(string $url, array $ips): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '' || $ips === []) {
            return [];
        }
        $port = parse_url($url, PHP_URL_PORT);
        if (!is_int($port)) {
            $port = strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'http' ? 80 : 443;
        }
        // One entry carries every address for the host:port pair.
        return [$host . ':' . $port . ':' . implode(',', $ips)];
    }

    public static function isPublic(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        $ips = [];
        foreach (['A' => 'ip', 'AAAA' => 'ipv6'] as $type => $field) {
            foreach (@dns_get_record($host, constant("DNS_{$type}")) ?: [] as $record) {
                if (isset($record[$field]) && is_string($record[$field])) {
                    $ips[] = $record[$field];
                }
            }
        }
        return array_values(array_unique($ips));
    }
}
