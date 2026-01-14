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

use MyShowcase\Enum\CacheDataTypes;
use MyShowcase\Service\CacheManager;
use myshowcase\src\Showcase;

use const MyShowcase\Plugin\Core\SHOWCASE_STATUS_ENABLED;

function task_myshowcase(array $taskData): array
{
    global $db, $mybb, $lang;

    foreach (
        (new CacheManager($db, $mybb->cache))->get(
            CacheDataTypes::Config
        ) as $showcaseID => $showcaseData
    ) {
        $showcasePruneTime = explode('|', $showcaseData['prune_time']);

        if ($showcasePruneTime[0] > 0 && $showcaseData['is_enabled'] === SHOWCASE_STATUS_ENABLED) {
            $pruneTime = (int)strtotime('-' . $showcasePruneTime[0] . ' ' . $showcasePruneTime[1], TIME_NOW);

            $showcase = new Showcase($db, $mybb, $lang, showcase_id: $showcaseID);

            foreach (
                $showcase->entries_repository->queryEntries(
                    ["dateline<='{$pruneTime}'", ['entry_id']]
                ) as $entryID => $entryData
            ) {
                $showcase->entries_repository->deleteEntry($entryID);
            }
        }
    }

    add_task_log($taskData, 'Showcase Pruning Run');

    return $taskData;
}