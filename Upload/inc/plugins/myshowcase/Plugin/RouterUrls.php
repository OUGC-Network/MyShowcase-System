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

namespace MyShowcase\Plugin;

use MyShowcase\Controllers\Attachments;
use MyShowcase\Controllers\Comments;
use MyShowcase\Controllers\Entries;
use MyShowcase\Controllers\Search;
use MyShowcase\System\Showcase;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGenerator;

class RouterUrls
{
    public const Main = 'main';
    public const MainUnapproved = 'main_unapproved';
    public const Search = 'main_search';
    public const MainUser = 'main_user';
    public const MainPage = 'main_page';
    public const MainApprove = 'main_approve';
    public const MainUnapprove = 'main_unapprove';
    public const MainSoftDelete = 'main_soft_delete';
    public const MainRestore = 'main_restore';
    public const MainDelete = 'main_delete';
    public const EntryCreate = 'entry_create';
    public const EntryCreateUpload = 'entry_create_upload';
    public const EntryCreateFiles = 'entry_create_file';
    public const EntryView = 'entry_view';
    public const EntryViewPage = 'entry_page';
    public const EntryUpdate = 'entry_update';
    public const EntryUpdateUpload = 'entry_update_upload';
    public const EntryApprove = 'entry_approve';
    public const EntryUnapprove = 'entry_unapprove';
    public const EntrySoftDelete = 'entry_soft_delete';
    public const EntryRestore = 'entry_restore';
    public const EntryDelete = 'entry_delete';
    public const Comment = 'comment';
    public const CommentCreate = 'comment_create';
    public const CommentView = 'comment_view';
    public const CommentUpdate = 'comment_update';
    public const CommentApprove = 'comment_approve';
    public const CommentUnapprove = 'comment_unapprove';
    public const CommentSoftDelete = 'comment_soft_delete';
    public const CommentRestore = 'comment_restore';
    public const CommentDelete = 'comment_delete';
    public const AttachmentViewFromPostHash = 'attachment_view_from_post_hash';
    public const AttachmentView = 'attachment_view';
    public const ThumbnailView = 'thumbnail_view';
    private static ?Showcase $showcase = null;

    private static ?RouteCollection $routes = null;

    private static ?RequestContext $context = null;
    private static ?UrlGenerator $generator = null;
    private static ?Request $request = null;
    private static ?UrlMatcher $matcher = null;

