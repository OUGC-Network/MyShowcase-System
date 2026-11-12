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

    $userIDs = implode("','", array_map('intval', $userIDs));

    foreach (showcaseGet() as $showcase_id => $showcase_data) {
        $showcase = (new BuildShowcase())->getShowcase($showcase_id);

        // queryEntries() yields rows without keys.
        $entry_ids = array_map(
            static fn(array $entry_data): int => (int)$entry_data['entry_id'],
            iterator_to_array(
                $showcase->context->entries_repository->queryEntries(["user_id IN ('{$userIDs}')"], ['entry_id']),
                false
            )
        );

        foreach ($entry_ids as $entry_id) {
            $showcase->context->entries_manager->deleteEntry($entry_id);
        }
    }

    return $dataHandler;
}