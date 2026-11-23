<?php

declare(strict_types=1);

namespace MyShowcase\Service;

use MyBB;
use MyShowcase\Routing\Routing;
use MyShowcase\ShowcaseContext;
use Twig\Environment;

use function MyBB\app;

/**
 * Effective template package for a showcase request: administrator prefix plus per-showcase visitor preference (TP-D5).
 */
final class TemplateSelection
{
    public const COOKIE_NAME = 'myshowcase_tpl_prefs';

  /** Visitor chose the administrator's configured package (or global when none is configured). */
    public const MODE_CONFIGURED = 'c';

  /** Visitor chose the global templates for this showcase only. */
    public const MODE_GLOBAL = 'g';

    private const MAX_COOKIE_BYTES = 2048;

    private const MAX_SHOWCASE_ENTRIES = 40;

    /** Shipped showcase JSON files and their default template folder (TP-D3 C). */
    private const PRESET_SHOWCASE_PACKAGES = [
        'showcases/blog.json' => 'blog',
        'showcases/downloads.json' => 'downloads',
        'showcases/gallery.json' => 'gallery',
    ];

    public function __construct(
        private readonly ShowcaseContext $context,
    ) {
    }

    public static function presetPackageForShowcaseJsonFile(string $jsonFile): string
    {
        $jsonFile = ltrim(str_replace('\\', '/', $jsonFile), '/');

        $prefix = self::PRESET_SHOWCASE_PACKAGES[$jsonFile] ?? '';

        if ($prefix === '' || !in_array($prefix, ShowcaseService::templateSets(), true)) {
            return '';
        }

        return $prefix;
    }

    /** Administrator-configured prefix, normalized. */
    public function configuredPrefix(): string
    {
        if ($this->context->showcase === null) {
            return '';
        }

        return ShowcaseService::normalizeTemplatePrefix($this->context->showcase->custom_template_prefix);
    }

    public function hasConfigurablePackage(): bool
    {
        $prefix = $this->configuredPrefix();

        if ($prefix === '') {
            return false;
        }

        return $this->packageTemplateExists($prefix, 'pageMain')
            || $this->packageTemplateExists($prefix, 'pageMainEntry');
    }

    /** Prefix used for templateGet and showcase_template(); empty means the global tree. */
    public function effectivePrefix(): string
    {
        $configured = $this->configuredPrefix();

        if ($configured === '') {
            return '';
        }

        if ($this->visitorMode() === self::MODE_GLOBAL) {
            return '';
        }

        return $configured;
    }

    public function visitorMode(): string
    {
        if ($this->context->showcase === null) {
            return self::MODE_CONFIGURED;
        }

        $prefs = $this->readPreferences();

        return $prefs[(string)(int)$this->context->showcase->showcase_id] ?? self::MODE_CONFIGURED;
    }

    public function setVisitorMode(string $mode): void
    {
        if ($this->context->showcase === null) {
            return;
        }

        $mode = $mode === self::MODE_GLOBAL ? self::MODE_GLOBAL : self::MODE_CONFIGURED;

        $prefs = $this->readPreferences();
        $id = (string)(int)$this->context->showcase->showcase_id;
        $prefs[$id] = $mode;

        if (count($prefs) > self::MAX_SHOWCASE_ENTRIES) {
            $prefs = array_slice($prefs, -self::MAX_SHOWCASE_ENTRIES, null, true);
        }

        $encoded = json_encode($prefs, JSON_THROW_ON_ERROR);

        if (strlen($encoded) > self::MAX_COOKIE_BYTES) {
            return;
        }

        my_setcookie(self::COOKIE_NAME, $encoded, -1, true, 'Lax');
    }

    /**
     * @return array<string, string> showcase_id => c|g
     */
    private function readPreferences(): array
    {
        global $mybb;

        $raw = (string)($mybb->cookies[self::COOKIE_NAME] ?? '');

        if ($raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $prefs = [];

        foreach ($decoded as $key => $value) {
            if (!is_string($key) || !preg_match('/^\d{1,10}$/', $key)) {
                continue;
            }

            if ($value === self::MODE_GLOBAL || $value === self::MODE_CONFIGURED) {
                $prefs[$key] = $value;
            }
        }

        return $prefs;
    }

    public function resolveTwigTemplateName(string $logicalName): string
    {
        $logicalName = $this->normalizeLogicalName($logicalName);

        $prefix = $this->effectivePrefix();

        if ($prefix !== '' && $this->packageTemplateExists($prefix, $logicalName)) {
            return '@ext.myshowcase/' . $prefix . '/' . $logicalName . '.twig';
        }

        return '@ext.myshowcase/' . $logicalName . '.twig';
    }

    public function templateExists(string $logicalName): bool
    {
        $logicalName = $this->normalizeLogicalName($logicalName);

        /** @var Environment $twig */
        $twig = app(Environment::class);

        return $twig->getLoader()->exists($this->resolveTwigTemplateName($logicalName));
    }

    private function packageTemplateExists(string $prefix, string $logicalName): bool
    {
        $logicalName = $this->normalizeLogicalName($logicalName);

        /** @var Environment $twig */
        $twig = app(Environment::class);

        return $twig->getLoader()->exists("@ext.myshowcase/{$prefix}/{$logicalName}.twig");
    }

    private function normalizeLogicalName(string $logicalName): string
    {
        return preg_replace('/\.twig$/i', '', $logicalName) ?? $logicalName;
    }

    /** Variables added to every templateGet context for the visitor toggle and package styling. */
    public function templateContext(): array
    {
        $configured = $this->configuredPrefix();
        $effective = $this->effectivePrefix();

        return [
            'template_configured_prefix' => $configured,
            'template_effective_prefix' => $effective,
            'template_visitor_mode' => $this->visitorMode(),
            'template_show_view_toggle' => $this->hasConfigurablePackage(),
            'absolute_request_url' => $this->absoluteRequestUrl(),
        ];
    }

    private function absoluteRequestUrl(): string
    {
        global $mybb;

        $request_uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $origin = Routing::originOf(
            Routing::normalizeUrl((string)($mybb->settings['bburl'] ?? ''))
        );

        return preg_match('#^https?://[^/]+$#i', $origin) === 1 ? $origin . $request_uri : $request_uri;
    }
}