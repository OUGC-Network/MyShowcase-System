<?php

declare(strict_types=1);

namespace MyShowcase\Service;

use MyShowcase\Enum\Routes;
use MyShowcase\Routing\Routing;
use MyShowcase\ShowcaseContext;
use MyShowcase\Util\StringUtil;
use Twig\Environment;

use function MyBB\app;

/**
 * Effective template package for a showcase request: administrator prefix plus per-showcase visitor preference (TP-D5).
 */
final class TemplateSelection
{
    public const COOKIE_NAME = 'myshowcase_tpl_prefs';

    public const MODE_CONFIGURED = 'c';

    public const MODE_GLOBAL = 'g';

    public const RETURN_ROUTE_MAIN = 'main';

    public const RETURN_ROUTE_ENTRY_VIEW = 'entry_view';

    public const RETURN_ROUTE_ENTRY_CREATE = 'entry_create';

    public const RETURN_ROUTE_ENTRY_UPDATE = 'entry_update';

    private const MAX_COOKIE_BYTES = 2048;

    private const MAX_SHOWCASE_ENTRIES = 40;

    private const ALLOWED_RETURN_ROUTES = [
        self::RETURN_ROUTE_MAIN,
        self::RETURN_ROUTE_ENTRY_VIEW,
        self::RETURN_ROUTE_ENTRY_CREATE,
        self::RETURN_ROUTE_ENTRY_UPDATE,
    ];

    /** Shipped showcase JSON files and their default template folder (TP-D3 C). */
    private const PRESET_SHOWCASE_PACKAGES = [
        'showcases/blog.json' => 'blog',
        'showcases/downloads.json' => 'downloads',
        'showcases/gallery.json' => 'gallery',
    ];

    private string $toggleReturnRoute = self::RETURN_ROUTE_MAIN;

    /** @var array<string, string> */
    private array $toggleRouteParams = [];

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

    /**
     * @return array<string, string> showcase_id => c|g
     */
    public static function decodeCookiePreferences(string $raw): array
    {
        if ($raw === '' || strlen($raw) > self::MAX_COOKIE_BYTES) {
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
            if (is_int($key)) {
                $key = (string)$key;
            }

            if (!is_string($key) || preg_match('/^\d{1,10}$/', $key) !== 1) {
                continue;
            }

            if ($value !== self::MODE_GLOBAL && $value !== self::MODE_CONFIGURED) {
                continue;
            }

            $prefs[$key] = $value;

            if (count($prefs) >= self::MAX_SHOWCASE_ENTRIES) {
                break;
            }
        }

        if (count($prefs) > self::MAX_SHOWCASE_ENTRIES) {
            $prefs = array_slice($prefs, -self::MAX_SHOWCASE_ENTRIES, null, true);
        }

        return $prefs;
    }

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

        if ($prefix === '' || !in_array($prefix, ShowcaseService::templateSets(), true)) {
            return false;
        }

        return $this->packageHasAnyTemplateFile($prefix);
    }

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
        $id = (string)(int)$this->context->showcase->showcase_id;

        return $prefs[$id] ?? self::MODE_CONFIGURED;
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

    public function configureToggleReturn(string $returnRoute, array $routeParameters = []): void
    {
        if (!in_array($returnRoute, self::ALLOWED_RETURN_ROUTES, true)) {
            $returnRoute = self::RETURN_ROUTE_MAIN;
            $routeParameters = [];
        }

        $this->toggleReturnRoute = $returnRoute;
        $this->toggleRouteParams = $routeParameters;
    }

    public function buildTogglePostUrl(): string
    {
        return $this->buildRoutedUrl($this->toggleReturnRoute, $this->toggleRouteParams);
    }

    public function buildReturnUrlFromRequest(): string
    {
        $route = $this->context->mybb->get_input('template_return_route');

        if (!in_array($route, self::ALLOWED_RETURN_ROUTES, true)) {
            return $this->context->routing->url(Routes::Main);
        }

        $params = [];

        if ($route === self::RETURN_ROUTE_ENTRY_VIEW || $route === self::RETURN_ROUTE_ENTRY_UPDATE) {
            $publicId = (string)$this->context->mybb->get_input('template_return_entry_public_id');

            if (!(new StringUtil())->isValidPublicId($publicId)) {
                return $this->context->routing->url(Routes::Main);
            }

            $params = [
                'entry_public_id' => $publicId,
                'entry_slug_custom' => (string)$this->context->mybb->get_input('template_return_entry_slug_custom'),
            ];
        }

        return $this->buildRoutedUrl($route, $params);
    }

    private function buildRoutedUrl(string $route, array $parameters): string
    {
        $routing = $this->context->routing;

        return match ($route) {
            self::RETURN_ROUTE_ENTRY_VIEW => $routing->url(Routes::EntryView, $parameters),
            self::RETURN_ROUTE_ENTRY_CREATE => $routing->url(Routes::EntryCreate),
            self::RETURN_ROUTE_ENTRY_UPDATE => $routing->url(Routes::EntryUpdate, $parameters),
            default => $routing->url(Routes::Main),
        };
    }

    /**
     * @return array<string, string>
     */
    private function readPreferences(): array
    {
        global $mybb;

        return self::decodeCookiePreferences((string)($mybb->cookies[self::COOKIE_NAME] ?? ''));
    }

    public function resolveTwigTemplateName(string $logicalName): string
    {
        if (str_contains($logicalName, '/')) {
            $parts = explode('/', $logicalName, 2);
            $prefix = $parts[0];
            $inner = $this->normalizeLogicalName($parts[1] ?? '');

            if (in_array($prefix, ShowcaseService::templateSets(), true) && $this->packageTemplateExists($prefix, $inner)) {
                return "@ext.myshowcase/{$prefix}/{$inner}.twig";
            }

            if ($inner !== '') {
                /** @var Environment $twig */
                $twig = app(Environment::class);

                if ($twig->getLoader()->exists("@ext.myshowcase/{$inner}.twig")) {
                    return "@ext.myshowcase/{$inner}.twig";
                }
            }
        }

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

        if (str_contains($logicalName, '/')) {
            [$prefix, $name] = array_pad(explode('/', $logicalName, 2), 2, '');

            if (in_array($prefix, ShowcaseService::templateSets(), true)) {
                return $twig->getLoader()->exists("@ext.myshowcase/{$prefix}/{$name}.twig");
            }
        }

        return $twig->getLoader()->exists($this->resolveTwigTemplateName($logicalName));
    }

    private function packageTemplateExists(string $prefix, string $logicalName): bool
    {
        $logicalName = $this->normalizeLogicalName($logicalName);

        /** @var Environment $twig */
        $twig = app(Environment::class);

        return $twig->getLoader()->exists("@ext.myshowcase/{$prefix}/{$logicalName}.twig");
    }

    private function packageHasAnyTemplateFile(string $prefix): bool
    {
        foreach (glob(\MyShowcase\ROOT . '/view/templates/' . $prefix . '/*.twig') ?: [] as $file) {
            if (is_file($file)) {
                return true;
            }
        }

        return false;
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
        $postUrl = $this->buildTogglePostUrl();

        return [
            'template_configured_prefix' => $configured,
            'template_effective_prefix' => $effective,
            'template_visitor_mode' => $this->visitorMode(),
            'template_show_view_toggle' => $this->hasConfigurablePackage(),
            'template_return_route' => $this->toggleReturnRoute,
            'url_template_view_post' => $postUrl,
            'url_template_view_return' => $postUrl,
        ];
    }
}