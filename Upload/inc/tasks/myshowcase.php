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

use MyShowcase\Service\EntryManager;

use function MyShowcase\Plugin\Functions\cacheGet;
use function MyShowcase\Plugin\Functions\showcaseGetObject;

use const MyShowcase\Plugin\Core\CACHE_TYPE_CONFIG;
use const MyShowcase\Plugin\Core\SHOWCASE_STATUS_ENABLED;

function task_myshowcase(array $taskData): array
{
    foreach (cacheGet(CACHE_TYPE_CONFIG) as $showcaseID => $showcaseData) {
        $showcasePruneTime = explode('|', $showcaseData['prune_time']);

        if ($showcasePruneTime[0] > 0 && $showcaseData['enabled'] === SHOWCASE_STATUS_ENABLED) {
            $pruneTime = (int)strtotime('-' . $showcasePruneTime[0] . ' ' . $showcasePruneTime[1], TIME_NOW);

            $showcase = showcaseGetObject($showcaseID);

            $entries_manager = new EntryManager($showcase,);

            foreach (
                $entries_manager->queryEntries(
                    ["dateline<='{$pruneTime}'", ['entry_id']]
                ) as $entryID => $entryData
            ) {
                $entries_manager->deleteEntry($entryID);
            }
        }
    }

    add_task_log($taskData, 'Showcase Pruning Run');

    return $taskData;
}