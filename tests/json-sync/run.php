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

// ---- every shipped file: planned by the engine, fields complete, showcases without the per-showcase keys ----------
// The shipped JSON examples: every collection and showcase file is planned from an empty board, its plan has no
// errors and every field has a type, a key, a label, a valid regular expression and, for select fields, options.
$shippedCollections = [];

foreach ($committed as $relative => $_) {
    if (!str_starts_with($relative, 'collections/')) {
        continue;
    }

    $read = (new JsonFiles(\MyShowcase\ROOT . '/imports'))->read($relative);
    $decoded = $read['data'] ?? [];
    $shippedCollections[$relative] = $decoded;

    $plan = JsonPlanner::collection($decoded, $relative, []);
    check($plan['errors'] === [] && $plan['steps'] !== [], "shipped collection is planned from an empty board: {$relative}");

    $keys = array_column($decoded['fields'] ?? [], 'field_key');
    check(count($keys) === count(array_unique($keys)), "shipped collection has unique field keys: {$relative}");

    foreach ($decoded['fields'] ?? [] as $field) {
        $name = ($field['field_key'] ?? '?') . ' in ' . $relative;

        check(isset($field['html_type'], $field['field_type'], $field['label']) && $field['label'] !== '', "field has a type, a key and a label: {$name}");

        if (!empty($field['regular_expression'])) {
            check(@preg_match($field['regular_expression'], '') !== false, "regular expression compiles: {$name}");
        }

        if (($field['html_type'] ?? '') === 'select' || ($field['html_type'] ?? '') === 'radio' || ($field['html_type'] ?? '') === 'checkbox') {
            check(count($field['options'] ?? []) > 0, "select, radio and checkbox fields have options: {$name}");
        }
    }
}

foreach ($committed as $relative => $_) {
    if (!str_starts_with($relative, 'showcases/')) {
        continue;
    }

    $decoded = (new JsonFiles(\MyShowcase\ROOT . '/imports'))->read($relative)['data'] ?? [];

    check(!array_key_exists('name', $decoded) && !array_key_exists('route_identifier', $decoded), "showcase file sets neither name nor route_identifier: {$relative}");

    $collectionFile = 'collections/' . ($decoded['collection'] ?? '') . '.json';

    check(isset($shippedCollections[$collectionFile]), "showcase names a shipped collection file: {$relative}");

    $plan = JsonPlanner::showcase($decoded, $relative, [
        'showcase' => null,
        'collection' => ['row' => null],
        'collection_file' => $shippedCollections[$collectionFile] ?? null,
        'collection_file_path' => $collectionFile,
    ]);

    check($plan['errors'] === [], "shipped showcase is planned without errors: {$relative}");
}

// The downloads example: a file field, a checksum that must be 64 hexadecimal characters, one platform and one licence.
$downloads = $shippedCollections['collections/downloads.json'] ?? [];
$downloadFields = array_column($downloads['fields'] ?? [], null, 'field_key');
check(($downloadFields['archive']['html_type'] ?? '') === 'file' && ($downloadFields['archive']['is_required'] ?? 0) === 1 && ($downloadFields['archive']['maximum_size'] ?? 0) > 0, 'downloads: a required file field with a size limit');
check(($downloadFields['sha256']['is_required'] ?? 0) === 1 && preg_match($downloadFields['sha256']['regular_expression'] ?? '', str_repeat('a', 64)) === 1 && preg_match($downloadFields['sha256']['regular_expression'] ?? '', str_repeat('a', 63)) !== 1, 'downloads: the checksum is required and must be 64 hexadecimal characters');
check(preg_match($downloadFields['version']['regular_expression'] ?? '', '1.4.2') === 1 && preg_match($downloadFields['version']['regular_expression'] ?? '', 'v1') !== 1, 'downloads: the version pattern accepts 1.4.2 and refuses v1');
check(array_column($downloadFields['platform']['options'] ?? [], 'value_id') === [1, 2, 3, 4, 5] && array_column($downloadFields['license']['options'] ?? [], 'value_id') === [1, 2, 3, 4, 5, 6], 'downloads: platform and license options have stable ids');
check(isset($committed['showcases/downloads.json']) && (($shippedCollections['collections/downloads.json'] ?? null) !== null), 'downloads: collection and showcase are committed');

