<?php
/**
 * Tests of Service/ExportsStorage: the exports folder setting, the dedicated-folder rule, the folder and file modes, and
 * the uninstall cleanup. No database, no MyBB: php tests/exports-storage/run.php
 *
 * The forum folder is a temporary folder created by the test; nothing outside it is written.
 */

declare(strict_types=1);

error_reporting(E_ALL);

define('IN_MYBB', 1);
define('TIME_NOW', 1794700000);

$base = sys_get_temp_dir() . '/msc-exports-storage-' . bin2hex(random_bytes(6));

$forum = $base . '/forum';

mkdir($forum . '/uploads', 0777, true);

define('MYBB_ROOT', $forum . '/');

require dirname(__DIR__, 2) . '/Upload/inc/plugins/myshowcase/src/Service/ExportsStorage.php';

use MyShowcase\Service\ExportsStorage;

$passed = 0;
$failed = 0;

function check(string $label, bool $condition): void
{
    global $passed, $failed;

    if ($condition) {
        ++$passed;

        return;
    }

    ++$failed;

    echo "FAIL: {$label}\n";
}

function rrmdir(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }

    if (!is_dir($path)) {
        return;
    }

    foreach (scandir($path) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            rrmdir($path . '/' . $name);
        }
    }

    @rmdir($path);
}

$export_name = '7-gallery-20261112-093015-' . str_repeat('a', 32) . '.xml';

// normalizeDirectory(): accepted values
check('empty is the default', ExportsStorage::normalizeDirectory('') === 'uploads/myshowcase/exports');
check('whitespace only is the default', ExportsStorage::normalizeDirectory(" \t ") === 'uploads/myshowcase/exports');
check('plain relative folder', ExportsStorage::normalizeDirectory('uploads/other/exports') === 'uploads/other/exports');
check('surrounding whitespace is dropped', ExportsStorage::normalizeDirectory('  uploads/other/exports  ') === 'uploads/other/exports');
check('trailing and duplicate slashes are dropped', ExportsStorage::normalizeDirectory('uploads//a///b/') === 'uploads/a/b');
check('single dot segments are dropped', ExportsStorage::normalizeDirectory('./uploads/./x') === 'uploads/x');

// normalizeDirectory(): refused values
$refused = [
    'absolute path' => '/etc',
    'parent segment' => '../outside',
    'parent inside' => 'uploads/../outside',
    'backslash' => 'uploads\\x',
    'drive letter' => 'C:/x',
    'stream wrapper' => 'uploads/x://y',
    'NUL byte' => "uploads/x\0y",
    'space' => 'uploads/a b',
    'semicolon' => 'uploads/x;y',
    'command substitution' => 'uploads/x$(y)',
    'the forum folder itself' => '.',
    'a slash only' => '/',
];

foreach ($refused as $label => $value) {
    check("refused: {$label}", ExportsStorage::normalizeDirectory($value) === null);
}

// A link inside the forum folder that points outside it.
$outside = $base . '/outside';

mkdir($outside, 0777, true);

symlink($outside, $forum . '/uploads/link');

check('refused: symlink escaping the forum folder', ExportsStorage::normalizeDirectory('uploads/link') === null);
check('refused: folder below a symlink escaping the forum folder', ExportsStorage::normalizeDirectory('uploads/link/deeper') === null);

// The dedicated-folder rule.
$shared = $forum . '/uploads/shared';

mkdir($shared, 0750, true);

file_put_contents($shared . '/notes.txt', 'mine');

check('refused: foreign file in the folder', ExportsStorage::normalizeDirectory('uploads/shared') === null);

rrmdir($shared);
mkdir($shared . '/sub', 0750, true);

check('refused: subdirectory in the folder', ExportsStorage::normalizeDirectory('uploads/shared') === null);

rrmdir($shared);
mkdir($shared, 0750, true);
file_put_contents($shared . '/.htaccess', "Deny from all\n");

check('refused: foreign .htaccess', ExportsStorage::normalizeDirectory('uploads/shared') === null);

rrmdir($shared);
mkdir($shared, 0750, true);
file_put_contents($shared . '/.htaccess', (new ReflectionClassConstant(ExportsStorage::class, 'DENY_FILE'))->getValue());
file_put_contents($shared . '/index.html', '<html></html>');
file_put_contents($shared . '/' . $export_name, '<x/>');