    public static function buildRoutes(): RouteCollection
    {
        if (self::$routes !== null) {
            return self::$routes;
        }

        // Create a RouteCollection and add routes
        self::$routes = new RouteCollection();

        foreach (
            [
                //self::$showcase->selfPhpScript .  '/',
                //self::$showcase->selfPhpScript .  '/limit/{limit}',
                //self::$showcase->selfPhpScript .  '/limit/{limit}/start/{start}',
                '/',
                //'/limit/{limit}',
                //'/limit/{limit}/start/{start}',
            ] as $route
        ) {
            self::$routes->add(
                self::Main,
                new Route(
                    self::$showcase->selfPhpScript . '/',
                    [
                        '_controller' => function (): string {
                            return (new Entries())->mainView();
                        },
                    ],
                    [
                        '_method' => 'GET',
                    ]
                )
            );
        }

        self::$routes->add(
            self::MainUnapproved,
            new Route(
                self::$showcase->selfPhpScript . '/unapproved',
                [
                    '_controller' => function (): string {
                        return (new Entries())->mainUnapproved();
                    },
                ],
                [
                    '_method' => 'GET',
                ]
            )
        );

        self::$routes->add(
            self::Search,
            new Route(
                self::$showcase->selfPhpScript . '/search',
                [
                    '_controller' => function (): string {
                        return (new Search())->mainSearch();
                    },
                ],
            )
        );

        self::$routes->add(
            self::Search,
            new Route(
                self::$showcase->selfPhpScript . '/search/{search_hash}',
                [
                    '_controller' => function (): string {
                        return (new Search())->mainSearch();
                    },
                ],
                [
                    '_method' => 'GET',
                ]
            )
        );

        self::$routes->add(
            self::MainUser,
            new Route(
                self::$showcase->selfPhpScript . '/user/{user_id}',
                [
                    '_controller' => function (): string {
                        return (new Entries())->mainUser();
                    },
                ],
                [
                    'id' => '[0-9]+',
                    '_method' => 'GET',
                ]
            )
        );

        self::$routes->add(
            self::MainApprove,
            new Route(
                self::$showcase->selfPhpScript . '/approve',
                [
                    '_controller' => function (): string {
                        return (new Entries())->approveEntries();
                    },
                ],
                [
                    '_method' => 'POST',
                ]
            )
        );

        self::$routes->add(
            self::MainUnapprove,
            new Route(
                self::$showcase->selfPhpScript . '/unapprove',
                [
                    '_controller' => function (): string {
                        return (new Entries())->unapproveEntries();
                    },
                ],
                [
                    '_method' => 'POST',
                ]
            )
        );

        self::$routes->add(
            self::MainSoftDelete,
            new Route(
                self::$showcase->selfPhpScript . '/soft_delete',
                [
                    '_controller' => function (): string {
                        return (new Entries())->softDeleteEntries();
                    },
                ],
                [
                    '_method' => 'POST',
                ]
            )
        );

        self::$routes->add(
            self::MainRestore,
            new Route(
                self::$showcase->selfPhpScript . '/restore',
                [
                    '_controller' => function (): string {
                        return (new Entries())->restoreEntries();
                    },
                ],
                [
                    '_method' => 'POST',
                ]
            )
        );

        self::$routes->add(
            self::MainDelete,
            new Route(
                self::$showcase->selfPhpScript . '/delete',
                [
                    '_controller' => function (): string {
                        return (new Entries())->deleteEntries();
                    },
                ],
                [
                    '_method' => 'POST',
                ]
            )
        );

        self::$routes->add(
            self::EntryCreate,
            new Route(
                self::$showcase->selfPhpScript . '/create',
                [
                    '_controller' => function (): string {
                        return (new Entries())->createEntry();
                    },
                ],
            )
        );

        self::$routes->add(
            self::EntryCreateUpload,
            new Route(
                self::$showcase->selfPhpScript . '/create/{post_hash}/upload',
                [
                    '_controller' => function (array $parameters): string {
                        return (new Attachments())->uploadAttachment(...$parameters);
                    },
                ],
                [
                    '_method' => 'POST',
                    'post_hash' => '[a-z0-9-]+',
                ]
            ),
        );

        self::$routes->add(
            self::EntryCreateFiles,
            new Route(
                self::$showcase->selfPhpScript . '/create/{post_hash}/files',
                [
                    '_controller' => function (array $parameters): string {
                        return (new Attachments())->getAttachments(...$parameters);
                    },
                ],
                [
                    '_method' => 'POST',
                    'post_hash' => '[a-z0-9-]+',
                ]
            ),
        );

        self::$routes->add(
            self::EntryView,
            new Route(
                self::$showcase->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}',
                [
                    '_controller' => function (array $parameters): string {
                        return (new Entries())->viewEntry(...$parameters);
                    },
                ],
                [
                    '_method' => 'GET',
                    'entry_slug' => '[a-z0-9-]+',
                    'entry_slug_custom' => '[a-z0-9-]*',
                ]
            )
        );

        self::$routes->add(
            self::EntryUpdate,
            new Route(
                self::$showcase->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/update',
                [
                    '_controller' => function (): string {
                        return (new Entries())->updateEntry();
                    },
                ],
                [
                    'entry_slug' => '[a-z0-9-]+',
                    'entry_slug_custom' => '[a-z0-9-]*',
                ]
            )
        );

        self::$routes->add(
            self::EntryUpdateUpload,
            new Route(
                self::$showcase->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/update/attachment',
                [
                    '_controller' => function (): string {
                        return (new Attachments())->uploadAttachment();
                    },
                ],
                [
                    'entry_slug' => '[a-z0-9-]+',
                    'entry_slug_custom' => '[a-z0-9-]*',
                ]
            )
        );

        self::$routes->add(
            self::EntryViewPage,
            new Route(
                self::$showcase->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/page/{page_id}',
                [
                    '_controller' => function (): string {
                        return (new Entries())->viewEntry();
                    },
                ],
                [
                    '_method' => 'GET',
                    'entry_slug' => '[a-z0-9-]+',
                    'entry_slug_custom' => '[a-z0-9-]*',
                ]
            )
        );

        self::$routes->add(
            self::EntryApprove,
            new Route(
                self::$showcase->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/approve',
                [
                    '_controller' => function (): string {
                        return (new Entries())->approveEntry();
                    },
                ],
                [
                    '_method' => 'POST',
                    'entry_slug' => '[a-z0-9-]+',
                    'entry_slug_custom' => '[a-z0-9-]*',
                ]
            )
        );

