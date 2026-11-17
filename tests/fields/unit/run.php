<?php
/**
 * Unit tests of the multiple-choice rule (decisions D184 and D187): which field types take several values, and the column
 * type a field with several values must have. No database, no network:
 *
 *     php tests/fields/unit/run.php
 */

declare(strict_types=1);

error_reporting(E_ALL);

define('IN_MYBB', 1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'MyShowcase\\')) {
        $file = dirname(__DIR__, 3) . '/Upload/inc/plugins/myshowcase/src/' . str_replace('\\', '/', substr($class, 11)) . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    }
});

use MyShowcase\Util\MultipleChoiceUtil;

$failures = 0;
$passes = 0;

$check = static function (bool $ok, string $name) use (&$failures, &$passes): void {
    if ($ok) {
        $passes++;
    } else {
        $failures++;
        echo "FAIL {$name}\n";
    }
};

// D184: checkbox is always multi-valued; select only with the flag; radio and the others never.
$check(MultipleChoiceUtil::isMultiple('checkbox', 0) === true, 'checkbox with flag 0 takes several values');
$check(MultipleChoiceUtil::isMultiple('checkbox', 1) === true, 'checkbox with flag 1 takes several values');
$check(MultipleChoiceUtil::isMultiple('select', 1) === true, 'select with flag 1 takes several values');
$check(MultipleChoiceUtil::isMultiple('select', '1') === true, 'select with the flag as a string 1 takes several values');
$check(MultipleChoiceUtil::isMultiple('select', 0) === false, 'select with flag 0 takes one value');
$check(MultipleChoiceUtil::isMultiple('radio', 1) === false, 'radio ignores the flag (D131)');
$check(MultipleChoiceUtil::isMultiple('text', 1) === false, 'text ignores the flag (D131)');
$check(MultipleChoiceUtil::isMultiple('select2_users', 1) === false, 'select2_users ignores the flag (D131)');

// D187: a field with several values is text; any other type is refused as typed.
$check(MultipleChoiceUtil::columnTypeFits('checkbox', 0, 'text') === true, 'checkbox as text fits');
$check(MultipleChoiceUtil::columnTypeFits('checkbox', 0, 'TEXT') === true, 'the type name is compared without case');
$check(MultipleChoiceUtil::columnTypeFits('checkbox', 0, 'int') === false, 'checkbox as int is refused');
$check(MultipleChoiceUtil::columnTypeFits('checkbox', 0, 'varchar') === false, 'checkbox as varchar is refused');
$check(MultipleChoiceUtil::columnTypeFits('select', 1, 'int') === false, 'select with several values as int is refused');
$check(MultipleChoiceUtil::columnTypeFits('select', 1, 'text') === true, 'select with several values as text fits');
$check(MultipleChoiceUtil::columnTypeFits('select', 0, 'int') === true, 'a single select keeps its int column');
$check(MultipleChoiceUtil::columnTypeFits('radio', 1, 'int') === true, 'a radio keeps its int column whatever the flag');

echo "{$passes} passed, {$failures} failed\n";
exit($failures ? 1 : 0);
