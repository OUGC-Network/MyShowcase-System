<?php
/**
 * Unit tests of the pure parts of the JSON sync: validation, hashing, status, planning and export.
 * No database, no MyBB: php tests/json-sync/run.php
 */

declare(strict_types=1);

error_reporting(E_ALL);

define('IN_MYBB', 1);
define('MyShowcase\SETTINGS', ['slugLength' => 10]);
define('MyShowcase\DEBUG', false);
define('MyShowcase\ROOT', dirname(__DIR__, 2) . '/Upload/inc/plugins/myshowcase');

if (!function_exists('my_strtolower')) {
    function my_strtolower(string $string): string
    {
        return mb_strtolower($string);
    }
}

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

use MyShowcase\Service\JsonSync\JsonExporter;
use MyShowcase\Service\JsonSync\JsonFiles;
use MyShowcase\Service\JsonSync\JsonHash;
use MyShowcase\Service\JsonSync\JsonPlanner;
use MyShowcase\Service\JsonSync\JsonSchema;
use MyShowcase\Service\JsonSync\JsonStatus;
use MyShowcase\Service\JsonSync\JsonValidator;

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

function paths(array $errors): array
{
    return array_column($errors, 'path');
}

$field = static fn(array $extra = []) => $extra + [
        'field_key' => 'category', 'label' => 'Category', 'html_type' => 'select', 'field_type' => 'int',
        'maximum_length' => 10,
    ];

$collection = static fn(?array $fields = null, array $extra = []) => $extra + [
        'type' => 'collection', 'version' => '1.0.0', 'name' => 'Blog', 'fields' => $fields ?? [$field()],
    ];

// ---- validation ----------------------------------------------------------------------------------------------
check(JsonValidator::validate($collection()) === [], 'a minimal collection is valid');
check(JsonValidator::validate(['type' => 'showcase', 'version' => '1.0.0', 'collection' => 'blog', 'main_per_page' => 5]) === [], 'a minimal showcase is valid');
check(isset(JsonSchema::showcaseColumns()['main_prune_age']) && JsonValidator::validate(['type' => 'showcase', 'version' => '1.0.0', 'collection' => 'blog', 'main_prune_age' => 30]) === [], 'main_prune_age is a JSON showcase key');
check(paths(JsonValidator::validate(['version' => '1.0.0'])) === ['$.type'], 'type is required');
check(in_array('$.version', paths(JsonValidator::validate(['type' => 'collection', 'version' => '1.0', 'name' => 'x', 'fields' => []]))), 'version must be x.y.z');

$all = JsonValidator::validate($collection([
    $field(['field_key' => 'Bad Key', 'html_type' => 'nope', 'field_type' => 'nope', 'unknown' => 1, 'json_hash' => 'x', 'collection_id' => 3, 'allowed_groups_fill' => '1,2', 'label' => 12, 'maximum_length' => -1]),
    $field(['field_key' => 'user_id']),
    $field(['field_key' => 'dup']),
    $field(['field_key' => 'dup', 'options' => [['value_id' => 1, 'value' => 'a']]]),
    $field(['field_key' => 'noopt', 'html_type' => 'text', 'options' => []]),
    $field(['field_key' => str_repeat('a', 31)]),
    $field(['field_key' => 'rename', 'previous_keys' => ['dup']]),
]));
$p = paths($all);
foreach (
    [
        '$.fields[0].field_key', '$.fields[0].html_type', '$.fields[0].field_type', '$.fields[0].unknown', '$.fields[0].json_hash',
        '$.fields[0].collection_id', '$.fields[0].allowed_groups_fill', '$.fields[0].label', '$.fields[0].maximum_length',
        '$.fields[1].field_key', '$.fields[3].field_key', '$.fields[5].field_key', '$.fields[6].previous_keys[0]',
    ] as $expected
) {
    check(in_array($expected, $p, true), "reported with its path: {$expected}");
}
check(count($all) >= 13, 'every error is returned, not only the first (' . count($all) . ')');
check(in_array('$.fields[3].field_key', $p, true) && !in_array('$.fields[2].field_key', $p, true), 'a duplicate key is reported on the later field only');

check(paths(JsonValidator::validate($collection([$field(['options' => [['value_id' => 1], ['value_id' => 1, 'value' => 'x', 'bogus' => 1]]])]))) === [
    '$.fields[0].options[0].value', '$.fields[0].options[1].bogus', '$.fields[0].options[1].value_id',
], 'option problems carry their path');