        self::$routes->add(
            self::EntryUnapprove,
            new Route(
                self::$showcase->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/unapprove',
                [
                    '_controller' => function (): string {
                        return (new Entries())->unapproveEntry();
                    },
                ],
                [
                    '_method' => 'POST',
                    'entry_slug' => '[a-z0-9-]+',
                    'entry_slug_custom' => '[a-z0-9-]*',
                ]
            )
        );

        self::$routes->add(
            self::EntrySoftDelete,
            new Route(
                self::$showcase->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/soft_delete',
                [
                    '_controller' => function (): string {
                        return (new Entries())->softDeleteEntry();
                    },
                ],
                [
                    '_method' => 'POST',
                    'entry_slug' => '[a-z0-9-]+',
                    'entry_slug_custom' => '[a-z0-9-]*',
                ]
            )
        );

        self::$routes->add(
            self::EntryRestore,
            new Route(
                self::$showcase->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/restore',
                [
                    '_controller' => function (): string {
                        return (new Entries())->restoreEntry();
                    },
                ],
                [
                    '_method' => 'POST',
                    'entry_slug' => '[a-z0-9-]+',
                    'entry_slug_custom' => '[a-z0-9-]*',
                ]
            )
        );

        self::$routes->add(
            self::EntryDelete,
            new Route(
                self::$showcase->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/delete',
                [
                    '_controller' => function (): string {
                        return (new Entries())->deleteEntry();
                    },
                ],
                [
                    '_method' => 'POST',
                    'entry_slug' => '[a-z0-9-]+',
                    'entry_slug_custom' => '[a-z0-9-]*',
                ]
            )
        );

        self::$routes->add(
            self::Comment,
            new Route(
                self::$showcase->selfPhpScript . '/comment/{comment_slug}',
                [
                    '_controller' => function (): string {
                        return (new Comments())->redirect();
                    },
                ],
                [
                    'comment_slug' => '[a-z0-9-]+',
                ]
            )
        );

        self::$routes->add(
            self::CommentCreate,
            new Route(
                self::$showcase->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/comment',
                [
                    '_controller' => function (array $parameters): string {
                        return (new Comments())->create(...$parameters);
                    },
                ],
                [
                    'entry_slug' => '[a-z0-9-]+',
                    'entry_slug_custom' => '[a-z0-9-]*',
                ]
            )
        );

