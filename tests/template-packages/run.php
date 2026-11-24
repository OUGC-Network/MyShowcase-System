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

use MyShowcase\Fields\FieldsInterface;
use MyShowcase\Fields\FileField;
use MyShowcase\Service\LanguageSelection;
use MyShowcase\Service\RenderManager;
use MyShowcase\Service\ShowcaseService;
use MyShowcase\Service\TemplateSelection;
use MyShowcase\Service\TemplateSelectionRegistry;
use MyShowcase\Service\JsonSync\JsonSchema;

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

check(
    TemplateSelection::resolveCreateTemplatePrefix(
        TemplateSelection::TEMPLATE_PREFIX_USE_PRESET,
        'showcases/gallery.json'
    ) === 'gallery',
    'ACP create presetdefault applies the shipped gallery package'
);

check(
    TemplateSelection::resolveCreateTemplatePrefix('', 'showcases/blog.json') === '',
    'ACP create explicit global keeps global templates even when a preset is selected'
);

check(
    TemplateSelection::resolveCreateTemplatePrefix('downloads', 'showcases/blog.json') === 'downloads',
    'ACP create explicit named package wins over preset'
);

foreach (['blog', 'downloads', 'gallery'] as $package) {
    check(in_array($package, ShowcaseService::templateSets(), true), "shipped folder {$package} is discoverable");
}

$showcaseServiceSource = file_get_contents(\MyShowcase\ROOT . '/src/Service/ShowcaseService.php');
check(
    $showcaseServiceSource !== false
    && !str_contains($showcaseServiceSource, 'my_strtolower(')
    && str_contains($showcaseServiceSource, 'strtolower($prefix)'),
    'template prefix normalization avoids undefined MyBB helpers'
);
check(ShowcaseService::normalizeTemplatePrefix('Blog') === 'blog', 'normalizeTemplatePrefix lowercases letters');
check(ShowcaseService::normalizeTemplatePrefix('blog-2_extra') === 'blog2extra', 'normalizeTemplatePrefix strips non-alphanumeric');
check(ShowcaseService::normalizeTemplatePrefix('../etc/passwd') === 'etcpasswd', 'normalizeTemplatePrefix strips traversal punctuation');
check(ShowcaseService::normalizeTemplatePrefix('..') === '', 'normalizeTemplatePrefix rejects punctuation-only input');
check(
    ShowcaseService::normalizeTemplatePrefix('mixedCase99') === 'mixedcase99',
    'normalizeTemplatePrefix is case-insensitive on ASCII letters'
);
check(
    in_array('blog', ShowcaseService::templateSets(), true)
    && !in_array('Blog', ShowcaseService::templateSets(), true),
    'templateSets keeps lowercase directory names only'
);

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
    TemplateSelection::decodeCookiePreferences('{"not-a-showcase-id":"g","1":"g","2":"x","3":"g"}') === ['1' => 'g', '3' => 'g'],
    'cookie decoder keeps every valid numeric showcase id and mode'
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
        'blog/pageViewEntry.twig',
        'downloads/pageViewEntry.twig',
        'gallery/pageViewEntry.twig',
        'gallery/pageMain.twig',
        'gallery/pageView.twig',
        'blog/pageMain.twig',
        'blog/pageView.twig',
        'downloads/pageMain.twig',
        'downloads/pageView.twig',
    ] as $relative
) {
    check(is_file("{$twigRoot}/{$relative}"), "package template exists: {$relative}");
}

foreach (['blog', 'downloads'] as $package) {
    $packageMain = file_get_contents("{$twigRoot}/{$package}/pageMain.twig");
    check(
        $packageMain !== false
        && str_contains($packageMain, '{% extends \'@ext.myshowcase/pageMain.twig\' %}')
        && str_contains($packageMain, 'package_head_extra')
        && str_contains($packageMain, "{$package}/partialPackageStyles.twig"),
        "{$package} list page loads scoped package CSS through package_head_extra"
    );
}

check(is_file("{$twigRoot}/partialTemplateViewToggle.twig"), 'visitor toggle partial exists');

