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

namespace MyShowcase\Hooks\Shared;

use MyShowcase\Util\BuildShowcase;
use UserDataHandler;

use function MyShowcase\Plugin\Functions\showcaseGet;

function datahandler_user_delete_content(UserDataHandler &$dataHandler): UserDataHandler
{
    $delete_uids = $dataHandler->delete_uids;

    if (is_array($delete_uids)) {
        $userIDs = $delete_uids;
    } else {
        $userIDs = explode(',', is_string($delete_uids) ? $delete_uids : '');
    }

    if (!$userIDs) {
        return $dataHandler;
    }

    foreach (showcaseGet() as $showcase_id => $showcase_data) {
        $showcase = (new BuildShowcase())->getShowcase($showcase_id);

        $entries_repository = $showcase->context->entries_repository;

        foreach ($userIDs as $userID) {
            foreach (
                $entries_repository->queryEntries(["user_id={$userID}"], ['entry_id']
                ) as $entryID => $entryData
            ) {
                $entries_repository->deleteEntry($entryID);
            }
        }
    }

    return $dataHandler;
}