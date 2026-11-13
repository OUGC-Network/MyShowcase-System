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

// --- File structure ----------------------------------------------------------------------------------------------------

use MyShowcase\Service\ImportFileCheck;
use MyShowcase\Service\ImportReport;
use MyShowcase\Util\ImportUpload;
use MyShowcase\Util\ImportUploadException;

function importHeader(array $overrides = []): array
{
    return array_replace([
        'format' => 'myshowcase-entries',
        'format_version' => 1,
        'showcase' => ['route_identifier' => 'blog', 'name' => 'Blog'],
        'collection' => ['name' => 'Blog'],
        'entry_count' => 1,
    ], $overrides);
}

function importTarget(array $overrides = []): array
{
    return array_replace([
        'route_identifier' => 'blog',
        'collection_name' => 'Blog',
        'known_keys' => ['category', 'summary', 'cover_image', 'picture'],
        'fillable_keys' => ['category', 'summary', 'cover_image'],
        'file_keys' => ['picture'],
        'maximum_entries' => 5000,
        'subject_enabled' => true,
        'message_enabled' => true,
    ], $overrides);
}

function problemCodes(array $problems): array
{
    return array_map(static fn(array $problem): string => $problem['code'], $problems);
}

$good = ['header' => importHeader(), 'entries' => [['subject' => 'A', 'message' => 'B', 'fields' => ['category' => 1, 'summary' => '']]]];

check(ImportFileCheck::problems($good, importTarget()) === [], 'a valid file has no structural problems');
check(problemCodes(ImportFileCheck::problems(['header' => importHeader(['format' => 'x'])] + $good, importTarget())) === ['format'], 'a wrong format is refused');
check(problemCodes(ImportFileCheck::problems(['header' => importHeader(['format_version' => 2])] + $good, importTarget())) === ['version'], 'a higher format version is refused');
check(problemCodes(ImportFileCheck::problems(['header' => importHeader(['format_version' => 0])] + $good, importTarget())) === ['version'], 'a format version of 0 is refused');

$foreign = ImportFileCheck::problems(['header' => importHeader(['showcase' => ['route_identifier' => 'gallery']])] + $good, importTarget());
check(problemCodes($foreign) === ['showcase'] && $foreign[0]['args'] === ['gallery', 'blog'], 'a file of another showcase is refused with both names');

$other_collection = ['header' => importHeader(['collection' => ['name' => 'Gallery']])] + $good;
check(ImportFileCheck::problems($other_collection, importTarget()) === [], 'a file of another collection is not refused (decision T3)');
$collection_warnings = ImportFileCheck::warnings($other_collection, importTarget());
check(problemCodes($collection_warnings) === ['collection'] && $collection_warnings[0]['args'] === ['Gallery', 'Blog'], 'a collection name mismatch is a warning with both names');
check(ImportFileCheck::warnings($good, importTarget()) === [], 'a matching collection name gives no warning');
$both = ['header' => importHeader(['showcase' => ['route_identifier' => 'gallery'], 'collection' => ['name' => 'Gallery']])] + $good;
check(problemCodes(ImportFileCheck::problems($both, importTarget())) === ['showcase'], 'a route identifier mismatch still refuses the file, whatever the collection name');
check(problemCodes(ImportFileCheck::problems(['header' => importHeader()] + ['entries' => ['a' => []]], importTarget())) === ['entries_not_list'], 'entries that are not a list are refused');
check(problemCodes(ImportFileCheck::problems(['header' => importHeader(['entry_count' => 0])] + ['entries' => []], importTarget())) === ['empty'], 'a file without entries is refused as empty');
check(problemCodes(ImportFileCheck::problems(['header' => importHeader(['entry_count' => 3])] + $good, importTarget())) === ['count_mismatch'], 'a wrong entry_count is refused');
$many = ImportFileCheck::problems(['header' => importHeader(['entry_count' => 3])] + ['entries' => [[], [], []]], importTarget(['maximum_entries' => 2]));
check(in_array('too_many', problemCodes($many), true), 'more entries than the maximum is refused');

check(problemCodes(ImportFileCheck::problems(['header' => importHeader()] + ['entries' => ['not an entry']], importTarget())) === ['entry_shape'], 'an entry that is not an array is refused');
check(problemCodes(ImportFileCheck::problems(['header' => importHeader()] + ['entries' => [['fields' => 'x']]], importTarget())) === ['entry_shape'], 'fields that are not an array are refused');

$unknown = ImportFileCheck::problems(['header' => importHeader(['entry_count' => 2])] + ['entries' => [['fields' => ['legacy' => 'x']], ['fields' => ['legacy' => 'y']]]], importTarget());
check(problemCodes($unknown) === ['unknown_field'] && $unknown[0]['args'][0] === 'legacy' && $unknown[0]['args'][1] === '1, 2' && $unknown[0]['args'][2] === 2, 'an unknown key is refused with its positions');

check(problemCodes(ImportFileCheck::problems(['header' => importHeader()] + ['entries' => [['fields' => ['picture' => 'a.png']]]], importTarget())) === ['file_field'], 'a file field key is refused');
check(problemCodes(ImportFileCheck::problems(['header' => importHeader()] + ['entries' => [['fields' => ['summary' => 'x']]]], importTarget(['fillable_keys' => ['category']]))) === ['not_fillable'], 'a key the user cannot fill is refused');
check(problemCodes(ImportFileCheck::problems(['header' => importHeader()] + ['entries' => [['subject' => 'S']]], importTarget(['subject_enabled' => false]))) === ['not_enabled'], 'a subject the showcase does not use is refused');