$globalNeutralityPatterns = [
    "template_effective_prefix == '",
    'myshowcase-gallery-entries',
    'myshowcase-entry-view--',
    'myshowcase-package-entry--',
    'partialPackageStyles',
    'showcase_template_exists',
];

foreach (glob("{$twigRoot}/*.twig") ?: [] as $globalTemplate) {
    $basename = basename($globalTemplate);
    if ($basename === 'partialTemplateViewToggle.twig') {
        continue;
    }

    $contents = file_get_contents($globalTemplate);
    if ($contents === false) {
        check(false, "global template readable: {$basename}");
        continue;
    }

    foreach ($globalNeutralityPatterns as $pattern) {
        check(
            !str_contains($contents, $pattern),
            "global {$basename} stays package-neutral (no {$pattern})"
        );
    }
}

$galleryMain = file_get_contents("{$twigRoot}/gallery/pageMain.twig");
check(
    $galleryMain !== false
    && preg_match(
        '/myshowcase-gallery-entries[^>]*>[\s\S]*?{% for entry in normal_entries %}/',
        $galleryMain
    ) === 1,
    'gallery package grid wraps normal entry cards'
);

check(
    $galleryMain !== false
    && preg_match(
        '/myshowcase-gallery-entries[^>]*>[\s\S]*?{% for entry in stuck_entries %}/',
        $galleryMain
    ) === 1,
    'gallery package grid wraps sticky entry cards'
);

$globalMain = file_get_contents("{$twigRoot}/pageMain.twig");
check(
    $globalMain !== false
    && str_contains($globalMain, 'showcase_template(')
    && str_contains($globalMain, '{% block package_head_extra %}')
    && str_contains($globalMain, '{% block main_entry_thread_lists %}'),
    'global pageMain exposes neutral package override blocks'
);

foreach (['blog', 'downloads', 'gallery'] as $package) {
    $packageMainEntry = file_get_contents("{$twigRoot}/{$package}/pageMainEntry.twig");
    check(
        $packageMainEntry !== false
        && str_contains($packageMainEntry, 'url(get_profile_link(')
        && !str_contains($packageMainEntry, 'mybb.settings.bburl'),
        "{$package} list entry uses Routing absolute profile URLs"
    );
}

$toggle = file_get_contents("{$twigRoot}/partialTemplateViewToggle.twig");
check(
    $toggle !== false
    && str_contains($toggle, 'url_template_view_post')
    && !str_contains($toggle, 'absolute_request_url'),
    'toggle form uses Routing-built post URL'
);

$twigExtension = file_get_contents(\MyShowcase\ROOT . '/src/Twig/ShowcaseTwigExtension.php');
check(
    $twigExtension !== false
    && str_contains($twigExtension, 'showcase_template_exists'),
    'ShowcaseTwigExtension registers optional template existence helper'
);

$adminPhp = file_get_contents(\MyShowcase\ROOT . '/admin.php');
check(
    $adminPhp !== false
    && str_contains($adminPhp, 'TEMPLATE_PREFIX_USE_PRESET'),
    'ACP create form defaults template set to presetdefault when unset'
);

$languagesRoot = LanguageSelection::languagesRootPath();
check(
    is_file($languagesRoot . '/english/myshowcase.lang.php'),
    'language root resolves to Upload/inc/languages'
);
check(
    !is_file(dirname(\MyShowcase\ROOT, 3) . '/languages/english/myshowcase.lang.php'),
    'language root is not Upload/languages'
);

$globalLang = file_get_contents($languagesRoot . '/english/myshowcase.lang.php');
$removedPackageKeys = [
    'myshowcase_package_blog_detail_intro',
    'myshowcase_package_downloads_detail_files',
    'myshowcase_package_downloads_detail_no_files',
    'myshowcase_package_gallery_detail_hero_missing',
];
foreach ($removedPackageKeys as $removedKey) {
    check($globalLang !== false && !str_contains($globalLang, $removedKey), "global language omits package key {$removedKey}");
}