// The blog example: the five original fields and the four added ones, every one visible to all groups.
$blog = $shippedCollections['collections/blog.json'] ?? [];
$blogFields = array_column($blog['fields'] ?? [], null, 'field_key');
check(array_keys($blogFields) === ['category', 'summary', 'tags', 'cover_image', 'reading_time', 'series', 'series_part', 'meta_description', 'source_url'], 'blog: the five original fields and the four added ones');
$blogOpen = true;

foreach ($blogFields as $key => $field) {
    $blogOpen = $blogOpen && ($field['allowed_groups_fill'] ?? '') === 'all' && ($field['allowed_groups_view'] ?? '') === 'all';
}

check($blogOpen, 'blog: every field is allowed for all groups to fill and view');
check(preg_match($blogFields['cover_image']['regular_expression'] ?? '', 'javascript:alert(1)') !== 1 && preg_match($blogFields['cover_image']['regular_expression'] ?? '', 'https://example.com/a.png') === 1, 'blog: the cover image accepts an http(s) address and refuses a javascript address');
check(preg_match($blogFields['reading_time']['regular_expression'] ?? '', '0') !== 1 && preg_match($blogFields['reading_time']['regular_expression'] ?? '', '1000') !== 1 && preg_match($blogFields['reading_time']['regular_expression'] ?? '', '999') === 1, 'blog: reading time is 1 to 999');

// The bounds of a field (decision D124): the types that have them, their formats, a range with both, the order.
$oneField = static function (array $props) use ($collection): array {
    $document = $collection();
    $document['fields'] = [array_merge(['field_key' => 'bound_test', 'label' => 'Bound', 'html_type' => 'number', 'field_type' => 'int'], $props)];

    return $document;
};
check(JsonValidator::validate($oneField(['minimum_value' => '1', 'maximum_value' => '10'])) === [], 'a number with whole number bounds is valid');
check(JsonValidator::validate($oneField(['minimum_value' => '-5'])) === [], 'a number with a negative minimum is valid');
check(paths(JsonValidator::validate($oneField(['minimum_value' => '1.5']))) === ['$.fields[0].minimum_value'], 'a number bound that is not a whole number is refused');
check(paths(JsonValidator::validate($oneField(['minimum_value' => '5', 'maximum_value' => '3']))) === ['$.fields[0].minimum_value'], 'a minimum above the maximum is refused');
check(paths(JsonValidator::validate($oneField(['html_type' => 'range', 'minimum_value' => '0']))) === ['$.fields[0]'], 'a range without a maximum is refused');
check(paths(JsonValidator::validate($oneField(['html_type' => 'text', 'field_type' => 'varchar', 'minimum_value' => 'x']))) === ['$.fields[0].minimum_value'], 'a bound on a text field is refused');
check(JsonValidator::validate($oneField(['html_type' => 'date', 'field_type' => 'date', 'minimum_value' => '2026-01-01', 'maximum_value' => '2026-12-31'])) === [], 'a date with valid bounds is valid');
check(paths(JsonValidator::validate($oneField(['html_type' => 'date', 'field_type' => 'date', 'maximum_value' => '2026-02-30']))) === ['$.fields[0].maximum_value'], 'a date bound that is not a calendar date is refused');
check(paths(JsonValidator::validate($oneField(['html_type' => 'week', 'field_type' => 'varchar', 'maximum_value' => '2026-W54']))) === ['$.fields[0].maximum_value'], 'a week bound that the year does not have is refused');
check(JsonValidator::validate($oneField(['html_type' => 'time', 'field_type' => 'time', 'minimum_value' => '09:00', 'maximum_value' => '18:00:00'])) === [], 'a time with HH:MM and HH:MM:SS bounds is valid');