foreach (['name', 'route_identifier', 'custom_template_prefix', 'showcase_url', 'collection_id', 'showcase_id', 'custom_theme_id', 'attachments_uploads_path', 'attachments_watermark_file', 'json_file', 'password'] as $forbidden) {
    check(
        in_array('$.' . $forbidden, paths(JsonValidator::validate(['type' => 'showcase', 'version' => '1.0.0', 'collection' => 'blog', $forbidden => 'x'])), true),
        "showcase file can not have {$forbidden}"
    );
}
check(paths(JsonValidator::validate(['type' => 'showcase', 'version' => '1.0.0', 'main_per_page' => 'ten'])) === ['$.collection', '$.main_per_page'], 'showcase: collection required, values typed');
check(JsonValidator::validate($collection(), 'showcase') !== [], 'expected type is enforced');

// ---- hashing -------------------------------------------------------------------------------------------------
$a = ['b' => 1, 'a' => ['z' => 1, 'y' => [3, 1, 2]]];
$b = ['a' => ['y' => [3, 1, 2], 'z' => 1], 'b' => 1];
check(JsonHash::canonical($a) === JsonHash::canonical($b) && JsonHash::hash($a) === JsonHash::hash($b), 'key order does not change the hash');
check(JsonHash::hash(['a' => [1, 2]]) !== JsonHash::hash(['a' => [2, 1]]), 'list order does');
check(JsonHash::canonical(['u' => 'é/x']) === '{"u":"é/x"}', 'slashes and Unicode are not escaped');
check(JsonHash::hash([]) === hash('sha256', '[]') && strlen(JsonHash::hash($a)) === 64, 'SHA-256 of the canonical text');

// ---- status --------------------------------------------------------------------------------------------------
$row = ['json_version' => '1.0.0', 'json_hash' => 'h'];
$file = static fn(string $v, string $h) => ['exists' => true, 'valid' => true, 'version' => $v, 'hash' => $h];
check(JsonStatus::of($row, $file('1.0.0', 'h')) === 'in_sync', 'status in_sync');
check(JsonStatus::of($row, $file('1.1.0', 'h')) === 'update_available', 'status update_available');
check(JsonStatus::of($row, $file('1.0.0', 'x')) === 'unversioned_change', 'status unversioned_change');
check(JsonStatus::of($row, $file('0.9.0', 'x')) === 'downgrade', 'status downgrade');
check(JsonStatus::of($row, ['exists' => false, 'valid' => false]) === 'file_missing', 'status file_missing');
check(JsonStatus::of($row, ['exists' => true, 'valid' => false]) === 'invalid', 'status invalid');
check(JsonStatus::of(['json_version' => '1.9.0', 'json_hash' => ''], $file('1.10.0', 'h')) === 'update_available', 'versions compare as numbers');
check(JsonStatus::ofField(['json_file' => '', 'json_version' => '', 'json_hash' => ''], $file('1.0.0', 'h'), 'h') === 'custom', 'field without file is custom');
check(JsonStatus::ofField(['json_file' => 'collections/blog.json'] + $row, $file('1.0.0', 'whole-file-hash'), 'h') === 'in_sync', 'field in_sync (compared with the hash of its own entry, not the whole file)');
check(JsonStatus::ofField(['json_file' => 'collections/blog.json'] + $row, $file('1.0.0', 'h'), 'other') === 'unversioned_change', 'field changed without a new version');
check(JsonStatus::ofField(['json_file' => 'collections/blog.json'] + $row, $file('1.0.0', 'h'), null) === 'file_missing', 'field gone from the file');
check(JsonStatus::managedColumns('collection', []) === ['name'], 'collection manages name');
check(JsonStatus::managedColumns('field', ['field_key' => 'a', 'html_type' => 'text', 'label' => 'A', 'options' => []]) === ['field_key', 'html_type', 'field_label'], 'field manages the columns of its entry');
check(in_array('main_per_page', JsonStatus::managedColumns('showcase', ['main_per_page' => 5, 'collection' => 'blog'])) && !in_array('name', JsonStatus::managedColumns('showcase', ['collection' => 'blog'])), 'showcase manages what the file sets');