check('accepted: dedicated folder with the exact deny file, index and exports', ExportsStorage::normalizeDirectory('uploads/shared') === 'uploads/shared');

rrmdir($shared);

check('accepted: a folder that does not exist yet', ExportsStorage::normalizeDirectory('uploads/new/folder') === 'uploads/new/folder');

// ensureDirectory(): modes, files, no overwrite
$storage = new ExportsStorage($forum . '/uploads/myshowcase/exports');

check('ensureDirectory creates the folder', $storage->ensureDirectory());
check('folder mode is 0750', substr(sprintf('%o', fileperms($storage->directory())), -4) === '0750');
check('.htaccess is the deny file', file_get_contents($storage->directory() . '/.htaccess') === (new ReflectionClassConstant(ExportsStorage::class, 'DENY_FILE'))->getValue());
check('index.html is written', is_file($storage->directory() . '/index.html'));

check('write stores an export', $storage->write($export_name, '<x/>'));
check('export mode is 0640', substr(sprintf('%o', fileperms($storage->directory() . '/' . $export_name)), -4) === '0640');

file_put_contents($storage->directory() . '/index.html', 'kept');

check('ensureDirectory keeps an existing index page', $storage->ensureDirectory() && file_get_contents($storage->directory() . '/index.html') === 'kept');

file_put_contents($storage->directory() . '/.htaccess', "Options +Indexes\n");

check('ensureDirectory refuses a foreign .htaccess', !$storage->ensureDirectory());
check('ensureDirectory does not overwrite a foreign .htaccess', file_get_contents($storage->directory() . '/.htaccess') === "Options +Indexes\n");
check('write refuses to store into a folder it cannot protect', !$storage->write('8-gallery-20261112-093015-' . str_repeat('b', 32) . '.xml', '<x/>'));

rrmdir($storage->directory());

// removeAll(): dedicated folder
$own = new ExportsStorage($forum . '/uploads/myshowcase/exports');

$own->ensureDirectory();
$own->write($export_name, '<x/>');
$own->removeAll();

check('removeAll removes the export file', !is_file($own->directory() . '/' . $export_name));
check('removeAll removes the folder when it is then empty', !is_dir($own->directory()));
check('removeAll keeps the parent folder', is_dir($forum . '/uploads/myshowcase'));

// removeAll(): a folder shared with other files
$shared_storage = new ExportsStorage($shared);

mkdir($shared, 0750, true);
file_put_contents($shared . '/notes.txt', 'mine');
mkdir($shared . '/sub', 0750, true);
file_put_contents($shared . '/sub/keep.txt', 'mine');
file_put_contents($shared . '/' . $export_name, '<x/>');
file_put_contents($shared . '/.htaccess', (new ReflectionClassConstant(ExportsStorage::class, 'DENY_FILE'))->getValue());
file_put_contents($shared . '/index.html', 'mine');

$shared_storage->removeAll();

check('shared folder: export file removed', !is_file($shared . '/' . $export_name));
check('shared folder: foreign file kept', file_get_contents($shared . '/notes.txt') === 'mine');
check('shared folder: subdirectory kept with its file', file_get_contents($shared . '/sub/keep.txt') === 'mine');
check('shared folder: index.html kept because other files remain', file_get_contents($shared . '/index.html') === 'mine');
check('shared folder: the folder is kept', is_dir($shared));

rrmdir($shared);

// removeAll(): a link named like an export is never followed or removed
$linked = new ExportsStorage($forum . '/uploads/linked');

mkdir($linked->directory(), 0750, true);
file_put_contents($outside . '/victim.xml', 'victim');
symlink($outside . '/victim.xml', $linked->directory() . '/' . $export_name);
file_put_contents($outside . '/.htaccess', (new ReflectionClassConstant(ExportsStorage::class, 'DENY_FILE'))->getValue());
symlink($outside . '/.htaccess', $linked->directory() . '/.htaccess');

$linked->removeAll();

check('removeAll does not follow a link to an export file', is_file($outside . '/victim.xml') && file_get_contents($outside . '/victim.xml') === 'victim');
check('removeAll does not remove a linked deny file', is_file($outside . '/.htaccess'));
check('a folder holding a link is not removed', is_dir($linked->directory()));

rrmdir($linked->directory());

rrmdir($base);

echo "PASSED: {$passed}\n";
echo "FAILED: {$failed}\n";

exit($failed === 0 ? 0 : 1);
