<?php
/**
 * Unit tests of the entries import: its size limits, its file check, its report and its upload reader. No database,
 * no MyBB board: php tests/import/run.php
 */

declare(strict_types=1);

error_reporting(E_ALL);

ini_set('memory_limit', '1G');

define('IN_MYBB', 1);
define('TIME_NOW', 1794700000);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'MyShowcase\\')) {
        $file = dirname(__DIR__, 2) . '/Upload/inc/plugins/myshowcase/src/' . str_replace('\\', '/', substr($class, 11)) . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    }
});

use MyShowcase\Util\ImportLimits;

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

// --- Limits ------------------------------------------------------------------------------------------------------------

check(ImportLimits::bytesOf('128M') === 128 * 1024 * 1024, 'bytesOf reads M');
check(ImportLimits::bytesOf('2G') === 2 * 1024 * 1024 * 1024, 'bytesOf reads G');
check(ImportLimits::bytesOf('512K') === 512 * 1024, 'bytesOf reads K');
check(ImportLimits::bytesOf('-1') === -1, 'bytesOf keeps -1 as no limit');
check(ImportLimits::bytesOf('') === -1, 'bytesOf treats an unset value as no limit');

check(ImportLimits::sizeCap('128M') === intdiv(128 * 1024 * 1024, 40), 'the size cap is a fortieth of the memory limit');
check(ImportLimits::sizeCap('256M') === ImportLimits::CEILING, 'the size cap never goes above 5 MB');
check(ImportLimits::sizeCap('32M') === intdiv(32 * 1024 * 1024, 40), 'the size cap of 32M is 0.8 MB');
check(ImportLimits::sizeCap('1M') === ImportLimits::FLOOR, 'the size cap never goes below 256 KB');
check(ImportLimits::sizeCap('-1') === ImportLimits::CEILING, 'no memory limit gives the 5 MB ceiling');

check(
    ImportLimits::effectiveCap('256M', '2M', '8M') === 2 * 1024 * 1024,
    'effectiveCap takes the smaller upload_max_filesize'
);
check(
    ImportLimits::effectiveCap('256M', '8M', '1M') === 1024 * 1024,
    'effectiveCap takes the smaller post_max_size'
);
check(
    ImportLimits::effectiveCap('128M', '64M', '64M') === ImportLimits::sizeCap('128M'),
    'effectiveCap keeps the size cap when PHP allows more'
);
check(
    ImportLimits::effectiveCap('256M', '0', '-1') === ImportLimits::CEILING,
    'effectiveCap ignores 0 and -1 in the request limits'
);
check(
    ImportLimits::effectiveCap('256M', '', '') === ImportLimits::CEILING,
    'effectiveCap ignores unset request limits'
);

echo "{$passes} passed, {$failures} failed\n";

exit($failures === 0 ? 0 : 1);
