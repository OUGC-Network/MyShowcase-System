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

$jsonIntKeys = json_encode(['1' => TemplateSelection::MODE_GLOBAL, '42' => TemplateSelection::MODE_CONFIGURED], JSON_THROW_ON_ERROR);
$prefs = TemplateSelection::decodeCookiePreferences($jsonIntKeys);
check(
    ($prefs['1'] ?? '') === TemplateSelection::MODE_GLOBAL && ($prefs['42'] ?? '') === TemplateSelection::MODE_CONFIGURED,
    'decodeCookiePreferences accepts JSON numeric showcase ids'
);

check(TemplateSelection::decodeCookiePreferences(str_repeat('x', 3000)) === [], 'oversized cookie payload is rejected on read');

$many = [];
for ($i = 1; $i <= 50; ++$i) {
    $many[(string)$i] = TemplateSelection::MODE_CONFIGURED;
}
check(count(TemplateSelection::decodeCookiePreferences(json_encode($many, JSON_THROW_ON_ERROR))) <= 40, 'too many cookie entries are capped on read');

check(
    TemplateSelection::decodeCookiePreferences('{"../etc/passwd":"g","1":"x","2":"g"}') === ['2' => 'g'],
    'invalid cookie keys and modes are discarded safely'
);

check(
    TemplateSelectionRegistry::resolveTwigTemplateName('pageMainEntry') === '@ext.myshowcase/pageMainEntry.twig',
    'registry without active selection falls back to global'
);

$twigRoot = \MyShowcase\ROOT . '/view/templates';
foreach (
    [
        'blog/pageMainEntry.twig',
        'blog/partialPackageStyles.twig',
        'downloads/pageMainEntry.twig',
        'downloads/partialPackageStyles.twig',
        'gallery/pageMainEntry.twig',
        'gallery/partialPackageStyles.twig',
    ] as $relative
) {
    check(is_file("{$twigRoot}/{$relative}"), "package template exists: {$relative}");
}

check(!is_file("{$twigRoot}/gallery/pageMain.twig"), 'gallery does not duplicate the full pageMain template');

check(is_file("{$twigRoot}/partialTemplateViewToggle.twig"), 'visitor toggle partial exists');

$globalMain = file_get_contents("{$twigRoot}/pageMain.twig");
check(
    $globalMain !== false
    && str_contains($globalMain, 'showcase_template(')
    && str_contains($globalMain, 'myshowcase-gallery-entries'),
    'global pageMain uses package includes and scopes gallery grid to entry lists'
);

$toggle = file_get_contents("{$twigRoot}/partialTemplateViewToggle.twig");
check(
    $toggle !== false
    && str_contains($toggle, 'url_template_view_post')
    && !str_contains($toggle, 'absolute_request_url'),
    'toggle form uses Routing-built post URL'
);

echo PHP_EOL . ($failures === 0 ? "OK {$passes} checks\n" : "FAILED {$failures}, passed {$passes}\n");

exit($failures === 0 ? 0 : 1);