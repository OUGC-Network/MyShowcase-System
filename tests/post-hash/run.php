<?php
/**
 * Unit tests of the post hash: the generator (the shared public id logic), the validator used by the create and
 * comment forms, and the route requirement of the upload API.
 * No database, no MyBB: php tests/post-hash/run.php
 */

declare(strict_types=1);

error_reporting(E_ALL);

define('IN_MYBB', 1);
define('MyShowcase\SETTINGS', ['slugLength' => 10]);
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

require_once \MyShowcase\ROOT . '/Plugin/Core.php';
require_once \MyShowcase\ROOT . '/Plugin/Functions.php';

use MyShowcase\Util\StringUtil;

$failures = 0;
$passes = 0;

function check(bool $condition, string $name): void
{
    global $failures, $passes;

    if ($condition) {
        ++$passes;
    } else {
        ++$failures;

        echo "FAIL {$name}\n";
    }
}

$stringUtil = new StringUtil();

// Generator: the same logic as public_id
$hashes = [];

for ($i = 0; $i < 20000; ++$i) {
    $hashes[] = $stringUtil->generatePublicId();
}

check(count(array_filter($hashes, static fn(string $hash): bool => strlen($hash) === 10)) === count($hashes), 'generator: every post hash has 10 characters');
check(count(array_filter($hashes, static fn(string $hash): bool => preg_match('/^[0-9a-z]{10}$/D', $hash) === 1)) === count($hashes), 'generator: every post hash uses only 0-9 and a-z');
check(count(array_unique($hashes)) === count($hashes), 'generator: no duplicate in a sample of 20000');
check(preg_match('/[0-9]/', implode('', $hashes)) === 1 && preg_match('/[a-z]/', implode('', $hashes)) === 1, 'generator: both digits and letters are drawn');
check(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $hashes[0]), 'generator: no UUID shape');

// Validator: accepts a generated value, refuses everything else
$generated = $hashes[0];

check($stringUtil->isValidPublicId($generated), 'validator: accepts a generated post hash');

$refused = [
    'UUID v4' => '3f6b2c9e-8a1d-4e7b-9c05-2d4f6a8b1e3c',
    'nine characters' => 'abcdefghi',
    'eleven characters' => 'abcdefghijk',
    'uppercase' => 'ABCDEFGHIJ',
    'mixed case' => 'abcdeFGHij',
    'symbols' => 'abc-defghi',
    'dot' => 'abcdefgh.j',
    'space' => 'abcdefghi ',
    'trailing newline' => "abcdefghij\n",
    'empty' => '',
    'SQL quote' => "abcdefgh'j",
];

foreach ($refused as $label => $value) {
    check(!$stringUtil->isValidPublicId($value), "validator: refuses {$label}");
}

// The route requirement (src/Routing/Routing.php) is the same pattern as the validator
$routing_source = file_get_contents(\MyShowcase\ROOT . '/src/Routing/Routing.php');

check($routing_source !== false, 'route: Routing.php is readable');

if ($routing_source !== false) {
    check(str_contains($routing_source, "\$public_id_pattern = (new StringUtil())->publicIdPattern();"), 'route: the route pattern comes from publicIdPattern()');

    check(preg_match("/'post_hash'\\s*=>\\s*\\\$public_id_pattern/", $routing_source) === 1, 'route: the upload route requires post_hash to match the public id pattern');
}

check($stringUtil->publicIdPattern() === '[0-9a-z]{10}', 'route: the pattern is [0-9a-z]{slugLength}');

// The pattern the validator applies is the one the routes receive
$pattern = '/^' . $stringUtil->publicIdPattern() . '$/D';

check(preg_match($pattern, $generated) === 1, 'route: a generated post hash matches the route requirement');
check(preg_match($pattern, '3f6b2c9e-8a1d-4e7b-9c05-2d4f6a8b1e3c') === 0, 'route: a UUID v4 matches no route requirement');

echo "{$passes} passed, {$failures} failed\n";

exit($failures === 0 ? 0 : 1);
