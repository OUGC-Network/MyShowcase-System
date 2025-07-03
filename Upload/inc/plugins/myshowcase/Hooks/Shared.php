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

use UserDataHandler;

use function MyShowcase\Plugin\Functions\entryDelete;
use function MyShowcase\Plugin\Functions\entryGet;
use function MyShowcase\Plugin\Functions\showcaseGet;

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
        foreach ($userIDs as $userID) {
            foreach (entryGet($showcaseID, ["user_id={$userID}"]) as $entryID => $entryData) {
                entryDelete($showcaseID, $entryID);
            }
        }
    }

    return $dataHandler;
}