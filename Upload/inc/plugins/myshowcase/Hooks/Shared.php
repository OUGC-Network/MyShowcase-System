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

use MyShowcase\Service\EntryManager;
use UserDataHandler;

use function MyShowcase\Plugin\Functions\showcaseGet;
use function MyShowcase\Plugin\Functions\showcaseGetObject;

function datahandler_user_delete_content(UserDataHandler &$dataHandler): UserDataHandler
{
    if (is_array($dataHandler->delete_uids)) {
        $userIDs = $dataHandler->delete_uids;
    } else {
        $userIDs = explode(',', (string)$dataHandler->delete_uids);
    }

    if (!$userIDs) {
        return $dataHandler;
    }

    foreach (showcaseGet() as $showcaseID => $showcaseData) {
        $showcase = showcaseGetObject($showcaseID);

        $entries_manager = new EntryManager($showcase);

        foreach ($userIDs as $userID) {
            foreach ($entries_manager->queryEntries(["user_id={$userID}"], ['entry_id']) as $entryID => $entryData) {
                $entries_manager->deleteEntry($entryID);
            }
        }
    }

    return $dataHandler;
}