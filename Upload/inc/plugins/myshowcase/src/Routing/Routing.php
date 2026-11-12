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
use MyShowcase\Enum\Routes;
use MyShowcase\Showcase;
use MyShowcase\ShowcaseContext;
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
                '/{showcase}/update/{entry_slug}',
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
                    'entry_slug' => '[a-z0-9-]+',
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
                    'post_hash' => '[a-z0-9-]+',
                ]
            )
        );

        $this->routes->add(
            Routes::EntryUpdateApi,
            new Route(
                '/{showcase}/api/update/{entry_slug}',
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
                    'entry_slug' => '[a-z0-9-]+',
                ]
            )
        );

        $this->routes->add(
            Routes::EntryUpdateUploadApi,
            new Route(
                '/{showcase}/api/view/{entry_slug}/upload',
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
                    'entry_slug' => '[a-z0-9-]+',
                ]
            )
        );

        $this->routes->add(
            Routes::EntryView,
            new Route(
                '/{showcase}/view/{entry_slug}/{entry_slug_custom}',
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
                    'entry_slug' => '[a-z0-9-]+',
                    //'entry_slug_custom' => '[a-z0-9-]*',
                ]
            )
        );

        $this->routes->add(
            Routes::EntryViewApi,
            new Route(
                '/{showcase}/api/view/{entry_slug}/{entry_slug_custom}',
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
                    'entry_slug' => '[a-z0-9-]+',
                    //'entry_slug_custom' => '[a-z0-9-]*',
                ]
            )
        );

        $this->routes->add(
            Routes::CommentCreate,
            new Route(
                '/{showcase}/view/{entry_slug}/create/comment',
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
                    'entry_slug' => '[a-z0-9-]+',
                ]
            )
        );

        $this->routes->add(
            Routes::CommentUpdate,
            new Route(
                '/{showcase}/view/{entry_slug}/update/comment/{comment_slug}',
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
                    'entry_slug' => '[a-z0-9-]+',
                    'comment_slug' => '[a-z0-9-]+',
                ]
            )
        );

        $this->routes->add(
            Routes::CommentUpdateApi,
            new Route(
                '/{showcase}/api/view/{entry_slug}/update/comment/{comment_slug}',
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
                    'entry_slug' => '[a-z0-9-]+',
                    //'entry_slug_custom' => '[a-z0-9-]*',
                ]
            )
        );

        $this->routes->add(
            Routes::CommentView,
            new Route(
                '/{showcase}/comment/{comment_slug}',
                [
                    '_controller' => function (array $parameters): void {
                        unset($parameters['showcase']);

                        (new CommentsController($this->context,))->redirect(...$parameters);
                    },
                ],
                [
                    '_method' => 'GET',
                    'showcase' => '[a-z0-9]+',
                    'comment_slug' => '[a-z0-9-]+',
                ]
            )
        );

        $this->routes->add(
            Routes::AttachmentDownload,
            new Route(
                '/{showcase}/attachment/{attachment_hash}/{attachment_name}',
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
                    'attachment_hash' => '[a-z0-9-]+',
                    'attachment_name' => '[^\/:*?"<>|\x00-\x1F]+',
                ]
            ),
        );

        $this->routes->add(
            Routes::AttachmentThumbnail,
            new Route(
                '/{showcase}/thumbnail/{attachment_hash}/{attachment_name}',
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
                    'attachment_hash' => '[a-z0-9-]+',
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