$neutralKeys = [
    'myshowcase_custom_entry_detail_label',
    'myshowcase_custom_attachments_heading',
    'myshowcase_custom_attachments_empty',
    'myshowcase_custom_preview_unavailable',
];
foreach (['blog', 'downloads', 'gallery'] as $package) {
    $entryTwig = file_get_contents("{$twigRoot}/{$package}/pageViewEntry.twig");
    check($entryTwig !== false, "{$package} entry template readable for language audit");
    foreach ($removedPackageKeys as $removedKey) {
        check($entryTwig !== false && !str_contains($entryTwig, $removedKey), "{$package} entry template does not reference {$removedKey}");
    }
}

$languageSelectionSource = file_get_contents(\MyShowcase\ROOT . '/src/Service/LanguageSelection.php');
check(
    $languageSelectionSource !== false
    && !str_contains($languageSelectionSource, 'PRESET_SHOWCASE_LANGUAGE_FILES')
    && !str_contains($languageSelectionSource, 'presetLanguageFileForShowcaseJsonFile'),
    'language selection has no JSON preset mapping'
);
check(
    $adminPhp !== false
    && !str_contains($adminPhp, 'LanguageSelection::LANGUAGE_PREFIX_USE_PRESET'),
    'ACP language selector does not default to a language preset sentinel'
);

$globalDefaults = [
    'myshowcase_custom_entry_detail_label' => 'Entry',
    'myshowcase_custom_attachments_heading' => 'Attachments',
    'myshowcase_custom_attachments_empty' => 'No attachments are available for this entry.',
    'myshowcase_custom_preview_unavailable' => 'No preview image is available.',
];
foreach ($globalDefaults as $key => $value) {
    check(
        $globalLang !== false
        && preg_match("/'{$key}'\\s*=>\\s*'" . preg_quote($value, '/') . "'/", $globalLang) === 1,
        "global language defines {$key}"
    );
}

check(
    LanguageSelection::languageSectionForPrefix('article') === 'myshowcase_showcase_article',
    'language section resolves bundled article file'
);
check(
    LanguageSelection::languageSectionForPrefix('../etc/passwd') === '',
    'invalid language basename yields no loadable section'
);
check(
    LanguageSelection::languageSectionForPrefix('Article') === 'myshowcase_showcase_article',
    'language section accepts normalised valid basename'
);
check(!LanguageSelection::isValidBasename('../etc/passwd'), 'traversal basename is invalid');
check(!LanguageSelection::isValidBasename('bad/name'), 'slash basename is invalid');
check(!LanguageSelection::isValidBasename('Report'), 'mixed-case basenames are invalid and excluded from discovery');
check(
    LanguageSelection::normalizeSubmittedBasename('Article') === 'article',
    'ACP input normalises valid mixed-case basenames to lowercase'
);
check(
    $languageSelectionSource !== false
    && !str_contains($languageSelectionSource, 'my_strtolower(')
    && str_contains($languageSelectionSource, "dirname(\\MyShowcase\\ROOT, 2) . '/languages'"),
    'language discovery uses inc/languages and avoids undefined MyBB helpers'
);
$discoveredSets = LanguageSelection::languageSets();
foreach ($discoveredSets as $setName) {
    check($setName === strtolower($setName) && LanguageSelection::isValidBasename($setName), "discovered language basename {$setName} is lowercase and valid");
}
check(in_array('article', $discoveredSets, true), 'article language file is discoverable dynamically');
check(in_array('resources', $discoveredSets, true), 'resources language file is discoverable dynamically');
check(in_array('previews', $discoveredSets, true), 'previews language file is discoverable dynamically');
check(
    in_array('custom_language_prefix', JsonSchema::SHOWCASE_EXCLUDED, true),
    'showcase JSON files cannot own custom_language_prefix'
);

$resourcesLang = file_get_contents($languagesRoot . '/english/myshowcase_showcase_resources.lang.php');
$articleLang = file_get_contents($languagesRoot . '/english/myshowcase_showcase_article.lang.php');
check($articleLang !== false && is_file($languagesRoot . '/english/myshowcase_showcase_article.lang.php'), 'article custom language file is readable from language root');
check(
    $resourcesLang !== false
    && str_contains($resourcesLang, 'myshowcase_custom_attachments_heading')
    && !str_contains($resourcesLang, 'myshowcase_custom_preview_unavailable'),
    'example resources language file may override only selected neutral keys'
);

