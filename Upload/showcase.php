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

use MyShowcase\Plugin\RouterUrls;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGenerator;

use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\showcaseGetObjectByScriptName;

use const MyShowcase\Plugin\Core\DEBUG;
use const MyShowcase\ROOT;

/*
 * Only user edits required
*/

$forumDirectoryPath = ''; //no trailing slash

/*
 * Stop editing
*/

const IN_MYBB = true;

const IN_SHOWCASE = true;

const SHOWCASE_FILE_VERSION_CODE = 3000;

define('THIS_SCRIPT', substr($_SERVER['SCRIPT_NAME'], -mb_strpos(strrev($_SERVER['SCRIPT_NAME']), '/')));

$currentWorkingDirectoryPath = getcwd();

$change_dir = './';

if (!chdir($forumDirectoryPath) && !empty($forumDirectoryPath)) {
    if (is_dir($forumDirectoryPath)) {
        $change_dir = $forumDirectoryPath;
    } else {
        exit("{$forumDirectoryPath} is invalid!");
    }
}

//change working directory to allow board includes to work
$forumDirectoryPathTrailing = ($forumDirectoryPath === '' ? '' : $forumDirectoryPath . '/');

$templatelist = '';

if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/attachment/') ||
    str_contains($_SERVER['REQUEST_URI'] ?? '', '/thumbnail/')) {
    define('NO_ONLINE', 1);

    global $mybb, $lang, $db, $cache, $templates;

    $minimalLoad = true;

    require_once $change_dir . '/inc/init.php';

    $shutdown_queries = $shutdown_functions = [];

    header('Expires: Sat, 1 Jan 2000 01:00:00 GMT');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');

    require_once MYBB_ROOT . 'inc/class_session.php';

    $session = new session();

    $session->init();

    $mybb->session = &$session;

    if (!isset($mybb->settings['bblanguage'])) {
        $mybb->settings['bblanguage'] = 'english';
    }
    if (isset($mybb->user['language']) && $lang->language_exists($mybb->user['language'])) {
        $mybb->settings['bblanguage'] = $mybb->user['language'];
    }
    $lang->set_language($mybb->settings['bblanguage']);

    if (function_exists('mb_internal_encoding') && !empty($lang->settings['charset'])) {
        mb_internal_encoding($lang->settings['charset']);
    }

    $templateList = '';

    if ($templateList) {
        $templates->cache($db->escape_string($templateList));
    }

    if ($lang->settings['charset']) {
        $charset = $lang->settings['charset'];
    } else {
        $charset = 'UTF-8';
    }

    $lang->load('global');

    $closed_bypass = ['refresh_captcha', 'validate_captcha'];
} else {
    $minimalLoad = false;

    require_once $change_dir . '/global.php';
}

function_exists('MyShowcase\Plugin\Functions\hooksRun') || error();

hooksRun('script_file_start');

//change directory back to current where script is
chdir($currentWorkingDirectoryPath);

require_once ROOT . '/Controllers/Base.php';
require_once ROOT . '/vendor/autoload.php';

$requestBaseUriExtra = '';
/*
switch ($showcaseObject->config['filter_force_field']) {
    case FILTER_TYPE_USER_ID:
        $requestBaseUriExtra = '/user/{user_id}';

        break;
    default:
        break;
}
*/
if ($minimalLoad) {
    require_once ROOT . '/Controllers/Attachments.php';
} else {
    require_once ROOT . '/Controllers/Entries.php';
    require_once ROOT . '/Controllers/Comments.php';
}

$showcaseObject = showcaseGetObjectByScriptName(THIS_SCRIPT);

// Create a RouteCollection and add routes
$routes = new RouteCollection();

foreach (
    [
        //$showcaseObject->selfPhpScript . $requestBaseUriExtra . '/',
        //$showcaseObject->selfPhpScript . $requestBaseUriExtra . '/limit/{limit}',
        //$showcaseObject->selfPhpScript . $requestBaseUriExtra . '/limit/{limit}/start/{start}',
        '/',
        //'/limit/{limit}',
        //'/limit/{limit}/start/{start}',
    ] as $route
) {
    $routes->add(
        RouterUrls::Main,
        new Route(
            $showcaseObject->selfPhpScript . '/',
            [
                '_controller' => function (): string {
                    return (new MyShowcase\Controllers\Entries())->mainView();
                },
            ],
            [
                '_method' => 'GET',
            ]
        )
    );
}

$routes->add(
    RouterUrls::MainUnapproved,
    new Route(
        $showcaseObject->selfPhpScript . $requestBaseUriExtra . '/unapproved',
        [
            '_controller' => function (): string {
                return (new MyShowcase\Controllers\Entries())->mainUnapproved();
            },
        ],
        [
            '_method' => 'GET',
        ]
    )
);

$routes->add(
    RouterUrls::Search,
    new Route(
        $showcaseObject->selfPhpScript . $requestBaseUriExtra . '/search',
        [
            '_controller' => function (): string {
                return (new MyShowcase\Controllers\Search())->mainSearch();
            },
        ],
    )
);

