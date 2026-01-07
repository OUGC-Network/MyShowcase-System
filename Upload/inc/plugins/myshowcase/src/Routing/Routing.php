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

use MyShowcase\Controllers\Attachments;
use MyShowcase\Controllers\Comments;
use MyShowcase\Controllers\Entries;
use MyShowcase\Controllers\Search;
use MyShowcase\Enum\Routes;
use MyShowcase\System\Showcase;
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

    private ?RequestContext $context = null;
    private ?UrlGenerator $generator = null;
    private ?Request $request = null;
    private ?UrlMatcher $matcher = null;

    public function __construct(Showcase $showcase)
    {
        if ($this->showcase === null) {
            $this->showcase = $showcase;
        }

        $this->setRoutes();

        $this->buildContext();

        $this->buildGenerator();

        $this->buildRequest();

        $this->context->fromRequest($this->request);

        $this->buildMatcher();
    }

    private function setRoutes(): void
    {
        // Create a RouteCollection and add routes
        $this->routes = new RouteCollection();

        $this->routes->add(
            Routes::Main,
            new Route(
                '/',
                [
                    '_controller' => function (): void {
                        output_page((new Entries($this))->main());
                    },
                ],
                [
                    '_method' => 'GET',
                ]
            )
        );

        $this->routes->add(
            Routes::Search,
            new Route(
                '/search',
                [
                    '_controller' => function (): string {
                        return (new Search($this))->mainSearch();
                    },
                ],
            )
        );

        $this->routes->add(
            Routes::Search,
            new Route(
                '/search/{search_hash}',
                [
                    '_controller' => function (): string {
                        return (new Search($this))->mainSearch();
                    },
                ],
                [
                    '_method' => 'GET',
                ]
            )
        );

        $this->routes->add(
            Routes::EntryCreate,
            new Route(
                '/create',
                [
                    '_controller' => function (): void {
                        output_page((new Entries($this))->create());
                    },
                ],
            )
        );

        $this->routes->add(
            Routes::EntryCreateUpload,
            new Route(
                '/create/{post_hash}/upload',
                [
                    '_controller' => function (array $parameters): void {
                        output_page((new Attachments($this))->uploadAttachment(...$parameters));
                    },
                ],
                [
                    '_method' => 'POST',
                    'post_hash' => '[a-z0-9-]+',
                ]
            ),
        );

        $this->routes->add(
            Routes::EntryView,
            new Route(
                '/view/{entry_slug}/{entry_slug_custom}',
                [
                    '_controller' => function (array $parameters): void {
                        output_page((new Entries($this))->view(...$parameters));
                    },
                ],
                [
                    '_method' => 'GET',
                    'entry_slug' => '[a-z0-9-]+',
                    'entry_slug_custom' => '[a-z0-9-]*',
                ]
            )
        );

        $this->routes->add(
            Routes::EntryUpdate,
            new Route(
                '/view/{entry_slug}/update',
                [
                    '_controller' => function (array $parameters): void {
                        output_page((new Entries($this))->update(...$parameters));
                    },
                ],
                [
                    'entry_slug' => '[a-z0-9-]+',
                ]
            )
        );

        $this->routes->add(
            Routes::EntryUpdateUpload,
            new Route(
                '/view/{entry_slug}/update/attachment',
                [
                    '_controller' => function (array $parameters): string {
                        (new Attachments($this))->uploadAttachment(...$parameters);
                    },
                ],
                [
                    'entry_slug' => '[a-z0-9-]+',
                ]
            )
        );

        $this->routes->add(
            Routes::Comment,
            new Route(
                '/comment/{comment_slug}',
                [
                    '_controller' => function (): string {
                        return (new Comments($this))->redirect();
                    },
                ],
                [
                    'comment_slug' => '[a-z0-9-]+',
                ]
            )
        );

        $this->routes->add(
            Routes::CommentCreate,
            new Route(
                '/view/{entry_slug}/{entry_slug_custom}/comment',
                [
                    '_controller' => function (array $parameters): string {
                        return (new Comments($this))->create(...$parameters);
                    },
                ],
                [
                    'entry_slug' => '[a-z0-9-]+',
                    'entry_slug_custom' => '[a-z0-9-]*',
                ]
            )
        );

        $this->routes->add(
            Routes::CommentView,
            new Route(
                '/view/{entry_slug}/{entry_slug_custom}/comment/{comment_slug}',
                [
                    '_controller' => function (): string {
                        return (new Comments($this))->view();
                    },
                ],
                [
                    '_method' => 'GET',
                    'entry_slug' => '[a-z0-9-]+',
                    'entry_slug_custom' => '[a-z0-9-]*',
                    'comment_slug' => '[a-z0-9-]+',
                ]
            )
        );

        $this->routes->add(
            Routes::CommentUpdate,
            new Route(
                '/comment/{comment_slug}/update',
                [
                    '_controller' => function (array $parameters): string {
                        return (new Comments($this))->update(...$parameters);
                    },
                ],
                [
                    'entry_slug' => '[a-z0-9-]+',
                    'entry_slug_custom' => '[a-z0-9-]*',
                    'comment_slug' => '[a-z0-9-]+',
                ]
            )
        );

        $this->routes->add(
            Routes::AttachmentViewFromPostHash,
            new Route(
                '/create/{post_hash}/attachment/{attachment_hash}',
                [
                    '_controller' => function (array $parameters): string {
                        // todo, maybe check that only the current user is trying to view ?
                        (new Attachments($this))->downloadAttachment(...$parameters);
                    },
                ],
                [
                    '_method' => 'GET',
                    'post_hash' => '[a-z0-9-]+',
                    'attachment_hash' => '[a-z0-9-]+',
                ]
            ),
        );

        $this->routes->add(
            Routes::AttachmentView,
            new Route(
                '/view/{entry_slug}/attachment/{attachment_hash}',
                [
                    '_controller' => function (array $parameters): string {
                        (new Attachments($this))->downloadAttachment(...$parameters);
                    },
                ],
                [
                    '_method' => 'GET',
                    'entry_slug' => '[a-z0-9-]+',
                    'attachment_hash' => '[a-z0-9-]+',
                ]
            )
        );

        $this->routes->add(
            Routes::ThumbnailView,
            new Route(
                '/view/{entry_slug}/thumbnail/{attachment_hash}',
                [
                    '_controller' => function (array $parameters): string {
                        $parameters['is_thumbnail'] = true;

                        (new Attachments($this))->viewThumbnail(...$parameters);
                    },
                ],
                [
                    '_method' => 'GET',
                    'entry_slug' => '[a-z0-9-]+',
                    'attachment_hash' => '[a-z0-9-]+',
                ]
            )
        );
    }

    private function getRoutes(): ?RouteCollection
    {
        return $this->routes;
    }

    private function buildContext(): RequestContext
    {
        if ($this->context !== null) {
            return $this->context;
        }

        // Match the current request
        $this->context = new RequestContext('/' . $this->showcase->contextPath); // Base URL

        $this->context->setBaseUrl($this->showcase->config['url_root']);

        return $this->context;
    }

    private function buildGenerator(): void
    {
        if ($this->generator !== null) {
            return;
        }

        $this->generator = new UrlGenerator($this->getRoutes(), $this->buildContext());
    }

    private function buildRequest(): void
    {
        if ($this->request !== null) {
            return;
        }

        $this->request = Request::createFromGlobals();
    }

    private function buildMatcher(): UrlMatcher
    {
        if ($this->matcher !== null) {
            return $this->matcher;
        }

        $this->matcher = new UrlMatcher($this->routes, $this->context);

        return $this->matcher;
    }

    public function getMatch(): array
    {
        $pathInfo = str_replace($this->showcase->contextPath, '', $this->request->getPathInfo());

        return $this->buildMatcher()->match($pathInfo);
    }

    public function generateUrl(string $name, array $parameters = []): string
    {
        return str_replace(
            '../',
            '',
            $this->generator->generate($name, $parameters, UrlGenerator::RELATIVE_PATH)
        );
    }
}