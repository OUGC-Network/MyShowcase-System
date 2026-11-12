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
use MyShowcase\Util\BuildShowcase;

use const MyShowcase\Plugin\Core\SHOWCASE_STATUS_ENABLED;

function task_myshowcase(array $taskData): array
{
    global $db, $mybb;

    foreach (
        (new CacheManager($db, $mybb->cache))->get(
            CacheDataTypes::Config
        ) as $showcase_id => $showcase_data
    ) {
        // "<amount>|<unit>", for example "30|days"; the setting has no column at present, so nothing is pruned.
        $showcasePruneTime = explode('|', (string)($showcase_data['prune_time'] ?? ''));

        if ((int)$showcasePruneTime[0] > 0 && !empty($showcasePruneTime[1]) &&
            $showcase_data['is_enabled'] === SHOWCASE_STATUS_ENABLED) {
            $pruneTime = (int)strtotime('-' . (int)$showcasePruneTime[0] . ' ' . $showcasePruneTime[1], TIME_NOW);

            $showcase = (new BuildShowcase())->getShowcase($showcase_id);

            // dateline is a DATETIME column; queryEntries() yields rows without keys.
            $entry_ids = array_map(
                static fn(array $entry_data): int => (int)$entry_data['entry_id'],
                iterator_to_array(
                    $showcase->context->entries_repository->queryEntries(
                        ["dateline<='" . gmdate('Y-m-d H:i:s', $pruneTime) . "'"],
                        ['entry_id']
                    ),
                    false
                )
            );

            foreach ($entry_ids as $entry_id) {
                $showcase->context->entries_manager->deleteEntry($entry_id);
            }
        }
    }

    add_task_log($taskData, 'Showcase Pruning Run');

    return $taskData;
}