// SQLite type-change loss check (decision D116): the integer bounds and their comparison are pure
check(\MyShowcase\Service\SchemaManager::compareIntegers('-5', '3') < 0 && \MyShowcase\Service\SchemaManager::compareIntegers('10', '9') > 0, 'integers compare by sign and magnitude');
check(\MyShowcase\Service\SchemaManager::compareIntegers('000', '0') === 0 && \MyShowcase\Service\SchemaManager::compareIntegers('-0', '0') === 0, 'zero compares equal with leading zeros and a minus sign');
check(\MyShowcase\Service\SchemaManager::compareIntegers('9223372036854775808', '9223372036854775807') > 0, 'integers beyond the PHP int range compare as strings');
check(\MyShowcase\Service\SchemaManager::compareIntegers('-9223372036854775809', '-9223372036854775808') < 0, 'negative integers beyond the PHP int range compare as strings');
check(\MyShowcase\Service\SchemaManager::sqliteIntegerBounds('tinyint', false) === ['-128', '127'] && \MyShowcase\Service\SchemaManager::sqliteIntegerBounds('tinyint', true) === ['0', '255'], 'tinyint bounds, signed and unsigned');
check(\MyShowcase\Service\SchemaManager::sqliteIntegerBounds('int', false) === ['-2147483648', '2147483647'], 'int bounds are signed 32 bit by default');
check(\MyShowcase\Service\SchemaManager::sqliteIntegerBounds('bigint', true) === ['0', '18446744073709551615'], 'unsigned bigint bounds');

// Decision D140: a stored text on an integer target is refused only when it is not a number, a real fraction or out of range
$loss = static fn(string $value, string $type = 'int', bool $unsigned = false): ?string => \MyShowcase\Service\SchemaManager::integerValueLoss($value, ...\MyShowcase\Service\SchemaManager::sqliteIntegerBounds($type, $unsigned));
check($loss('') === 'not_number', 'a blank stored text is not a number for an integer (MySQL and PostgreSQL refuse it too)');
check($loss('abc') === 'not_number' && $loss('1 2') === 'not_number' && $loss('.') === 'not_number', 'text that is not a number is refused');
check($loss('1.0') === null && $loss('1e3') === null && $loss('-0.0') === null && $loss('+7') === null && $loss(' 12 ') === null, 'an integral text (1.0, 1e3, -0.0, +7) is accepted for an integer');
check($loss('1.5') === 'fraction' && $loss('1e-1') === 'fraction' && $loss('12e-1') === 'fraction', 'a real fraction is refused with the fraction reason');
check($loss('1.00000000000000000000000001') === 'fraction' && $loss('0.99999999999999999999') === 'fraction', 'a fraction beyond float precision is refused exactly');
check($loss('2147483647') === null && $loss('-2147483648') === null, 'the int limits are accepted');
check($loss('2147483648') === 'out_of_range' && $loss('-2147483649') === 'out_of_range', 'values outside the int range are refused');
check($loss('1e30', 'bigint') === 'out_of_range' && $loss('1e999') === 'out_of_range', 'a large exponent is out of range');
check($loss('9223372036854775807', 'bigint') === null && $loss('9223372036854775808', 'bigint') === 'out_of_range', 'the bigint limit is exact');
check($loss('12345678901234567890', 'bigint', true) === null && $loss('18446744073709551616', 'bigint', true) === 'out_of_range', 'the unsigned bigint limit is exact');
check($loss('1e-999') === 'fraction', 'a tiny non-zero exponent is a fraction');
check(\MyShowcase\Service\SchemaManager::isNumericText('1.5') && \MyShowcase\Service\SchemaManager::isNumericText('-2e3') && !\MyShowcase\Service\SchemaManager::isNumericText('') && !\MyShowcase\Service\SchemaManager::isNumericText('x'), 'decimal text: numbers yes, blank and words no');

