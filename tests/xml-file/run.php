<?php
/**
 * Unit tests of Util/XmlFile, the wrapper over PluginLibrary's XML export and import.
 * No database, no MyBB: php tests/xml-file/run.php
 *
 * PluginLibrary is not part of this repository. It is looked for in the PLUGINLIBRARY environment variable, then in
 * ../_deps/MyBB-PluginLibrary/inc/plugins/pluginlibrary.php next to this working tree.
 */

declare(strict_types=1);

error_reporting(E_ALL);

ini_set('memory_limit', '1G');

define('IN_MYBB', 1);
define('TIME_NOW', 1794700000);

$library_file = getenv('PLUGINLIBRARY') ?: dirname(__DIR__, 3) . '/_deps/MyBB-PluginLibrary/inc/plugins/pluginlibrary.php';

if (!is_file($library_file)) {
    echo "SKIP: PluginLibrary not found at {$library_file} (set PLUGINLIBRARY)\n";

    exit(0);
}

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'MyShowcase\\')) {
        $file = dirname(__DIR__, 2) . '/Upload/inc/plugins/myshowcase/src/' . str_replace('\\', '/', substr($class, 11)) . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    }
});

require_once $library_file;

use MyShowcase\Util\XmlFile;
use MyShowcase\Util\XmlFileException;

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

function reason(callable $call): ?string
{
    try {
        $call();
    } catch (XmlFileException $exception) {
        return $exception->reason;
    }

    return null;
}

global $PL;

$xml = new XmlFile($PL);

// --- Round trips -------------------------------------------------------------------------------------------------

$tricky = [
    'crlf' => "line one\r\nline two\r\n",
    'lone_cr' => "a\rb",
    'cdata_end' => 'before ]]> after',
    'cdata_end_and_cr' => "<<<<<<<<<<<<\r\n]]>\r<<<<",
    'quotes' => "he said \"hi\" and 'bye' & <b>bold</b>",
    'emoji_and_cjk' => "caf\u{e9} \u{65e5}\u{672c}\u{8a9e} \u{1F600}",
    'tab_and_newline' => "col1\tcol2\nrow2",
    'doctype_text' => '<!DOCTYPE html><html><body>a page</body></html>',
    'zero' => '0',
    'empty' => '',
    'int' => 42,
    'float' => 1.5,
    'true' => true,
    'false' => false,
    'list' => [1, 2, 3],
    'empty_array' => [],
];

$data = ['header' => ['format' => 'test'], 'entries' => [0 => $tricky, 1 => ['subject' => 'second']]];

$removed = -1;

$written = $xml->write($data, removed_characters: $removed);

check($removed === 0, 'nothing removed from clean data');

$read = $xml->read($written);

check($read === $data, 'round trip of tricky strings and scalars is identical');

check($read['entries'][0]['crlf'] === "line one\r\nline two\r\n", 'CRLF round-trips byte-exact');

check($read['entries'][0]['lone_cr'] === "a\rb", 'lone CR round-trips');

check($read['entries'][0]['cdata_end'] === 'before ]]> after', ']]> round-trips byte-exact');

check($read['entries'][0]['cdata_end_and_cr'] === "<<<<<<<<<<<<\r\n]]>\r<<<<", 'CDATA with CR and ]]> round-trips');

check(str_contains($written, '&#13;'), 'CR is written as a character reference');

check(!str_contains($written, "\r") && !str_contains($written, "\x01"), 'no raw CR or placeholder is left in the file');

check($read['entries'][0]['doctype_text'] === '<!DOCTYPE html><html><body>a page</body></html>', 'text that looks like a DOCTYPE inside the data is data, not rejected');

// --- Nulls ---------------------------------------------------------------------------------------------------------

$with_nulls = ['a' => null, 'b' => '', 'c' => ['x' => null, 'y' => 0, 'z' => '0'], 'd' => [null, 'k']];

$read_nulls = $xml->read($xml->write($with_nulls));

check($read_nulls === $with_nulls, 'nulls (top level, nested, in lists) round-trip and stay different from empty string and 0');

check(array_key_exists('a', $read_nulls) && $read_nulls['a'] === null, 'a null comes back as null, not as a missing key');

// --- Control characters ---------------------------------------------------------------------------------------------