$routes->add(
    RouterUrls::Search,
    new Route(
        $showcaseObject->selfPhpScript . $requestBaseUriExtra . '/search/{search_hash}',
        [
            '_controller' => function (): string {
                return (new MyShowcase\Controllers\Search())->mainSearch();
            },
        ],
        [
            '_method' => 'GET',
        ]
    )
);

$routes->add(
    RouterUrls::MainUser,
    new Route(
        $showcaseObject->selfPhpScript . '/user/{user_id}',
        [
            '_controller' => function (): string {
                return (new MyShowcase\Controllers\Entries())->mainUser();
            },
        ],
        [
            'id' => '[0-9]+',
            '_method' => 'GET',
        ]
    )
);

$routes->add(
    RouterUrls::MainApprove,
    new Route(
        $showcaseObject->selfPhpScript . '/approve',
        [
            '_controller' => function (): string {
                return (new MyShowcase\Controllers\Entries())->approveEntries();
            },
        ],
        [
            '_method' => 'POST',
        ]
    )
);

$routes->add(
    RouterUrls::MainUnapprove,
    new Route(
        $showcaseObject->selfPhpScript . '/unapprove',
        [
            '_controller' => function (): string {
                return (new MyShowcase\Controllers\Entries())->unapproveEntries();
            },
        ],
        [
            '_method' => 'POST',
        ]
    )
);

$routes->add(
    RouterUrls::MainSoftDelete,
    new Route(
        $showcaseObject->selfPhpScript . '/soft_delete',
        [
            '_controller' => function (): string {
                return (new MyShowcase\Controllers\Entries())->softDeleteEntries();
            },
        ],
        [
            '_method' => 'POST',
        ]
    )
);

$routes->add(
    RouterUrls::MainRestore,
    new Route(
        $showcaseObject->selfPhpScript . '/restore',
        [
            '_controller' => function (): string {
                return (new MyShowcase\Controllers\Entries())->restoreEntries();
            },
        ],
        [
            '_method' => 'POST',
        ]
    )
);

$routes->add(
    RouterUrls::MainDelete,
    new Route(
        $showcaseObject->selfPhpScript . '/delete',
        [
            '_controller' => function (): string {
                return (new MyShowcase\Controllers\Entries())->deleteEntries();
            },
        ],
        [
            '_method' => 'POST',
        ]
    )
);

$routes->add(
    RouterUrls::EntryCreate,
    new Route(
        $showcaseObject->selfPhpScript . '/create',
        [
            '_controller' => function (): string {
                return (new MyShowcase\Controllers\Entries())->createEntry();
            },
        ],
    )
);

$routes->add(
    RouterUrls::EntryView,
    new Route(
        $showcaseObject->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}',
        [
            '_controller' => function (array $parameters): string {
                return (new MyShowcase\Controllers\Entries())->viewEntry(...$parameters);
            },
        ],
        [
            '_method' => 'GET',
            'entry_slug' => '[a-z0-9-]+',
            'entry_slug_custom' => '[a-z0-9-]*',
        ]
    )
);

$routes->add(
    RouterUrls::EntryUpdate,
    new Route(
        $showcaseObject->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/update',
        [
            '_controller' => function (): string {
                return (new MyShowcase\Controllers\Entries())->updateEntry();
            },
        ],
        [
            'entry_slug' => '[a-z0-9-]+',
            'entry_slug_custom' => '[a-z0-9-]*',
        ]
    )
);

$routes->add(
    RouterUrls::EntryViewPage,
    new Route(
        $showcaseObject->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/page/{page_id}',
        [
            '_controller' => function (): string {
                return (new MyShowcase\Controllers\Entries())->viewEntry();
            },
        ],
        [
            '_method' => 'GET',
            'entry_slug' => '[a-z0-9-]+',
            'entry_slug_custom' => '[a-z0-9-]*',
        ]
    )
);

$routes->add(
    RouterUrls::EntryApprove,
    new Route(
        $showcaseObject->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/approve',
        [
            '_controller' => function (): string {
                return (new MyShowcase\Controllers\Entries())->approveEntry();
            },
        ],
        [
            '_method' => 'POST',
            'entry_slug' => '[a-z0-9-]+',
            'entry_slug_custom' => '[a-z0-9-]*',
        ]
    )
);

$routes->add(
    RouterUrls::EntryUnapprove,
    new Route(
        $showcaseObject->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/unapprove',
        [
            '_controller' => function (): string {
                return (new MyShowcase\Controllers\Entries())->unapproveEntry();
            },
        ],
        [
            '_method' => 'POST',
            'entry_slug' => '[a-z0-9-]+',
            'entry_slug_custom' => '[a-z0-9-]*',
        ]
    )
);

$routes->add(
    RouterUrls::EntrySoftDelete,
    new Route(
        $showcaseObject->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/soft_delete',
        [
            '_controller' => function (): string {
                return (new MyShowcase\Controllers\Entries())->softDeleteEntry();
            },
        ],
        [
            '_method' => 'POST',
            'entry_slug' => '[a-z0-9-]+',
            'entry_slug_custom' => '[a-z0-9-]*',
        ]
    )
);

