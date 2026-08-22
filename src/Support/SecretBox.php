<?php
declare(strict_types=1);

namespace LogLens\Support;

use LogLens\Config;
use RuntimeException;

/**
 * Authenticated encryption for secrets stored at rest (e.g. a Linear API key
 * kept in the SQLite settings table).
 *
 * The encryption key is derived from a deployment secret — `LOG_LENS_SECRET`
 * in the environment or `.env`, surfaced as config `linear.secret` — so the
 * key material never lives inside the database next to the ciphertext.
 *
 * When no deployment secret is configured, encryption cannot provide real
 * at-rest protection, so `secured()` reports false and the caller decides
 * whether to persist the secret in a clearly-marked, unencrypted envelope or
 * to refuse. In every case the stored value is a self-describing token, so
 * decrypt() keeps working after a secret is later added or rotated for values
 * that were written while it was present.
 */
final class SecretBox
{
    private const CIPHER = 'sb1:';   // libsodium secretbox, key from deployment secret
    private const PLAIN = 'plain:';  // no deployment secret configured — not encrypted

    /** Whether a deployment secret is configured, enabling real encryption. */
    public static function secured(): bool
    {
        return self::secret() !== '';
    }

    /**
     * Encrypt a secret into a self-describing storage token. Returns an empty
     * string for empty input so callers can store "no secret" transparently.
     */
    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }
        $secret = self::secret();
        if ($secret === '') {
            return self::PLAIN . base64_encode($plaintext);
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, self::key($secret));
        return self::CIPHER . base64_encode($nonce . $cipher);
    }

    /**
     * Decrypt a storage token produced by encrypt(). Returns '' for an empty
     * token. Throws when a ciphertext token cannot be opened (wrong or rotated
     * secret, tampering), so callers surface a clear configuration error rather
     * than silently using a corrupt key.
     */
    public static function decrypt(string $token): string
    {
        if ($token === '') {
            return '';
        }
        if (str_starts_with($token, self::PLAIN)) {
            $decoded = base64_decode(substr($token, strlen(self::PLAIN)), true);
            return $decoded === false ? '' : $decoded;
        }
        if (str_starts_with($token, self::CIPHER)) {
            $secret = self::secret();
            if ($secret === '') {
                throw new RuntimeException(
                    'Cannot decrypt a stored secret because LOG_LENS_SECRET is not configured.'
                );
            }
            $raw = base64_decode(substr($token, strlen(self::CIPHER)), true);
            if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
                throw new RuntimeException('Stored secret is malformed.');
            }
            $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plaintext = sodium_crypto_secretbox_open($cipher, $nonce, self::key($secret));
            if ($plaintext === false) {
                throw new RuntimeException(
                    'Stored secret could not be decrypted. LOG_LENS_SECRET may have changed.'
                );
            }
            return $plaintext;
        }
        // Legacy/untagged value: treat as literal plaintext for forward safety.
        return $token;
    }

    private static function secret(): string
    {
        return Config::string('linear.secret', '');
    }

    private static function key(string $secret): string
    {
        // Deterministic 32-byte key from an arbitrary-length deployment secret.
        return sodium_crypto_generichash($secret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