// ---- planning ------------------------------------------------------------------------------------------------
$types = static fn(array $plan) => array_column($plan['steps'], 'type');
$stateRow = ['collection_id' => 1, 'name' => 'Blog', 'json_version' => '1.0.0', 'json_hash' => ''];
$fieldRow = static fn(string $key, array $extra = []) => $extra + [
        'field_id' => 1, 'field_key' => $key, 'field_label' => 'Category', 'html_type' => 'select', 'field_type' => 'int',
        'maximum_length' => '10', 'json_file' => 'collections/blog.json', 'json_version' => '1.0.0', 'json_hash' => 'x',
    ];

$new = JsonPlanner::collection($collection(), 'collections/blog.json', []);
check($types($new) === ['collection_create', 'field_add', 'write_version', 'cache_refresh'], 'new collection: create, add, version, caches');

$decoded = $collection([$field(['field_key' => 'topics', 'previous_keys' => ['tags']]), $field(['field_key' => 'extra']), $field(['field_key' => 'wide', 'maximum_length' => 20, 'options' => [['value_id' => 1, 'value' => 'One'], ['value_id' => 2, 'value' => 'Two']]])], ['version' => '1.2.0', 'name' => 'Blog 2']);
$state = [
    'row' => $stateRow,
    'fields' => ['tags' => $fieldRow('tags', ['field_id' => 1]), 'gone' => $fieldRow('gone', ['field_id' => 2]), 'mine' => $fieldRow('mine', ['field_id' => 3, 'json_file' => '']), 'wide' => $fieldRow('wide', ['field_id' => 4])],
    'options' => [4 => [1 => ['value' => 'Uno', 'display_order' => '0', 'display_style' => ''], 3 => ['value' => 'Three']]],
    'showcases' => [7 => ['name' => 'Blog', 'data_table' => true], 8 => ['name' => 'No table', 'data_table' => false]],
    'php_steps' => ['1.0.0' => '/a', '1.1.0' => '/b', '1.2.0' => '/c', '1.3.0' => '/d'],
    'language_writable' => false,
];
$plan = JsonPlanner::collection($decoded, 'collections/blog.json', $state);
check($types($plan) === [
    'collection_update', 'field_rename', 'field_add', 'field_update', 'field_unlink', 'option_sync', 'php_update', 'php_update',
    'data_table_rebuild', 'write_version', 'cache_refresh',
], 'collection plan order: ' . implode(',', $types($plan)));
check(array_column(array_filter($plan['steps'], fn($s) => $s['type'] === 'php_update'), 'data') === [['version' => '1.1.0', 'path' => '/b'], ['version' => '1.2.0', 'path' => '/c']], 'PHP steps: above the row version, up to the file version, ascending');
check(count(array_filter($plan['steps'], fn($s) => $s['type'] === 'data_table_rebuild')) === 1, 'rebuild only for showcases with a data table');
check($plan['errors'] === [] && in_array('The language directory is not writable: field labels will not be written.', $plan['warnings'], true), 'warning: language directory');
$unlink = array_values(array_filter($plan['steps'], fn($s) => $s['type'] === 'field_unlink'));
check(count($unlink) === 1 && $unlink[0]['data']['field_key'] === 'gone' && $unlink[0]['warnings'] !== [], 'removed field becomes custom, with a warning; custom fields are left alone');
$opt = array_values(array_filter($plan['steps'], fn($s) => $s['type'] === 'option_sync'))[0];
check($opt['warnings'] !== [] && str_contains($opt['warnings'][0], '3'), 'options missing from the file are kept and reported');
check(!array_filter($plan['steps'], fn($s) => str_contains($s['type'], 'delete') || str_contains($s['type'], 'drop')), 'a plan never deletes');
$loss = JsonPlanner::collection($decoded, 'collections/blog.json', $state + ['losses' => fn($id, $key, $cols) => $key === 'wide' ? '3 rows too long' : null]);
check(array_filter($loss['steps'], fn($s) => $s['warnings'] !== [] && $s['type'] === 'data_table_rebuild') !== [], 'warning: conversion loss');

$synced = $collection([$field()]);
$entry = JsonPlanner::fieldColumns($synced['fields'][0]);
$same = JsonPlanner::collection($synced, 'collections/blog.json', [
    'row' => ['collection_id' => 1, 'name' => 'Blog', 'json_version' => '1.0.0', 'json_hash' => JsonHash::hash($synced)],
    'fields' => ['category' => $fieldRow('category', $entry)],
]);
check($same['steps'] === [], 'nothing to do when the rows match the file');