$routes->add(
    RouterUrls::EntryRestore,
    new Route(
        $showcaseObject->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/restore',
        [
            '_controller' => function (): string {
                return (new MyShowcase\Controllers\Entries())->restoreEntry();
            },
        ],
        [
            '_method' => 'POST',
            'entry_slug' => '[a-z0-9-]+',
            'entry_slug_custom' => '[a-z0-9-]*',
        ]
    )
);

$routes->add(
    RouterUrls::EntryDelete,
    new Route(
        $showcaseObject->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/delete',
        [
            '_controller' => function (): string {
                return (new MyShowcase\Controllers\Entries())->deleteEntry();
            },
        ],
        [
            '_method' => 'POST',
            'entry_slug' => '[a-z0-9-]+',
            'entry_slug_custom' => '[a-z0-9-]*',
        ]
    )
);

$routes->add(
    RouterUrls::Comment,
    new Route(
        $showcaseObject->selfPhpScript . '/comment/{comment_slug}',
        [
            '_controller' => function (): string {
                return (new MyShowcase\Controllers\Comments())->redirect();
            },
        ],
        [
            'comment_slug' => '[a-z0-9-]+',
        ]
    )
);

$routes->add(
    RouterUrls::CommentCreate,
    new Route(
        $showcaseObject->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/comment',
        [
            '_controller' => function (): string {
                return (new MyShowcase\Controllers\Comments())->create();
            },
        ],
        [
            'entry_slug' => '[a-z0-9-]+',
            'entry_slug_custom' => '[a-z0-9-]*',
        ]
    )
);

$routes->add(
    RouterUrls::CommentView,
    new Route(
        $showcaseObject->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/comment/{comment_slug}',
        [
            '_controller' => function (): string {
                return (new MyShowcase\Controllers\Comments())->view();
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

$routes->add(
    RouterUrls::CommentUpdate,
    new Route(
        $showcaseObject->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/comment/{comment_slug}/update',
        [
            '_controller' => function (): string {
                return (new MyShowcase\Controllers\Comments())->update();
            },
        ],
        [
            'entry_slug' => '[a-z0-9-]+',
            'entry_slug_custom' => '[a-z0-9-]*',
            'comment_slug' => '[a-z0-9-]+',
        ]
    )
);

$routes->add(
    RouterUrls::CommentApprove,
    new Route(
        $showcaseObject->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/comment/{comment_slug}/approve',
        [
            '_controller' => function (): string {
                return (new MyShowcase\Controllers\Comments())->approve();
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

$routes->add(
    RouterUrls::CommentUnapprove,
    new Route(
        $showcaseObject->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/comment/{comment_slug}/unapprove',
        [
            '_controller' => function (): string {
                return (new MyShowcase\Controllers\Comments())->unapprove();
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

$routes->add(
    RouterUrls::CommentSoftDelete,
    new Route(
        $showcaseObject->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/comment/{comment_slug}/soft_delete',
        [
            '_controller' => function (): string {
                return (new MyShowcase\Controllers\Comments())->softDelete();
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

$routes->add(
    RouterUrls::CommentRestore,
    new Route(
        $showcaseObject->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/comment/{comment_slug}/restore',
        [
            '_controller' => function (): string {
                return (new MyShowcase\Controllers\Comments())->restore();
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

$routes->add(
    RouterUrls::CommentDelete,
    new Route(
        $showcaseObject->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/comment/{comment_slug}/delete',
        [
            '_controller' => function (): string {
                return (new MyShowcase\Controllers\Comments())->delete();
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

$routes->add(
    RouterUrls::AttachmentView,
    new Route(
        $showcaseObject->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/attachment/{attachment_hash}',
        [
            '_controller' => function (array $parameters): string {
                return (new MyShowcase\Controllers\Attachments())->downloadAttachment(...$parameters);
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

$routes->add(
    RouterUrls::ThumbnailView,
    new Route(
        $showcaseObject->selfPhpScript . '/view/{entry_slug}/{entry_slug_custom}/thumbnail/{attachment_hash}',
        [
            '_controller' => function (array $parameters): string {
                $parameters['is_thumbnail'] = true;

                return (new MyShowcase\Controllers\Attachments())->viewThumbnail(...$parameters);
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

hooksRun('script_file_intermediate');

// Match the current request
$context = new RequestContext('/'); // Base URL

$context->setBaseUrl($mybb->settings['bburl']);

// Create the UrlGenerator
$showcaseObject->generator = new UrlGenerator($routes, $context);

$request = Request::createFromGlobals();

$context->fromRequest($request);

$matcher = new UrlMatcher($routes, $context);

hooksRun('script_file_end');

try {
    $parameters = $matcher->match($request->getPathInfo());

    $controller = $parameters['_controller'];

    unset($parameters['_controller'], $parameters['_route']);

    $showcaseObject->urlParamsSet($parameters);

    output_page($controller($parameters));
} catch (Exception $e) {
    error(DEBUG ? $e->getMessage() . "<pre>{$e->getTraceAsString()}</pre>" : '');
}

exit;