$baseController = file_get_contents(\MyShowcase\ROOT . '/src/Controller/BaseController.php');
check(
    $baseController !== false
    && str_contains($baseController, 'LanguageSelection::sectionsForShowcase'),
    'front controller loads reusable and per-showcase language sections through LanguageSelection'
);

$interfaceAttachmentsBuild = new \ReflectionMethod(FieldsInterface::class, 'attachmentsBuild');
check($interfaceAttachmentsBuild->getNumberOfParameters() === 2, 'FieldsInterface::attachmentsBuild keeps the two-parameter contract');
check(
    (new \ReflectionMethod(FileField::class, 'attachmentsBuild'))->getNumberOfParameters() === 2,
    'FileField attachmentsBuild remains a two-argument implementation'
);
check(
    is_subclass_of(FileField::class, FieldsInterface::class),
    'FileField satisfies FieldsInterface without a third attachmentsBuild argument'
);
check(
    method_exists(RenderManager::class, 'prepareAttachmentEntitiesForDisplay'),
    'RenderManager exposes form-only attachment metadata preparation'
);
$renderManagerSource = file_get_contents(\MyShowcase\ROOT . '/src/Service/RenderManager.php');
check(
    $renderManagerSource !== false
    && str_contains($renderManagerSource, 'function prepareAttachmentEntitiesForDisplay')
    && !preg_match('/bool \$renderTemplates/', $renderManagerSource),
    'buildAttachments has no renderTemplates toggle; preparation is a separate method'
);
check(
    (new \ReflectionMethod(RenderManager::class, 'prepareAttachmentEntitiesForDisplay'))->isPublic(),
    'prepareAttachmentEntitiesForDisplay is a public RenderManager entry point'
);
$loadEntryFieldsSource = '';
if ($baseController !== false) {
    if (preg_match(
        '/protected function loadEntryFields\b[\s\S]*?(?=\n    (?:private|protected|public) function )/',
        $baseController,
        $loadEntryFieldsMatch
    )) {
        $loadEntryFieldsSource = $loadEntryFieldsMatch[0];
    }
}
check(
    $loadEntryFieldsSource !== ''
    && !preg_match('/attachmentsBuild\s*\([^)]*,\s*[^)]*,/', $loadEntryFieldsSource),
    'loadEntryFields does not pass a third argument through attachmentsBuild'
);
check(
    preg_match(
        '/if\s*\(\$is_create_update_page\)\s*\{[\s\S]*?prepareAttachmentEntitiesForDisplay\s*\(/s',
        $loadEntryFieldsSource
    ) === 1,
    'create/update branch in loadEntryFields calls prepareAttachmentEntitiesForDisplay'
);
check(
    preg_match(
        '/\}\s*else\s*\{[\s\S]*?\$field->attachmentsBuild\s*\(/s',
        $loadEntryFieldsSource
    ) === 1,
    'entry view else branch in loadEntryFields still calls attachmentsBuild'
);
$editFieldTemplate = file_get_contents(\MyShowcase\ROOT . '/view/templates/pageEntryCreateUpdateField.twig');
check(
    $editFieldTemplate !== false
    && str_contains($editFieldTemplate, "showcase_template('pageEntryCreateUpdateFieldAttachment.twig')"),
    'edit attachment partial keeps TP-D1 package-first nested resolution'
);
$fieldAttachmentsTwig = file_get_contents(\MyShowcase\ROOT . '/view/templates/pageViewEntryFieldAttachments.twig');
check(
    $fieldAttachmentsTwig !== false
    && str_contains($fieldAttachmentsTwig, 'showcase_template('),
    'entry view field attachment Twig retains package-first nested includes'
);

echo PHP_EOL . ($failures === 0 ? "OK {$passes} checks\n" : "FAILED {$failures}, passed {$passes}\n");

exit($failures === 0 ? 0 : 1);