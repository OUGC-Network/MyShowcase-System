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

use MyShowcase\Enum\ModeratorPermissions;
use MyShowcase\Enum\UserPermissions;
use MyShowcase\Routing\Routing;
use MyShowcase\System\Showcase;

use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\loadLanguage;
use function MyShowcase\Plugin\Functions\showcaseGetObjectByScriptName;

use const MyShowcase\Plugin\Core\ERROR_TYPE_NOT_CONFIGURED;
use const MyShowcase\Plugin\Core\ERROR_TYPE_NOT_INSTALLED;
use const MyShowcase\Plugin\Core\VERSION_CODE;

abstract class Base
{
    public const NO_PERMISSION = 0;

    public const HAS_PERMISSION = 1;

    public const INVALID_ENTRY = 3;

    public const INVALID_COMMENT = 4;

    public const INVALID_ATTACHMENT = 5;

    private array $entry_data = [];

    private array $comment_data = [];
    private int $entry_id = 0;

    public function __construct(
        protected readonly Routing $routing,
        public ?Showcase &$showcase = null,
    ) {
//make sure this file is current
        if (SHOWCASE_FILE_VERSION_CODE < VERSION_CODE) {
            error(
                'This file is not the same version as the MyShowcase System. Please be sure to upload and configure ALL files.'
            );
        }

        global $forumDirectoryPathTrailing;

        //\MyShowcase\Plugin\Core\cacheUpdate(\MyShowcase\Plugin\Core\CACHE_TYPE_CONFIG);
//start by constructing the showcase
        $this->showcase = showcaseGetObjectByScriptName(THIS_SCRIPT);

        if (!$this->showcase->config['enabled']) {
            match ($this->showcase->errorType) {
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

        loadLanguage('myshowcase' . $showcase->showcase_id, false, true);

        ini_set('display_errors', 1);

        error_reporting(E_ALL);
    }

    public function verifyPermission(
        ?string $entry_slug = null,
        ?string $comment_slug = null,
    ): int {
        if (!$this->showcase->user_permissions[UserPermissions::CanView]) {
            return self::NO_PERMISSION;
        }

        global $db;

        if (!(
            $this->showcase->user_permissions[UserPermissions::CanViewEntries] ||
            $this->showcase->user_permissions[ModeratorPermissions::CanManageEntries]
        )) {
            return self::NO_PERMISSION;
        }

        if ($entry_slug !== null) {
            $entry_data = $this->showcase->entries_repository->queryEntry(
                ["entry_slug='{$db->escape_string($entry_slug)}'"],
                ['entry_id', 'entry_slug', 'entry_slug_custom', 'status', 'user_id'],
            );
        }

        if ($comment_slug !== null) {
            if (!$this->showcase->config['comments_allow'] ||
                !(
                    $this->showcase->user_permissions[UserPermissions::CanViewComments] ||
                    $this->showcase->user_permissions[ModeratorPermissions::CanManageComments]
                )) {
                return self::NO_PERMISSION;
            }

            $comment_data = $this->showcase->comments_repository->queryComment(
                ["comment_slug='{$db->escape_string($comment_slug)}'"],
                ['entry_id', 'status', 'user_id', 'dateline'],
            );

            if (empty($comment_data)) {
                return self::INVALID_COMMENT;
            }

            if (empty($entry_data)) {
                $entry_data = $this->showcase->entries_repository->queryEntry(
                    ["entry_id='{$db->escape_string($comment_data['entry_id'])}'"],
                    ['entry_id', 'entry_slug', 'entry_slug_custom', 'status', 'user_id'],
                );
            }
        }

        if (empty($entry_data)) {
            return self::INVALID_ENTRY;
        }

        if (
            (int)$entry_data['status'] !== $this->showcase->entries_manager::STATUS_VISIBLE &&
            ((
                    (int)$entry_data['status'] === $this->showcase->entries_manager::STATUS_PENDING &&
                    $this->showcase->current_user_id !== (int)$entry_data['user_id']
                ) ||
                (
                    (int)$entry_data['status'] === $this->showcase->entries_manager::STATUS_DELETED &&
                    !$this->showcase->user_permissions[ModeratorPermissions::CanManageEntries]
                ))) {
            return self::INVALID_ENTRY;
        }

        if ($comment_slug !== null) {
            if (
                (int)$comment_data['status'] !== $this->showcase->comments_manager::STATUS_VISIBLE &&
                ((
                        (int)$comment_data['status'] === $this->showcase->comments_manager::STATUS_PENDING &&
                        $this->showcase->current_user_id !== (int)$comment_data['user_id']
                    ) ||
                    (
                        (int)$comment_data['status'] === $this->showcase->comments_manager::STATUS_DELETED &&
                        !$this->showcase->user_permissions[ModeratorPermissions::CanManageComments]
                    ))) {
                return self::INVALID_COMMENT;
            }
        }

        $this->showcase->addEntry($entry_data);

        $this->showcase->entry_data = $entry_data;

        $this->entry_id = (int)$entry_data['entry_id'];

        $this->entry_data = $entry_data;

        $this->comment_data = $comment_data;

        hooksRun('controller_comments_verify_permissions_end', this);

        return self::HAS_PERMISSION;
    }
}