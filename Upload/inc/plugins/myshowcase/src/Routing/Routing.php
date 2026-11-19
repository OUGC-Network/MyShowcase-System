<?php
/**
 * MyShowcase Plugin for MyBB - Main Plugin
 * Copyright 2012 CommunityPlugins.com, All Rights Reserved
 *
 * Website: https://github.com/Sama34/MyShowcase-System
 * Version 2.5.2
 * License: Creative Commons Attribution-NonCommerical ShareAlike 3.0
 * http://creativecommons.org/licenses/by-nc-sa/3.0/legalcode
 * File: \inc\plugins\myshowcase.php
 *
 */

declare(strict_types=1);

namespace MyShowcase\Routing;

use MyShowcase\Controller\AttachmentsController;
use MyShowcase\Controller\CommentsController;
use MyShowcase\Controller\EntriesController;
use MyShowcase\Controller\ExportController;
use MyShowcase\Controller\ImportController;
use MyShowcase\Enum\Routes;
use MyShowcase\Showcase;
use MyShowcase\ShowcaseContext;
use MyShowcase\Util\StringUtil;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class Routing
{
    private ?Showcase $showcase = null;

    private ?RouteCollection $routes = null;

    private ?RequestContext $routing_context = null;
    private ?UrlGenerator $generator = null;
    private ?Request $request = null;
    private ?UrlMatcher $matcher = null;

    private readonly Showcase $config;

    public function __construct(
        public readonly ShowcaseContext $context,
        //Showcase $showcase,
        //private readonly DB_SQLite|DB_PgSQL|PostgresPdoDbDriver|MysqlPdoDbDriver|DB_MySQLi $database,
        //private readonly MyBB $mybb,
        //private readonly MyLanguage $language,
        //private readonly EventDispatcher $event_dispatcher,
    )
    {
        if ($this->showcase === null) {
            //$this->showcase = $showcase;
        }

        $this->setRoutes();

        $this->routing_context = new RequestContext();

        $this->generator = new UrlGenerator($this->routes, $this->routing_context);

        $this->request = Request::createFromGlobals();

        $this->routing_context->fromRequest($this->request);

        $this->matcher = new UrlMatcher($this->routes, $this->routing_context);
    }

    private function setRoutes(): void
    {
        // Create a RouteCollection and add routes
        $this->routes = new RouteCollection();

        $public_id_pattern = (new StringUtil())->publicIdPattern();

        $this->routes->add(
            Routes::Main,
            new Route(
                '/{showcase}/',
                [
                    '_controller' => function (array $parameters): void {
                        output_page(
                            (new EntriesController($this->context,))->main()
                        );
                    },
                ],
                [
                    '_method' => 'GET',
                    'showcase' => '[a-z0-9]+',
                ],
            )
        );

        // The router does not tell GET from POST (like EntryCreate), so the controller does: a POST generates the export.
        $this->routes->add(
            Routes::ExportMain,
            new Route(
                '/{showcase}/export',
                [
                    '_controller' => function (array $parameters): void {
                        $controller = new ExportController($this->context,);

                        output_page(
                            $this->context->mybb->request_method === 'post' ? $controller->create() : $controller->main()
                        );
                    },
                ],
                [
                    '_method' => 'GET',
                    'showcase' => '[a-z0-9]+',
                ],
            )
        );

        $this->routes->add(
            Routes::ExportView,
            new Route(
                '/{showcase}/export/{export_id}',
                [
                    '_controller' => function (array $parameters): void {
                        output_page((new ExportController($this->context,))->view((int)$parameters['export_id']));
                    },
                ],
                [
                    '_method' => 'GET',
                    'showcase' => '[a-z0-9]+',
                    'export_id' => '[0-9]+',
                ],
            )
        );

        $this->routes->add(
            Routes::ExportDownload,
            new Route(
                '/{showcase}/export/{export_id}/download',
                [
                    '_controller' => function (array $parameters): void {
                        (new ExportController($this->context,))->download((int)$parameters['export_id']);
                    },
                ],
                [
                    '_method' => 'GET',
                    'showcase' => '[a-z0-9]+',
                    'export_id' => '[0-9]+',
                ],
            )
        );

        $this->routes->add(
            Routes::ExportDelete,
            new Route(
                '/{showcase}/export/{export_id}/delete',
                [
                    '_controller' => function (array $parameters): void {
                        (new ExportController($this->context,))->delete((int)$parameters['export_id']);
                    },
                ],
                [
                    '_method' => 'POST',
                    'showcase' => '[a-z0-9]+',
                    'export_id' => '[0-9]+',
                ],
            )
        );

        // The router does not tell GET from POST (like ExportMain): a POST validates or imports the posted file.
        $this->routes->add(
            Routes::ImportMain,
            new Route(
                '/{showcase}/import',
                [
                    '_controller' => function (array $parameters): void {
                        $controller = new ImportController($this->context,);

                        output_page(
                            $this->context->mybb->request_method === 'post' ? $controller->submit() : $controller->main()
                        );
                    },
                ],
                [
                    '_method' => 'GET',
                    'showcase' => '[a-z0-9]+',
                ],
            )
        );

        $this->routes->add(
            Routes::EntryCreate,
            new Route(
                '/{showcase}/create',
                [
                    '_controller' => function (array $parameters): void {
                        unset($parameters['showcase']);

                        output_page(
                            (new EntriesController($this->context,))->create(...$parameters)
                        );
                    },
                ],
                [
                    '_method' => 'GET',
                    'showcase' => '[a-z0-9]+',
                ],
            )
        );

        $this->routes->add(
            Routes::EntryUpdate,
            new Route(
                '/{showcase}/update/{entry_public_id}',
                [
                    '_controller' => function (array $parameters): void {
                        unset($parameters['showcase']);

                        output_page(
                            (new EntriesController($this->context,))->update(...$parameters)
                        );
                    },
                ],
                [
                    'showcase' => '[a-z0-9]+',
                    'entry_public_id' => $public_id_pattern,
                ]
            )
        );

        $this->routes->add(
            Routes::EntryCreateUploadApi,
            new Route(
                '/{showcase}/update/{post_hash}/upload',
                [
                    '_controller' => function (array $parameters): void {
                        unset($parameters['showcase']);

                        (new AttachmentsController($this->context, is_api_request: true))
                            ->uploadAttachment(...$parameters);
                    },
                ],
                [
                    '_method' => 'POST',
                    'showcase' => '[a-z0-9]+',
                    'post_hash' => $public_id_pattern,
                ]
            )
        );

        $this->routes->add(
            Routes::EntryUpdateApi,
            new Route(
                '/{showcase}/api/update/{entry_public_id}',
                [
                    '_controller' => function (array $parameters): void {
                        unset($parameters['showcase']);

                        output_page(
                            (new EntriesController($this->context, is_api_request: true))->update(...$parameters)
                        );
                    },
                ],
                [
                    'showcase' => '[a-z0-9]+',
                    'entry_public_id' => $public_id_pattern,
                ]
            )
        );

        $this->routes->add(
            Routes::EntryUpdateUploadApi,
            new Route(
                '/{showcase}/api/view/{entry_public_id}/upload',
                [
                    '_controller' => function (array $parameters): void {
                        unset($parameters['showcase']);

                        (new AttachmentsController($this->context, is_api_request: true))
                            ->uploadAttachment(...$parameters);
                    },
                ],
                [
                    '_method' => 'POST',
                    'showcase' => '[a-z0-9]+',
                    'entry_public_id' => $public_id_pattern,
                ]
            )
        );

        $this->routes->add(
            Routes::EntryView,
            new Route(
                '/{showcase}/view/{entry_public_id}/{entry_slug_custom}',
                [
                    '_controller' => function (array $parameters): void {
                        unset($parameters['showcase']);

                        output_page(
                            (new EntriesController($this->context,))->view(...$parameters)
                        );
                    },
                ],
                [
                    '_method' => 'GET',
                    'showcase' => '[a-z0-9]+',
                    'entry_public_id' => $public_id_pattern,
                    //'entry_slug_custom' => '[a-z0-9-]*',
                ]
            )
        );

        $this->routes->add(
            Routes::EntryViewApi,
            new Route(
                '/{showcase}/api/view/{entry_public_id}/{entry_slug_custom}',
                [
                    '_controller' => function (array $parameters): void {
                        unset($parameters['showcase']);

                        output_page(
                            (new EntriesController($this->context, is_api_request: true))->view(...$parameters)
                        );
                    },
                ],
                [
                    '_method' => 'GET',
                    'showcase' => '[a-z0-9]+',
                    'entry_public_id' => $public_id_pattern,
                    //'entry_slug_custom' => '[a-z0-9-]*',
                ]
            )
        );

        $this->routes->add(
            Routes::CommentCreate,
            new Route(
                '/{showcase}/view/{entry_public_id}/create/comment',
                [
                    '_controller' => function (array $parameters): void {
                        unset($parameters['showcase']);

                        output_page(
                            (new CommentsController($this->context,))->create(...$parameters)
                        );
                    },
                ],
                [
                    'showcase' => '[a-z0-9]+',
                    'entry_public_id' => $public_id_pattern,
                ]
            )
        );

        $this->routes->add(
            Routes::CommentUpdate,
            new Route(
                '/{showcase}/view/{entry_public_id}/update/comment/{comment_public_id}',
                [
                    '_controller' => function (array $parameters): string {
                        unset($parameters['showcase']);

                        output_page(
                            (new CommentsController($this->context,))->update(...$parameters)
                        );
                    },
                ],
                [
                    'showcase' => '[a-z0-9]+',
                    'entry_public_id' => $public_id_pattern,
                    'comment_public_id' => $public_id_pattern,
                ]
            )
        );

        $this->routes->add(
            Routes::CommentUpdateApi,
            new Route(
                '/{showcase}/api/view/{entry_public_id}/update/comment/{comment_public_id}',
                [
                    '_controller' => function (array $parameters): void {
                        unset($parameters['showcase']);

                        output_page(
                            (new EntriesController($this->context, is_api_request: true))->view(...$parameters)
                        );
                    },
                ],
                [
                    '_method' => 'GET',
                    'showcase' => '[a-z0-9]+',
                    'entry_public_id' => $public_id_pattern,
                    //'entry_slug_custom' => '[a-z0-9-]*',
                ]
            )
        );

        $this->routes->add(
            Routes::CommentView,
            new Route(
                '/{showcase}/comment/{comment_public_id}',
                [
                    '_controller' => function (array $parameters): void {
                        unset($parameters['showcase']);

                        (new CommentsController($this->context,))->redirect(...$parameters);
                    },
                ],
                [
                    '_method' => 'GET',
                    'showcase' => '[a-z0-9]+',
                    'comment_public_id' => $public_id_pattern,
                ]
            )
        );

        $this->routes->add(
            Routes::AttachmentDownload,
            new Route(
                '/{showcase}/attachment/{attachment_public_id}/{attachment_name}',
                [
                    '_controller' => function (array $parameters): void {
                        unset($parameters['showcase']);

                        // todo, maybe check that only the current user is trying to view ?
                        (new AttachmentsController($this->context, is_api_request: true))
                            ->downloadAttachment(...$parameters);
                    },
                ],
                [
                    '_method' => 'GET',
                    'showcase' => '[a-z0-9]+',
                    'attachment_public_id' => $public_id_pattern,
                    'attachment_name' => '[^\/:*?"<>|\x00-\x1F]+',
                ]
            ),
        );

        $this->routes->add(
            Routes::AttachmentThumbnail,
            new Route(
                '/{showcase}/thumbnail/{attachment_public_id}/{attachment_name}',
                [
                    '_controller' => function (array $parameters): void {
                        unset($parameters['showcase']);

                        (new AttachmentsController($this->context, is_api_request: true))
                            ->viewThumbnail(...$parameters);
                    },
                ],
                [
                    '_method' => 'GET',
                    'showcase' => '[a-z0-9]+',
                    'attachment_public_id' => $public_id_pattern,
                    'attachment_name' => '[^\/:*?"<>|\x00-\x1F]+',
                ],
            )
        );

        $this->routes->add(
            Routes::Search,
            new Route(
                '/{showcase}/search',
                [
                    '_controller' => function (array $parameters): void {
                        unset($parameters['showcase']);

                        output_page(
                            (new EntriesController($this->context,))->search(...$parameters)
                        );
                    },
                ],
                [
                    'showcase' => '[a-z0-9]+',
                ],
            )
        );

        $this->routes->add(
            Routes::SearchResults,
            new Route(
                '/{showcase}/search/{search_hash}',
                [
                    '_controller' => function (array $parameters): void {
                        unset($parameters['showcase']);

                        output_page(
                            (new EntriesController($this->context,))->search(...$parameters)
                        );
                    },
                ],
                [
                    '_method' => 'GET',
                    'showcase' => '[a-z0-9]+',
                    'search_hash' => '[a-z0-9-]+',
                ]
            )
        );
    }

    public function getMatch(): array
    {
        return $this->matcher->match($this->request->getPathInfo());
    }

    public function generateUrl(string $name, array $parameters = []): string
    {
        $parameters['showcase'] = $this->context->showcase->route_identifier;

        $this->routing_context->setBaseUrl('/');

        return $this->generator->generate($name, $parameters, UrlGenerator::ABSOLUTE_PATH);
    }

    /**
     * The one builder of the links the plugin generates for a showcase (decisions D224, D229): the base the route path is
     * added to. Plain links keep the showcase.php segment (the showcase's "Url to Showcase", or the board address with
     * showcase.php); friendly links drop it, when the base ends in it. Both forms are always routed.
     */
    public function base(): string
    {
        return self::showcaseBase(
            (string)$this->context->showcase->showcase_url,
            (int)$this->context->showcase->url_mode,
            (string)($this->context->mybb->settings['bburl'] ?? ''),
            (string)($this->context->mybb->settings['seourls'] ?? 'no'),
            $_SERVER['SEO_SUPPORT'] ?? null,
            $_SERVER['SHOWCASE_SEO_SUPPORT'] ?? null
        );
    }

    /**
     * The plain base of the showcase, whatever its url_mode: the form that is written into files (decision D238).
     */
    public function plainBase(): string
    {
        return self::showcaseBase(
            (string)$this->context->showcase->showcase_url,
            UrlMode::PLAIN,
            (string)($this->context->mybb->settings['bburl'] ?? ''),
            'no',
            null,
            null
        );
    }

    /**
     * The friendly base of the showcase: the other form of the same address, which an own-board file URL may also take
     * (decisions D52, D58). Equal to the plain base when the prefix is custom.
     */
    public function friendlyBase(): string
    {
        return self::showcaseBase(
            (string)$this->context->showcase->showcase_url,
            UrlMode::FRIENDLY,
            (string)($this->context->mybb->settings['bburl'] ?? ''),
            'no',
            null,
            null
        );
    }

    /**
     * The base of the links of a showcase, without a trailing slash, as a pure function of its settings and the request.
     *
     * @param mixed $seoSupport $_SERVER['SEO_SUPPORT'] of the request; null when it is not set
     * @param mixed $marker $_SERVER['SHOWCASE_SEO_SUPPORT'] of the request; null when it is not set
     */
    public static function showcaseBase(string $showcaseUrl, int $urlMode, string $boardUrl, string $seourls, mixed $seoSupport, mixed $marker = null): string
    {
        $base = self::resolveBase($showcaseUrl, $boardUrl);

        if (UrlMode::usesFriendlyLinks($urlMode, $seourls, $seoSupport, $marker) && str_ends_with($base, '/showcase.php')) {
            $base = substr($base, 0, -strlen('/showcase.php'));
        }

        return $base;
    }

    /**
     * The path of a route of the showcase, without the base: for comparing a route, never for a link.
     *
     * @param array<string, mixed> $parameters
     */
    public function path(string $name, array $parameters = []): string
    {
        return $this->generateUrl($name, $parameters);
    }

    /**
     * The absolute base of a showcase (decision D244): an absolute value (with a scheme and a host) is used as it is; a value
     * starting with "/" is resolved against the origin of the board address; any other value against the board address;
     * an empty value falls back to the board address with showcase.php. The result has no doubled slashes and no
     * trailing slash.
     */
    public static function resolveBase(string $showcaseUrl, string $boardUrl): string
    {
        $value = trim($showcaseUrl);
        $board = self::normalizeUrl($boardUrl);

        if (preg_match('#^https?://[^/]+#i', $value) === 1) {
            return self::normalizeUrl($value);
        }

        if (str_starts_with($value, '/')) {
            return self::normalizeUrl(self::originOf($board) . $value);
        }

        if ($value !== '') {
            return self::normalizeUrl($board . '/' . $value);
        }

        return self::normalizeUrl($board . '/showcase.php');
    }

    /** A URL without doubled slashes after its scheme and without a trailing slash. */
    public static function normalizeUrl(string $url): string
    {
        if (preg_match('#^(https?://)(.*)$#i', $url, $parts) === 1) {
            return $parts[1] . rtrim((string)preg_replace('#/{2,}#', '/', $parts[2]), '/');
        }

        return rtrim((string)preg_replace('#/{2,}#', '/', $url), '/');
    }

    /** The scheme and host of a URL (its origin), or the URL itself when it has none. */
    public static function originOf(string $url): string
    {
        return preg_match('#^(https?://[^/]+)#i', $url, $parts) === 1 ? $parts[1] : $url;
    }

    /**
     * A link of the showcase: the builder's base followed by the route's path.
     *
     * @param array<string, mixed> $parameters
     */
    public function url(string $name, array $parameters = []): string
    {
        return $this->base() . $this->generateUrl($name, $parameters);
    }

    public function routeIdentifier(): string|false
    {
        static $route_identifier = null;

        if ($route_identifier === null) {
            $route_identifier = array_values(
                array_filter(explode('/', $this->routing_context->getPathInfo()))
            )[0] ?? false;
        }

        return $route_identifier;
    }
}