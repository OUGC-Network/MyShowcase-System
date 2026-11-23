<?php
/**
 * Static unit checks for template packages (TP). No database or MyBB runtime.
 * Owner run: php tests/template-packages/run.php
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

use MyShowcase\Service\ShowcaseService;
use MyShowcase\Service\TemplateSelection;
use MyShowcase\Service\TemplateSelectionRegistry;

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

check(
    TemplateSelection::presetPackageForShowcaseJsonFile('showcases/blog.json') === 'blog',
    'preset blog.json maps to blog package'
);
check(
    TemplateSelection::presetPackageForShowcaseJsonFile('showcases/gallery.json') === 'gallery',
    'preset gallery.json maps to gallery package'
);
check(
    TemplateSelection::presetPackageForShowcaseJsonFile('showcases/downloads.json') === 'downloads',
    'preset downloads.json maps to downloads package'
);
check(
    TemplateSelection::presetPackageForShowcaseJsonFile('showcases/unknown.json') === '',
    'unknown preset does not guess a package'
);

foreach (['blog', 'downloads', 'gallery'] as $package) {
    check(in_array($package, ShowcaseService::templateSets(), true), "shipped folder {$package} is discoverable");
}

check(
    TemplateSelectionRegistry::resolveTwigTemplateName('pageMainEntry') === '@ext.myshowcase/pageMainEntry.twig',
    'registry without active selection falls back to global'
);

$twigRoot = \MyShowcase\ROOT . '/view/templates';
foreach (['blog/pageMainEntry.twig', 'downloads/pageMainEntry.twig', 'gallery/pageMain.twig', 'gallery/pageMainEntry.twig'] as $relative) {
    check(is_file("{$twigRoot}/{$relative}"), "package template exists: {$relative}");
}

check(is_file("{$twigRoot}/partialTemplateViewToggle.twig"), 'visitor toggle partial exists');

$globalMain = file_get_contents("{$twigRoot}/pageMain.twig");
check(
    $globalMain !== false && str_contains($globalMain, 'showcase_template('),
    'global pageMain uses showcase_template for nested includes'
);

echo PHP_EOL . ($failures === 0 ? "OK {$passes} checks\n" : "FAILED {$failures}, passed {$passes}\n");

exit($failures === 0 ? 0 : 1);