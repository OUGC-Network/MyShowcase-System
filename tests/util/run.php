<?php
/**
 * Unit tests of the identifier generators in src/Util: public ids and UUID version 7.
 * No database, no MyBB: php tests/util/run.php
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
use MyShowcase\Util\UuidUtil;

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

// Public ids
$stringUtil = new StringUtil();

$publicIds = [];

for ($i = 0; $i < 2000; $i++) {
    $publicIds[] = $stringUtil->generatePublicId();
}

check(count(array_filter($publicIds, static fn(string $id): bool => preg_match('/^[0-9a-z]{10}$/D', $id) === 1)) === 2000,
    'public id: slugLength characters of [0-9a-z]');
check(count(array_unique($publicIds)) === 2000, 'public id: 2000 draws are distinct');
check(preg_match('/[g-z]/', implode('', $publicIds)) === 1, 'public id: the alphabet goes past hex');
check(strlen($stringUtil->generatePublicId(16)) === 16, 'public id: an explicit length is honoured');
check($stringUtil->publicIdPattern() === '[0-9a-z]{10}', 'public id pattern: [0-9a-z]{slugLength}');
check($stringUtil->isValidPublicId($publicIds[0]), 'isValidPublicId accepts a generated id');
check(!$stringUtil->isValidPublicId('ABCDEFGHIJ'), 'isValidPublicId refuses upper case');
check(!$stringUtil->isValidPublicId('abc-defghi'), 'isValidPublicId refuses other characters');
check(!$stringUtil->isValidPublicId('abcdefghi') && !$stringUtil->isValidPublicId('abcdefghijk'), 'isValidPublicId refuses another length');
check(!$stringUtil->isValidPublicId("abcdefghij\n"), 'isValidPublicId refuses a trailing newline');

// UUID version 7
$uuidUtil = new UuidUtil();

$before = (int)floor(microtime(true) * 1000);
$uuid = $uuidUtil->generateUuidV7();
$after = (int)floor(microtime(true) * 1000);

check(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $uuid) === 1, 'uuid v7: lowercase 8-4-4-4-12');
check($uuid[14] === '7', 'uuid v7: version nibble is 7');
check(in_array($uuid[19], ['8', '9', 'a', 'b'], true), 'uuid v7: variant nibble is 8, 9, a or b');

$embedded = hexdec(substr(str_replace('-', '', $uuid), 0, 12));

check($embedded >= $before && $embedded <= $after, 'uuid v7: the embedded milliseconds are the time of generation');
check(abs($embedded - (int)floor(microtime(true) * 1000)) < 5000, 'uuid v7: the embedded timestamp is within a few seconds of now');

$batch = [];

for ($i = 0; $i < 200; $i++) {
    $batch[] = $uuidUtil->generateUuidV7();
}

check(count(array_filter($batch, static fn(string $id): bool => $id[14] === '7' && in_array($id[19], ['8', '9', 'a', 'b'], true))) === 200,
    'uuid v7: version and variant in every value of a batch');
check(count(array_unique($batch)) === 200, 'uuid v7: 200 values are distinct');

$ordered = [];

for ($i = 0; $i < 20; $i++) {
    $ordered[] = $uuidUtil->generateUuidV7();

    usleep(2000);
}

$sorted = $ordered;

sort($sorted, SORT_STRING);

check($sorted === $ordered, 'uuid v7: values generated in different milliseconds sort in creation order');

check(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $uuidUtil->generateUuidV4()) === 1,
    'uuid v4 is unchanged');

echo "{$passes} passed, {$failures} failed\n";

exit($failures === 0 ? 0 : 1);