        self::$routes->add(
            self::CommentView,
            new Route(
                self::$showcase->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/comment/{comment_slug}',
                [
                    '_controller' => function (): string {
                        return (new Comments())->view();
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

        self::$routes->add(
            self::CommentUpdate,
            new Route(
                self::$showcase->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/comment/{comment_slug}/update',
                [
                    '_controller' => function (array $parameters): string {
                        return (new Comments())->update(...$parameters);
                    },
                ],
                [
                    'entry_slug' => '[a-z0-9-]+',
                    'entry_slug_custom' => '[a-z0-9-]*',
                    'comment_slug' => '[a-z0-9-]+',
                ]
            )
        );

        self::$routes->add(
            self::CommentApprove,
            new Route(
                self::$showcase->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/comment/{comment_slug}/approve',
                [
                    '_controller' => function (): string {
                        return (new Comments())->approve();
                    },
                ],
                [
                    '_method' => 'POST',
                    'entry_slug' => '[a-z0-9-]+',
                    'entry_slug_custom' => '[a-z0-9-]*',
                    'comment_slug' => '[a-z0-9-]+',
                ]
            )
        );

        self::$routes->add(
            self::CommentUnapprove,
            new Route(
                self::$showcase->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/comment/{comment_slug}/unapprove',
                [
                    '_controller' => function (): string {
                        return (new Comments())->unapprove();
                    },
                ],
                [
                    '_method' => 'POST',
                    'entry_slug' => '[a-z0-9-]+',
                    'entry_slug_custom' => '[a-z0-9-]*',
                    'comment_slug' => '[a-z0-9-]+',
                ]
            )
        );

        self::$routes->add(
            self::CommentSoftDelete,
            new Route(
                self::$showcase->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/comment/{comment_slug}/soft_delete',
                [
                    '_controller' => function (): string {
                        return (new Comments())->softDelete();
                    },
                ],
                [
                    '_method' => 'POST',
                    'entry_slug' => '[a-z0-9-]+',
                    'entry_slug_custom' => '[a-z0-9-]*',
                    'comment_slug' => '[a-z0-9-]+',
                ]
            )
        );

        self::$routes->add(
            self::CommentRestore,
            new Route(
                self::$showcase->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/comment/{comment_slug}/restore',
                [
                    '_controller' => function (): string {
                        return (new Comments())->restore();
                    },
                ],
                [
                    '_method' => 'POST',
                    'entry_slug' => '[a-z0-9-]+',
                    'entry_slug_custom' => '[a-z0-9-]*',
                    'comment_slug' => '[a-z0-9-]+',
                ]
            )
        );

        self::$routes->add(
            self::CommentDelete,
            new Route(
                self::$showcase->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/comment/{comment_slug}/delete',
                [
                    '_controller' => function (): string {
                        return (new Comments())->delete();
                    },
                ],
                [
                    '_method' => 'POST',
                    'entry_slug' => '[a-z0-9-]+',
                    'entry_slug_custom' => '[a-z0-9-]*',
                    'comment_slug' => '[a-z0-9-]+',
                ]
            )
        );

        self::$routes->add(
            self::AttachmentViewFromPostHash,
            new Route(
                self::$showcase->selfPhpScript . '/create/{post_hash}/attachment/{attachment_hash}',
                [
                    '_controller' => function (array $parameters): string {
                        // todo, maybe check that only the current user is trying to view ?
                        return (new Attachments())->downloadAttachment(...$parameters);
                    },
                ],
                [
                    '_method' => 'GET',
                    'post_hash' => '[a-z0-9-]+',
                    'attachment_hash' => '[a-z0-9-]+',
                ]
            ),
        );

        self::$routes->add(
            self::AttachmentView,
            new Route(
                self::$showcase->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/attachment/{attachment_hash}',
                [
                    '_controller' => function (array $parameters): string {
                        return (new Attachments())->downloadAttachment(...$parameters);
                    },
                ],
                [
                    '_method' => 'GET',
                    'entry_slug' => '[a-z0-9-]+',
                    'entry_slug_custom' => '[a-z0-9-]*',
                    'attachment_hash' => '[a-z0-9-]+',
                ]
            )
        );

        self::$routes->add(
            self::ThumbnailView,
            new Route(
                self::$showcase->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/thumbnail/{attachment_hash}',
                [
                    '_controller' => function (array $parameters): string {
                        $parameters['is_thumbnail'] = true;

                        return (new Attachments())->viewThumbnail(...$parameters);
                    },
                ],
                [
                    '_method' => 'GET',
                    'entry_slug' => '[a-z0-9-]+',
                    'entry_slug_custom' => '[a-z0-9-]*',
                    'attachment_hash' => '[a-z0-9-]+',
                ]
            )
        );

        return self::$routes;
    }

    public static function buildContext(): RequestContext
    {
        if (self::$context !== null) {
            return self::$context;
        }

        global $mybb;

        // Match the current request
        self::$context = new RequestContext('/'); // Base URL

        self::$context->setBaseUrl($mybb->settings['bburl']);

        return self::$context;
    }

    public static function buildGenerator(): UrlGenerator
    {
        if (self::$generator !== null) {
            return self::$generator;
        }

        self::$generator = new UrlGenerator(self::buildRoutes(), self::buildContext());

        return self::$generator;
    }

    public static function buildRequest(): Request
    {
        if (self::$request !== null) {
            return self::$request;
        }

        self::$request = Request::createFromGlobals();

        return self::$request;
    }

    public static function buildMatcher(): UrlMatcher
    {
        if (self::$matcher !== null) {
            return self::$matcher;
        }

        self::$matcher = new UrlMatcher(self::$routes, self::$context);

        return self::$matcher;
    }

    public static function getMatch(): array
    {
        return self::buildMatcher()->match(self::$request->getPathInfo());
    }

    public static function init(Showcase $showcase): void
    {
        if (self::$showcase === null) {
            self::$showcase = $showcase;
        }

        RouterUrls::buildRoutes($showcase);

        RouterUrls::buildContext();

        RouterUrls::buildGenerator();

        RouterUrls::buildRequest();

        self::$context->fromRequest(self::$request);

        RouterUrls::buildMatcher();
    }

    public static function generateUrl(string $name, array $parameters): string
    {
        return self::$generator->generate($name, $parameters, UrlGenerator::ABSOLUTE_URL);
    }
}