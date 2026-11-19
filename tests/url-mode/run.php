<?php
/**
 * Unit test of the Automatic rule of the URL option (decisions D224, D229, D233): which links a showcase generates.
 * It is the one place where the rule is written; the truth table below is MyBB core's rule (inc/init.php, seourls yes, or
 * auto with isset($_SERVER['SEO_SUPPORT']) and SEO_SUPPORT == 1, compared loosely as core does). No database, no MyBB:
 *   php tests/url-mode/run.php
 */

declare(strict_types=1);

error_reporting(E_ALL);

define('IN_MYBB', 1);
define('MyShowcase\SETTINGS', []);
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

use MyShowcase\Routing\UrlMode;

$failures = 0;
$checks = 0;
$check = static function (bool $ok, string $message) use (&$failures, &$checks): void {
    $checks++;
    if (!$ok) {
        $failures++;
        echo "FAIL {$message}\n";
    }
};

// the three modes, as stored (url_mode column) and as the ACP form offers them
$check(UrlMode::PLAIN === 0 && UrlMode::AUTOMATIC === 1 && UrlMode::FRIENDLY === 2, 'the modes are 0 (No), 1 (Automatic), 2 (Yes)');
$check(UrlMode::DEFAULT === 1, 'the default mode is Automatic (1)');
$check(UrlMode::isValid(0) && UrlMode::isValid(1) && UrlMode::isValid(2), '0, 1 and 2 are valid modes');
$check(!UrlMode::isValid(3) && !UrlMode::isValid(-1) && !UrlMode::isValid(7), 'any other number is not a valid mode');

// the SEO_SUPPORT values a request can carry: null stands for "not set" (isset is false)
$seoValues = [null, 1, '1', true, 0, '0', '', 2, '2', false];
foreach (['no', 'auto', 'yes'] as $seourls) {
    foreach ($seoValues as $seo) {
        $label = $seo === null ? 'null' : var_export($seo, true);

        $check(UrlMode::usesFriendlyLinks(UrlMode::PLAIN, $seourls, $seo) === false,
            "mode 0 is plain for seourls {$seourls} and SEO_SUPPORT {$label}");
        $check(UrlMode::usesFriendlyLinks(UrlMode::FRIENDLY, $seourls, $seo) === true,
            "mode 2 is friendly for seourls {$seourls} and SEO_SUPPORT {$label}");

        // Automatic follows core: yes is friendly; no is plain; auto is friendly only with SEO_SUPPORT == 1 (loose)
        $coreAuto = $seourls === 'yes' || ($seourls === 'auto' && $seo !== null && $seo == 1);
        $check(UrlMode::usesFriendlyLinks(UrlMode::AUTOMATIC, $seourls, $seo) === $coreAuto,
            "Automatic for seourls {$seourls} and SEO_SUPPORT {$label} is " . ($coreAuto ? 'friendly' : 'plain'));
    }
}

$check(UrlMode::usesFriendlyLinks(UrlMode::AUTOMATIC, 'auto', 1) === true, 'Automatic, seourls auto, SEO_SUPPORT 1: friendly');
$check(UrlMode::usesFriendlyLinks(UrlMode::AUTOMATIC, 'auto', 0) === false, 'Automatic, seourls auto, SEO_SUPPORT 0: plain');
$check(UrlMode::usesFriendlyLinks(UrlMode::AUTOMATIC, 'auto', null) === false, 'Automatic, seourls auto, SEO_SUPPORT not set: plain');
$check(UrlMode::usesFriendlyLinks(UrlMode::AUTOMATIC, 'no', 1) === false, 'Automatic, seourls no, SEO_SUPPORT 1: plain');
$check(UrlMode::usesFriendlyLinks(UrlMode::AUTOMATIC, 'yes', null) === true, 'Automatic, seourls yes, SEO_SUPPORT not set: friendly');


// D243: Automatic is friendly only when core's rule holds AND the plugin's own marker (SHOWCASE_SEO_SUPPORT, isset and
// loosely == 1, the same comparison core makes) is set on the request. Yes ignores the marker; No ignores it.
$markers = [null, 1, '1', true, 0, '0', '', 2, '2', false];
$markerLabel = static fn(mixed $v): string => $v === null ? 'null' : var_export($v, true);
foreach (['no', 'auto', 'yes'] as $seourls) {
    foreach ($seoValues as $seo) {
        foreach ($markers as $marker) {
            $label = "seourls {$seourls}, SEO_SUPPORT " . ($seo === null ? 'null' : var_export($seo, true)) . ", marker {$markerLabel($marker)}";
            $coreAuto = $seourls === 'yes' || ($seourls === 'auto' && $seo !== null && $seo == 1);
            $markerSet = $marker !== null && $marker == 1;

            $check(UrlMode::usesFriendlyLinks(UrlMode::PLAIN, $seourls, $seo, $marker) === false, "mode 0 is plain: {$label}");
            $check(UrlMode::usesFriendlyLinks(UrlMode::FRIENDLY, $seourls, $seo, $marker) === true, "mode 2 is friendly, the marker ignored: {$label}");
            $check(UrlMode::usesFriendlyLinks(UrlMode::AUTOMATIC, $seourls, $seo, $marker) === ($coreAuto && $markerSet),
                "Automatic is friendly only with core's rule and the marker: {$label}");
        }
    }
}
$check(UrlMode::usesFriendlyLinks(UrlMode::AUTOMATIC, 'yes', 1, null) === false, 'Automatic with seourls yes and no marker: plain (D243)');
$check(UrlMode::usesFriendlyLinks(UrlMode::AUTOMATIC, 'yes', null, 1) === true, 'Automatic with seourls yes and the marker: friendly');
$check(UrlMode::usesFriendlyLinks(UrlMode::AUTOMATIC, 'auto', 1, null) === false, 'Automatic with seourls auto, SEO_SUPPORT 1 and no marker: plain (D243)');
$check(UrlMode::usesFriendlyLinks(UrlMode::AUTOMATIC, 'auto', 1, 1) === true, 'Automatic with seourls auto, SEO_SUPPORT 1 and the marker: friendly');
echo "{$checks} checks, {$failures} failed\n";
exit($failures ? 1 : 0);