$dirty = "ok\x01\x07text\x0B\x0C\x1F\x00end\ttab\nnl\r";

$cleaned_xml = $xml->write(['v' => $dirty, 'nested' => ['w' => "x\x02y"]], removed_characters: $removed);

check($removed === 7, "control characters are counted (got {$removed}, wanted 7)");

$cleaned = $xml->read($cleaned_xml);

check($cleaned['v'] === "oktextend\ttab\nnl\r", 'control characters are removed, tab, newline and CR stay');

check($cleaned['nested']['w'] === 'xy', 'control characters are removed from nested values');

$invalid_utf8 = $xml->read($xml->write(['v' => "bad\xff\xfebytes"]));

check(is_string($invalid_utf8['v']) && str_starts_with($invalid_utf8['v'], 'bad') && str_ends_with($invalid_utf8['v'], 'bytes'), 'invalid UTF-8 does not break the file');

// --- DOCTYPE and entities --------------------------------------------------------------------------------------------

$doctype = '<?xml version="1.0"?><!DOCTYPE d [<!ENTITY x SYSTEM "file:///etc/passwd">]><array><element><key>k</key><value>&x;</value></element></array>';

check(reason(fn() => $xml->read($doctype)) === XmlFileException::REASON_DOCTYPE, 'a DOCTYPE with an external entity is rejected');

check(reason(fn() => $xml->read('<?xml version="1.0"?><!-- c --><!doctype d><array></array>')) === XmlFileException::REASON_DOCTYPE, 'a DOCTYPE after a comment, in lower case, is rejected');

check(reason(fn() => $xml->read("\xEF\xBB\xBF" . $doctype)) === XmlFileException::REASON_DOCTYPE, 'a DOCTYPE after a BOM is rejected');

// --- Malformed and foreign XML ---------------------------------------------------------------------------------------

try {
    $xml->read("<?xml version=\"1.0\"?>\n<array>\n<element>");

    check(false, 'truncated XML is rejected');
} catch (XmlFileException $exception) {
    check($exception->reason === XmlFileException::REASON_SYNTAX && $exception->xml_line > 0, 'truncated XML is rejected with a line number');
}

check(reason(fn() => $xml->read('<root><a>1</a></root>')) === XmlFileException::REASON_NOT_EXPORT, 'well-formed XML that is not an export is rejected');

check(reason(fn() => $xml->read('not xml at all')) === XmlFileException::REASON_SYNTAX, 'text that is not XML is rejected');

// --- PluginLibrary's notices stay inside the wrapper -------------------------------------------------------------------

$seen = [];

set_error_handler(static function (int $level, string $message, string $file) use (&$seen): bool {
    $seen[] = [$level, basename($file), $message];

    return true;
});

$xml->read($xml->write(['a' => ['b' => 1, 'c' => 'x']]));

check($seen === [], 'PluginLibrary\'s per-element PHP warnings are not reported (' . count($seen) . ' seen)');

trigger_error('outside the wrapper', E_USER_WARNING);

check(count($seen) === 1 && $seen[0][2] === 'outside the wrapper', 'the previous error handler is back and still receives other errors');

restore_error_handler();

// --- A very large file ------------------------------------------------------------------------------------------------

$entries = [];

for ($i = 0; $i < 10000; ++$i) {
    $entries[$i] = ['subject' => "Entry {$i}", 'message' => str_repeat('x', 200), 'fields' => ['category' => 1, 'summary' => str_repeat('y', 100)]];
}

$large = $xml->write(['entries' => $entries]);

check(strlen($large) > 11 * 1024 * 1024, 'the large test file is over 11 MB (' . round(strlen($large) / 1048576, 1) . ' MB)');

try {
    $xml->read($large);

    check(false, 'an 11 MB file is refused with a message');
} catch (XmlFileException $exception) {
    check($exception->reason === XmlFileException::REASON_TOO_LARGE, 'an 11 MB file fails as too large, not as a syntax error');

    check(stripos($exception->getMessage(), 'too large') !== false && stripos($exception->getMessage(), 'unknown') === false, 'the message says the file is too large, not "Unknown"');
}

unset($large, $entries);

echo "{$passes} passed, {$failures} failed\n";

exit($failures === 0 ? 0 : 1);