$taken = JsonPlanner::collection($synced, 'collections/blog.json', ['row' => $stateRow, 'fields' => ['category' => $fieldRow('category', ['json_file' => 'collections/other.json'])]]);
check($taken['errors'] !== [], 'a field of another file is an error');

$showcase = ['type' => 'showcase', 'version' => '1.0.0', 'collection' => 'blog', 'main_per_page' => 5];
$sp = JsonPlanner::showcase($showcase, 'showcases/blog.json', ['showcase' => null, 'collection' => ['row' => null], 'collection_file' => $synced, 'collection_file_path' => 'collections/blog.json']);
check($types($sp) === ['collection_create', 'field_add', 'write_version', 'cache_refresh', 'showcase_create', 'data_table_create', 'showcase_write_version', 'cache_refresh'], 'new showcase: nested collection plan first: ' . implode(',', $types($sp)));
$sp2 = JsonPlanner::showcase($showcase, 'showcases/blog.json', ['showcase' => null, 'collection' => ['row' => $stateRow], 'collection_file' => $synced, 'collection_file_path' => 'collections/blog.json']);
check($types($sp2) === ['showcase_create', 'data_table_create', 'showcase_write_version', 'cache_refresh'], 'new showcase with a linked collection reuses it');
check(JsonPlanner::showcase($showcase, 'showcases/blog.json', ['collection_file' => null])['errors'] !== [], 'showcase without a valid collection file is an error');

// ---- export --------------------------------------------------------------------------------------------------
$rows = [
    ['field_id' => 2, 'field_key' => 'b', 'field_label' => 'B', 'html_type' => 'text', 'field_type' => 'varchar', 'maximum_length' => '20', 'is_enabled' => '1', 'display_order' => '2', 'allowed_groups_fill' => '-1', 'allowed_groups_view' => '1,2', 'placeholder' => '', 'min' => 0],
    ['field_id' => 1, 'field_key' => 'a', 'field_label' => 'A', 'html_type' => 'select', 'field_type' => 'int', 'maximum_length' => '10', 'is_enabled' => '0', 'display_order' => '1'],
];
$export = JsonExporter::collection(['name' => 'Blog', 'json_version' => ''], $rows, [1 => [['value_id' => '2', 'value' => 'Two', 'display_order' => '2', 'display_style' => ''], ['value_id' => '1', 'value' => 'One', 'display_order' => '0', 'display_style' => '']]]);
check($export['version'] === '1.0.0' && array_column($export['fields'], 'field_key') === ['a', 'b'], 'export: unlinked row is 1.0.0; fields in display order');
check(JsonValidator::validate($export, 'collection') === [], 'exported collection validates');
check(!isset($export['fields'][0]['is_enabled']) && $export['fields'][1]['is_enabled'] === 1 && $export['fields'][1]['allowed_groups_fill'] === 'all' && !isset($export['fields'][1]['allowed_groups_view']), 'export: defaults left out, groups only as "all"');
check($export['fields'][0]['options'] === [['value_id' => 1, 'value' => 'One'], ['value_id' => 2, 'value' => 'Two', 'display_order' => 2]], 'export: options sorted');
check(!str_contains(json_encode($export), 'collection_id') && !str_contains(json_encode($export), 'json_'), 'export: no ids or json_ columns');
$se = JsonExporter::showcase(['json_version' => '1.4.0', 'name' => 'Mine', 'route_identifier' => 'mine', 'custom_template_prefix' => 'm', 'showcase_url' => 'x', 'password' => 'secret', 'collection_id' => 3, 'main_per_page' => 5, 'main_prune_age' => 90, 'description' => 'd', 'entries_enable_subject' => 1, 'entries_enable_message' => 1, 'comments_enable' => 1, 'custom_theme_id' => '4', 'attachments_uploads_path' => 'p'], 'My Blog');
check($se['version'] === '1.4.0' && $se['collection'] === 'my-blog' && $se['main_per_page'] === 5 && $se['main_prune_age'] === 90 && $se['entries_enable_subject'] === 1, 'export: showcase');
check(array_diff_key($se, array_flip(['type', 'version', 'collection', 'main_per_page', 'main_prune_age', 'description', 'entries_enable_subject', 'entries_enable_message', 'comments_enable'])) === [], 'export: per-showcase and board-specific values left out');
check(JsonValidator::validate($se, 'showcase') === [], 'exported showcase validates');
check(JsonHash::hash(json_decode(JsonExporter::encode($export), true)) === JsonHash::hash($export), 'encoded export decodes to the same content');