// Decision D129: a blank date, time or datetime value is NULL; the other types keep their value (blank included)
check(\MyShowcase\Plugin\Functions\isNullDateTypeValue('', 'date') && \MyShowcase\Plugin\Functions\isNullDateTypeValue('  ', 'time') && \MyShowcase\Plugin\Functions\isNullDateTypeValue(null, 'datetime'), 'a blank date, time or datetime value is NULL');
check(!\MyShowcase\Plugin\Functions\isNullDateTypeValue('2026-01-01', 'date') && !\MyShowcase\Plugin\Functions\isNullDateTypeValue('0', 'datetime') && !\MyShowcase\Plugin\Functions\isNullDateTypeValue('', 'varchar') && !\MyShowcase\Plugin\Functions\isNullDateTypeValue('', 'int'), 'a value of a date type, and a blank of another type, is not NULL');

// Decision D148: group restrictions are board-local. A file names "all" or no group key; a restriction is counted, not written
check(JsonValidator::validate($oneField(['allowed_groups_fill' => 'all'])) === [], 'a field group of "all" is valid in a file');
$groupErrors = JsonValidator::validate($oneField(['allowed_groups_fill' => '2']));
check(paths($groupErrors) === ['$.fields[0].allowed_groups_fill'] && str_contains((string)json_encode($groupErrors), 'board-local'), 'a group list in a file is refused, and the message says the restriction is board-local');
check(\MyShowcase\Service\JsonSync\JsonExporter::isRestricted('2,3') && !\MyShowcase\Service\JsonSync\JsonExporter::isRestricted('') && !\MyShowcase\Service\JsonSync\JsonExporter::isRestricted('-1') && !\MyShowcase\Service\JsonSync\JsonExporter::isRestricted('-1,2'), 'a group list restricts unless it is empty or lists -1 (all groups)');

// Decision D103: a four-byte character is refused only where the connection cannot store it (MySQL on a utf8 connection)
check(\MyShowcase\Plugin\Functions\containsFourByteCharacter("a \u{1F600} b") && !\MyShowcase\Plugin\Functions\containsFourByteCharacter("caf\u{E9} \u{20AC}"), 'a four-byte character is found, and accented or euro text is not');
check(\MyShowcase\Plugin\Functions\fourByteCharacterRefused("\u{1F600}", 'mysql', 'utf8') && !\MyShowcase\Plugin\Functions\fourByteCharacterRefused("\u{1F600}", 'mysql', 'utf8mb4'), 'MySQL refuses a four-byte character on a utf8 connection and accepts it on utf8mb4');
check(!\MyShowcase\Plugin\Functions\fourByteCharacterRefused("\u{1F600}", 'pgsql', 'utf8') && !\MyShowcase\Plugin\Functions\fourByteCharacterRefused("\u{1F600}", 'sqlite', 'utf8') && !\MyShowcase\Plugin\Functions\fourByteCharacterRefused("abc", 'mysql', 'utf8'), 'PostgreSQL and SQLite store it, and plain text is never refused');

// Reserved HTML types (poll and rating): not selectable, and a file that names them is refused with the reason
check(!isset(\MyShowcase\Plugin\Functions\fieldHtmlTypes()['poll']) && !isset(\MyShowcase\Plugin\Functions\fieldHtmlTypes()['rating']) && isset(\MyShowcase\Plugin\Functions\fieldHtmlTypes()['text']), 'poll and rating are left out of the selectable HTML types');
$reservedErrors = JsonValidator::validate(['type' => 'collection', 'version' => '1.0.0', 'name' => 'Reserved', 'fields' => [['field_key' => 'r', 'label' => 'R', 'html_type' => 'rating', 'field_type' => 'int']]]);
check(paths($reservedErrors) === ['$.fields[0].html_type'] && str_contains((string)json_encode($reservedErrors), 'reserved for a later feature'), 'a file that names the rating type is refused, with the reason');

