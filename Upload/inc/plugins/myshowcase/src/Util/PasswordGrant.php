<?php

declare(strict_types=1);

namespace MyShowcase\Util;

/**
 * The grant of a guest to a showcase with a password (decision D296): a signed cookie, one per showcase.
 *
 * The cookie holds its expiry and an HMAC-SHA256 hex digest over the showcase id, the expiry, a SHA-256 fingerprint of the stored
 * password and, with the showcase's IP check, the visitor's address. A password change therefore cancels every cookie of the
 * showcase, and an address check binds the cookie to the address it was made for. The key is a random secret kept in MyBB's
 * datacache (MyBB 1.9 has no board secret of its own); a board without the datacache entry makes a new one.
 */
final class PasswordGrant
{
    public const COOKIE_PREFIX = 'myshowcase_pw_';

    public const SECRET_KEY = 'myshowcase_secret';

    public static function cookieName(int $showcase_id): string
    {
        return self::COOKIE_PREFIX . $showcase_id;
    }

    public static function newSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * The key of the grants, from MyBB's datacache. Created once when the datacache has none.
     */
    public static function secretFrom(object $cache): string
    {
        $data = $cache->read(self::SECRET_KEY);

        if (is_array($data) && isset($data['secret']) && is_string($data['secret']) && strlen($data['secret']) === 64) {
            return $data['secret'];
        }

        $secret = self::newSecret();

        $cache->update(self::SECRET_KEY, ['secret' => $secret]);

        return $secret;
    }

    /**
     * The cookie of a grant: "<expiry>.<hex HMAC>". $ip is '' when the showcase has no IP check.
     */
    public static function value(string $secret, int $showcase_id, int $expires, string $password, string $ip = ''): string
    {
        return $expires . '.' . hash_hmac('sha256', self::payload($showcase_id, $expires, $password, $ip), $secret);
    }

    /**
     * Whether a cookie grants: well formed, not expired at $now, and with a signature that matches the showcase, the expiry, the
     * current password and (with the IP check, $ip_check true) the address $ip.
     */
    public static function valid(string $cookie, string $secret, int $showcase_id, string $password, string $ip, int $now, bool $ip_check = true): bool
    {
        if (preg_match('/^(\d{1,12})\.([0-9a-f]{64})$/', $cookie, $parts) !== 1) {
            return false;
        }

        $expires = (int)$parts[1];

        if ($expires <= $now) {
            return false;
        }

        $expected = hash_hmac('sha256', self::payload($showcase_id, $expires, $password, $ip_check ? $ip : ''), $secret);

        return hash_equals($expected, $parts[2]);
    }

    private static function payload(int $showcase_id, int $expires, string $password, string $ip): string
    {
        return implode("\n", [(string)$showcase_id, (string)$expires, hash('sha256', $password), $ip]);
    }
}
