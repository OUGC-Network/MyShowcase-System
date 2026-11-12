<?php
/**
 * Unit tests of the identifier generators in src/Util.
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

echo "{$passes} passed, {$failures} failed\n";

exit($failures === 0 ? 0 : 1);
