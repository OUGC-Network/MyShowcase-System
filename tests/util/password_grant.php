<?php
/**
 * Unit tests of the guest password grant (decision D296): the signed cookie of a showcase with a password.
 * The cookie holds its expiry and an HMAC-SHA256 over the showcase id, the expiry, a fingerprint of the stored password and,
 * with the IP check on, the visitor's address. No database, no MyBB: php tests/util/password_grant.php
 */

declare(strict_types=1);

error_reporting(E_ALL);

define('IN_MYBB', 1);
define('MyShowcase\SETTINGS', []);
define('MyShowcase\DEBUG', false);
define('MyShowcase\ROOT', dirname(__DIR__, 2) . '/Upload/inc/plugins/myshowcase');

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'MyShowcase\\')) {
        $file = \MyShowcase\ROOT . '/src/' . str_replace('\\', '/', substr($class, 11)) . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    }
});

use MyShowcase\Util\PasswordGrant;

$passes = 0;
$failures = 0;
$check = static function (bool $ok, string $message) use (&$passes, &$failures): void {
    if ($ok) {
        $passes++;
    } else {
        $failures++;
        echo "FAIL {$message}\n";
    }
};

$secret = str_repeat('a', 64);
$other_secret = str_repeat('b', 64);
$now = 1_800_000_000;
$expires = $now + 3600;
$password = 'correct-horse';

$cookie = PasswordGrant::value($secret, 7, $expires, $password);
$check(PasswordGrant::valid($cookie, $secret, 7, $password, '', $now), 'a cookie of this showcase, not expired, with the same secret and password grants');
$check(!PasswordGrant::valid('', $secret, 7, $password, '', $now), 'an empty cookie does not grant');
$check(!PasswordGrant::valid('not-a-cookie', $secret, 7, $password, '', $now), 'a malformed cookie does not grant');
$check(!PasswordGrant::valid('abc.xyz', $secret, 7, $password, '', $now), 'a non-hex signature does not grant');

[$exp, $mac] = explode('.', $cookie, 2);
$flipped = $mac[0] === '0' ? '1' . substr($mac, 1) : '0' . substr($mac, 1);
$check(!PasswordGrant::valid($exp . '.' . $flipped, $secret, 7, $password, '', $now), 'a changed signature does not grant');
$check(!PasswordGrant::valid(($expires + 60) . '.' . $mac, $secret, 7, $password, '', $now), 'a changed expiry (the signature no longer matches) does not grant');
$check(!PasswordGrant::valid($cookie, $secret, 7, $password, '', $expires), 'an expired cookie (expiry equal to now) does not grant');
$check(!PasswordGrant::valid($cookie, $secret, 7, $password, '', $expires + 1), 'an expired cookie (after the expiry) does not grant');
$check(!PasswordGrant::valid($cookie, $secret, 8, $password, '', $now), 'a cookie of another showcase does not grant');
$check(!PasswordGrant::valid($cookie, $secret, 7, 'changed-password', '', $now), 'after the showcase password changed, the cookie does not grant');
$check(!PasswordGrant::valid($cookie, $other_secret, 7, $password, '', $now), 'a cookie made with another secret does not grant');

$bound = PasswordGrant::value($secret, 7, $expires, $password, '127.0.0.1');
$check(PasswordGrant::valid($bound, $secret, 7, $password, '127.0.0.1', $now), 'with the IP check, the same address grants');
$check(!PasswordGrant::valid($bound, $secret, 7, $password, '10.0.0.9', $now), 'with the IP check, a cookie from another address does not grant');
$check(!PasswordGrant::valid($bound, $secret, 7, $password, '', $now), 'with the IP check, a cookie used without an address does not grant');

$check(PasswordGrant::valid(PasswordGrant::value($secret, 7, $expires, $password, ''), $secret, 7, $password, '10.0.0.9', $now, false),
    'without the IP check (the last argument false) the address is not part of the signature');
$check(!PasswordGrant::valid(PasswordGrant::value($secret, 7, $expires, $password, ''), $secret, 7, $password, '10.0.0.9', $now),
    'with the IP check on (the default), an address the cookie was not made for does not grant');

$check(PasswordGrant::cookieName(7) === 'myshowcase_pw_7', 'the cookie is named per showcase');
$check(PasswordGrant::cookieName(7) !== PasswordGrant::cookieName(8), 'two showcases have two cookies');
$check(strlen(PasswordGrant::newSecret()) === 64 && PasswordGrant::newSecret() !== PasswordGrant::newSecret(), 'a new secret is 32 random bytes in hex, and differs each time');

echo "{$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