// ---- files ---------------------------------------------------------------------------------------------------
check(JsonFiles::typeOf('collections/blog.json') === 'collection' && JsonFiles::typeOf('showcases/a-b_c1.json') === 'showcase', 'file names accepted');
foreach (['collections/Blog.json', 'collections/../showcases/x.json', '../x.json', 'collections/a.json.php', 'collections/a b.json', 'other/a.json', '/etc/passwd', 'collections/.json'] as $bad) {
    check(JsonFiles::typeOf($bad) === null, "file name refused: {$bad}");
}
check(JsonFiles::decode('{"a":1')['error'] !== null && JsonFiles::decode('[1]')['error'] !== null && JsonFiles::decode(str_repeat(' ', JsonFiles::MAX_BYTES + 1))['error'] !== null && JsonFiles::decode('{"a":1}')['data'] === ['a' => 1], 'decode: broken, not an object, too large, fine');

// ---- the committed files ---------------------------------------------------------------------------------------
$committed = [];

foreach (['collection', 'showcase'] as $kind) {
    $files = new JsonFiles(\MyShowcase\ROOT . '/imports');

    foreach ($files->list($kind) as $relative) {
        $read = $files->read($relative);

        check($read['error'] === null && $read['data'] !== null, "committed file decodes: {$relative}");

        if ($read['data'] !== null) {
            $errors = JsonValidator::validate($read['data'], $kind);

            check($errors === [], "committed file validates: {$relative} " . json_encode($errors));

            check(preg_match('/^\d+\.\d+\.\d+$/', (string)($read['data']['version'] ?? '')) === 1, "committed file has an x.y.z version: {$relative}");
        }

        $committed[$relative] = true;
    }
}

check(isset($committed['collections/blog.json'], $committed['showcases/blog.json']), 'the blog examples are committed');

// The gallery collection: title (subject), image (file), caption, category with six options.
$gallery = (new JsonFiles(\MyShowcase\ROOT . '/imports'))->read('collections/gallery.json')['data'] ?? [];
$galleryFields = array_column($gallery['fields'] ?? [], null, 'field_key');
check(isset($committed['collections/gallery.json']) && array_keys($galleryFields) === ['title', 'image', 'caption', 'category'], 'the gallery collection is committed with title, image, caption and category');
check(($galleryFields['title']['enable_subject'] ?? 0) === 1 && ($galleryFields['title']['is_required'] ?? 0) === 1 && ($galleryFields['image']['html_type'] ?? '') === 'file' && ($galleryFields['image']['is_required'] ?? 0) === 1, 'gallery: the title is the required subject and the image a required file field');
check(array_column($galleryFields['category']['options'] ?? [], 'value_id') === [1, 2, 3, 4, 5, 6] && !isset($galleryFields['tags']), 'gallery: six category options with stable value ids, no tags field');

// The gallery showcase: uses the gallery collection, images in the list and the entry, comments on, no empty fields.
$galleryShowcase = (new JsonFiles(\MyShowcase\ROOT . '/imports'))->read('showcases/gallery.json')['data'] ?? [];
check(isset($committed['showcases/gallery.json']) && ($galleryShowcase['collection'] ?? '') === 'gallery', 'the gallery showcase is committed and uses the gallery collection');
check(($galleryShowcase['attachments_allow_in_entries'] ?? 0) === 1 && ($galleryShowcase['comments_enable'] ?? 0) === 1 && ($galleryShowcase['entries_display_empty_fields'] ?? 1) === 0 && ($galleryShowcase['main_per_page'] ?? 0) === 24, 'gallery showcase: attachments and comments on, empty fields hidden, 24 per page');
check(!array_key_exists('custom_template_prefix', $galleryShowcase), 'gallery showcase: no template prefix (excluded from files)');

foreach (['', 'JSON/', 'JSON/collections/', 'JSON/showcases/', 'PHP/', 'PHP/collections/', 'PHP/showcases/'] as $folder) {
    check(is_file(\MyShowcase\ROOT . '/imports/' . $folder . 'index.html'), "index.html in imports/{$folder}");
}

check(is_file(\MyShowcase\ROOT . '/imports/.htaccess') && str_contains((string)file_get_contents(\MyShowcase\ROOT . '/imports/.htaccess'), 'denied'), 'imports/.htaccess denies web access');

echo "{$passes} passed, {$failures} failed\n";

exit($failures === 0 ? 0 : 1);