$many_positions = [];
for ($i = 0; $i < 9; $i++) {
    $many_positions[] = ['fields' => ['legacy' => 'x']];
}
$listed = ImportFileCheck::problems(['header' => importHeader(['entry_count' => 9])] + ['entries' => $many_positions], importTarget());
check($listed[0]['args'][1] === '1, 2, 3, 4, 5' && $listed[0]['args'][2] === 9, 'a key lists its first five positions and counts all');

// --- Report -------------------------------------------------------------------------------------------------------------

$report = ImportReport::refused('entries.xml', 'Blog', [['code' => 'format', 'args' => []]]);
check($report->failed() && $report->imported === 0 && $report->entries_in_file === 0, 'a refused file is a failed report with nothing imported');
$array = (new ImportReport(ImportReport::COMPLETED, 'a.xml', 'Blog', 2, 2, [], [], [], 2))->toArray();
check($array['status'] === 'completed' && $array['imported'] === 2 && $array['passed_entries'] === 2 && $array['entry_failures'] === [], 'a completed report keeps its counts');

// --- Write failure log ----------------------------------------------------------------------------------------------------

$described = MyShowcase\Service\EntriesImporter::describeFailure(new RuntimeException("Query: INSERT INTO t VALUES ('secret one', \"other secret\")", 7));
check(str_contains($described, 'RuntimeException') && preg_match('/\(run\.php:\d+\)$/', $described) === 1, 'the failure log names the class and the file and line');
check(!str_contains($described, 'secret') && str_contains($described, 'Query: INSERT INTO t VALUES (?, ?)'), 'the failure log has no quoted value of the query');
check(!str_contains($described, __DIR__), 'the failure log has no server path beyond the file name');

// --- Upload -------------------------------------------------------------------------------------------------------------

$directory = sys_get_temp_dir() . '/msc-import-tests-' . bin2hex(random_bytes(4));
mkdir($directory);

function tempFile(string $directory, string $name, string $content): string
{
    $path = $directory . '/' . bin2hex(random_bytes(4)) . '-' . $name;
    file_put_contents($path, $content);

    return $path;
}

function uploadOf(string $path, string $name = 'entries.xml', int $error = UPLOAD_ERR_OK): array
{
    return ['name' => $name, 'tmp_name' => $path, 'error' => $error, 'size' => is_file($path) ? filesize($path) : 0];
}

function uploadReason(callable $call): ?string
{
    try {
        $call();
    } catch (ImportUploadException $exception) {
        return $exception->reason;
    }

    return null;
}

$trusted = static fn(string $path): bool => true;
$reader = new ImportUpload(1024, '128M', $trusted);

check(uploadReason(fn() => $reader->read(null)) === ImportUpload::REASON_NO_FILE, 'no upload is refused');
check(uploadReason(fn() => $reader->read(uploadOf('', 'a.xml', UPLOAD_ERR_NO_FILE))) === ImportUpload::REASON_NO_FILE, 'an upload without a file is refused');
check(uploadReason(fn() => $reader->read(uploadOf('/nonexistent', 'a.xml', UPLOAD_ERR_INI_SIZE))) === ImportUpload::REASON_TOO_LARGE, 'an upload over the PHP limit is refused as too large');
check(uploadReason(fn() => $reader->read(uploadOf('/nonexistent', 'a.xml', UPLOAD_ERR_PARTIAL))) === ImportUpload::REASON_UPLOAD, 'a partial upload is refused');

$xml_path = tempFile($directory, 'a.xml', '<?xml version="1.0"?><array></array>');
check(uploadReason(fn() => $reader->read(uploadOf($xml_path, 'a.txt'))) === ImportUpload::REASON_EXTENSION, 'a file that is not .xml is refused');
check(uploadReason(fn() => (new ImportUpload(1024, '128M', static fn(string $path): bool => false))->read(uploadOf($xml_path))) === ImportUpload::REASON_UPLOAD, 'a file that was not uploaded by PHP is refused');
check($reader->read(uploadOf($xml_path)) === '<?xml version="1.0"?><array></array>', 'a valid XML upload is read');

$bom_path = tempFile($directory, 'bom.xml', "\xEF\xBB\xBF<?xml version=\"1.0\"?><array></array>");
check(str_starts_with($reader->read(uploadOf($bom_path)), "\xEF\xBB\xBF"), 'an XML upload with a byte order mark is read');

check(uploadReason(fn() => $reader->read(uploadOf(tempFile($directory, 'empty.xml', '')))) === ImportUpload::REASON_EMPTY, 'an empty file is refused');
check(uploadReason(fn() => $reader->read(uploadOf(tempFile($directory, 'big.xml', '<' . str_repeat('a', 2000)))) ) === ImportUpload::REASON_TOO_LARGE, 'a file above the cap is refused before it is read');
check(uploadReason(fn() => $reader->read(uploadOf(tempFile($directory, 'text.xml', 'hello world')))) === ImportUpload::REASON_NOT_XML, 'a text file that is not XML is refused');
check(uploadReason(fn() => $reader->read(uploadOf(tempFile($directory, 'binary.xml', "\x00\x01\x02\x03\xFF\xFE")))) === ImportUpload::REASON_MIME, 'a binary file is refused by its MIME type');

$discard_path = tempFile($directory, 'discard.xml', '<array/>');
$reader->discard(uploadOf($discard_path));
check(!is_file($discard_path), 'discard removes the temporary file');

foreach (glob($directory . '/*') ?: [] as $leftover) {
    @unlink($leftover);
}
@rmdir($directory);

echo "{$passes} passed, {$failures} failed\n";

exit($failures === 0 ? 0 : 1);
