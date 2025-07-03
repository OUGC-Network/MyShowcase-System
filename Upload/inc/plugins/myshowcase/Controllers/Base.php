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

namespace MyShowcase\Controllers;

use JetBrains\PhpStorm\NoReturn;
use MyShowcase\Plugin\RouterUrls;
use MyShowcase\System\Render;
use MyShowcase\System\Showcase;

use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\loadLanguage;
use function MyShowcase\Plugin\Functions\renderGetObject;
use function MyShowcase\Plugin\Functions\showcaseGetObjectByScriptName;
use function MyShowcase\SimpleRouter\url;

use const MyShowcase\Plugin\Core\DEBUG;
use const MyShowcase\Plugin\Core\ERROR_TYPE_NOT_CONFIGURED;
use const MyShowcase\Plugin\Core\ERROR_TYPE_NOT_INSTALLED;
use const MyShowcase\Plugin\Core\VERSION_CODE;

abstract class Base
{
    public function __construct(
        public ?Showcase &$showcaseObject = null,
        public ?Render &$renderObject = null,
    ) {
//make sure this file is current
        if (SHOWCASE_FILE_VERSION_CODE < VERSION_CODE) {
            error(
                'This file is not the same version as the MyShowcase System. Please be sure to upload and configure ALL files.'
            );
        }

        global $forumDirectoryPathTrailing;

//adjust theme settings in case this file is outside mybb_root

        if (isset($theme)) {
            global $theme;

            $theme['imgdir'] = $forumDirectoryPathTrailing . substr($theme['imgdir'], 0);

            $theme['imglangdir'] = $forumDirectoryPathTrailing . substr($theme['imglangdir'], 0);
        }

        //\MyShowcase\Plugin\Core\cacheUpdate(\MyShowcase\Plugin\Core\CACHE_TYPE_CONFIG);
//start by constructing the showcase
        $this->showcaseObject = showcaseGetObjectByScriptName(THIS_SCRIPT);

        $this->renderObject = renderGetObject($this->showcaseObject);

        if (!$this->showcaseObject->config['enabled']) {
            match ($this->showcaseObject->errorType) {
                ERROR_TYPE_NOT_INSTALLED => error(
                    'The MyShowcase System has not been installed and activated yet.'
                ),
                ERROR_TYPE_NOT_CONFIGURED => error(
                    'This file is not properly configured in the MyShowcase Admin section of the ACP'
                ),
                default => error_no_permission()
            };
        }

//try to load showcase specific language file
        loadLanguage();

        loadLanguage('myshowcase' . $showcaseObject->showcase_id, false, true);

        ini_set('display_errors', 1);

        error_reporting(E_ALL);

        return $this;
    }

    #[NoReturn] public function outputError(string $errorMessage): void
    {
    }

    #[NoReturn] public function outputSuccess(string $pageContents): void
    {
        $templatesContext = ['pageContents' => $pageContents];

        $hookArguments = [
            'templatesContext' => &$templatesContext,
        ];

        $templatesContext['mainUrl'] = url(
            RouterUrls::Main,
            getParams: $this->showcaseObject->urlParams
        )->getRelativeUrl();

        $templatesContext['pageTitle'] = $this->showcaseObject->config['name'];

        $templatesContext['errorMessages'] = empty($this->showcaseObject->errorMessages) ? '' : inline_error(
            $this->showcaseObject->errorMessages
        );

        $templatesContext['version'] = VERSION_CODE;

        if (DEBUG) {
            $templatesContext['version'] = TIME_NOW;
        }

        $templatesContext['pageMeta'] = '';

        $showcaseDescription = htmlspecialchars_uni($this->showcaseObject->config['description']);

        if ($this->showcaseObject->entryID) {
            $entryUrl = url(
                RouterUrls::EntryView,
                [
                    'entry_slug' => $this->showcaseObject->entryData['entry_slug'],
                    'entry_slug_custom' => $this->showcaseObject->entryData['entry_slug_custom']
                ]
            )->getRelativeUrl();

            $templatesContext['pageMeta'] = $this->renderObject->templateGetTwig('pageMetaCanonical', [
                'baseUrl' => $this->showcaseObject->urlBase,
                'entryUrl' => $entryUrl,
                'showcaseDescription' => $showcaseDescription,
            ]);
        }

        $hookArguments = hooksRun('controller_base_output_success', $hookArguments);

        output_page($this->renderObject->templateGetTwig('page', $templatesContext));

        exit;
    }

    #[NoReturn] public function outputErrorJson(string $errorMessage): void
    {
        global $lang;

        http_response_code(404);

        $data = [
            'error' => true,
            'message' => $errorMessage
        ];

        header("Content-type: application/json; charset={$lang->settings['charset']}");

        $data = json_encode($data);

        exit($data);
    }

    #[NoReturn] public function outputSuccessJson(array $data): void
    {
        global $lang;

        http_response_code(201);

        header("Content-type: application/json; charset={$lang->settings['charset']}");

        $data = json_encode($data);

        exit($data);
    }
}

//todo review hooks here