// Decision D152: a field key is refused as typed when it is not in the database's form, when it is too long, or when reserved
$fk = static fn(string $key): ?string => \MyShowcase\Service\FieldService::fieldKeyProblem($key);
check($fk('abc') === null && $fk('a1_b2') === null && $fk(str_repeat('a', 30)) === null, 'a key of letters, digits and single underscores is accepted, up to 30 characters');
check($fk(str_repeat('a', 31)) === 'length' && $fk('') === 'missing', 'a key of 31 characters is too long, and an empty key is missing');
check($fk('Abc') === 'format' && $fk('a-b') === 'format' && $fk('a b') === 'format' && $fk('1abc') === 'format' && $fk("caf\u{E9}x") === 'format', 'uppercase, hyphen, space, a leading digit and a non-ASCII letter are refused, not converted');
check($fk('a__b') === 'format' && $fk('a_') === 'format' && $fk('_a') === 'format', 'doubled and leading or trailing underscores are refused');
check($fk('subject') === 'reserved' && $fk('keys') === 'reserved', 'an entries column name is reserved');
check(\MyShowcase\Service\FieldService::reservedFieldKeys() === array_values(array_unique(array_merge(\MyShowcase\Service\FieldService::reservedKeys(), \MyShowcase\Service\FieldService::SQL_RESERVED_KEYS))), 'the reserved names are the table columns plus the SQL list');

// Decision D153: the SQL list is one sorted list without repeats, and holds a word of each engine's own keyword source
$sqlList = \MyShowcase\Service\FieldService::SQL_RESERVED_KEYS;
$sortedList = $sqlList;
sort($sortedList, SORT_STRING);
check($sqlList === $sortedList && count($sqlList) === count(array_unique($sqlList)), 'the SQL list is sorted and has no repeats');
$keywordSources = json_decode((string)file_get_contents(dirname(__DIR__) . '/fields/sql_keyword_sources.json'), true);
check(in_array('accessible', $keywordSources['mysql_8_4_reserved'], true) && in_array('analyse', $keywordSources['postgresql_R_T'], true) && in_array('autoincrement', $keywordSources['sqlite_documented'], true), 'the keyword sources hold a word of each engine for the check below');
check($fk('accessible') === 'reserved' && $fk('analyse') === 'reserved' && $fk('autoincrement') === 'reserved', 'a word of the MySQL, PostgreSQL and SQLite sources is refused as a key');

// Decision D146: the error sentence falls back to the global language, then to the English text
require_once \MyShowcase\ROOT . '/Plugin/ErrorSentence.php';
$sentenceCtx = new class { public string $myshowcase_error_unexpected = 'context sentence'; };
$sentenceGlobal = new class { public string $myshowcase_error_unexpected = 'global sentence'; };
$sentenceEmpty = new class { public string $myshowcase_error_unexpected = ''; };
$sentenceNone = new stdClass();
check(\MyShowcase\Plugin\ErrorSentence\errorSentence($sentenceCtx, $sentenceGlobal, 'myshowcase_error_unexpected', 'english') === 'context sentence', 'an error sentence comes from the plugin context first');
check(\MyShowcase\Plugin\ErrorSentence\errorSentence(null, $sentenceGlobal, 'myshowcase_error_unexpected', 'english') === 'global sentence', 'without a context, the global language gives the sentence');
check(\MyShowcase\Plugin\ErrorSentence\errorSentence($sentenceEmpty, null, 'myshowcase_error_unexpected', 'english') === 'english', 'a blank key falls back to the English text');
check(\MyShowcase\Plugin\ErrorSentence\errorSentence(null, 'not an object', 'myshowcase_error_unexpected', 'english') === 'english' && \MyShowcase\Plugin\ErrorSentence\errorSentence($sentenceNone, null, 'missing_key', 'english') === 'english', 'no object or key gives the English text, and no error');

echo "{$passes} passed, {$failures} failed\n";

exit($failures === 0 ? 0 : 1);
