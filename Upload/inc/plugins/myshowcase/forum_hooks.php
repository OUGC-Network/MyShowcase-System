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

namespace MyShowcase\Hooks\Forum;

use JetBrains\PhpStorm\NoReturn;
use MyBB;
use MyShowcase\Enum\CacheDataTypes;
use MyShowcase\Enum\Routes;
use MyShowcase\Service\CacheManager;
use MyShowcase\Showcase;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

use function MyShowcase\Plugin\Functions\loadLanguage;

/**
 * Add global notices for unapproved and reported showcases
 *
 */
#[NoReturn] function global_start(): void
{
    if (!defined('THIS_SCRIPT') || THIS_SCRIPT !== 'showcase.php') {
        return;
    }

    global $db, $mybb, $lang;
    global $showcase;

    $showcase = new Showcase($db, $mybb, $lang);

    if (empty($showcase->config['custom_theme_id'])) {
        return;
    }

    if (empty($showcase->mybb->user['style']) || !empty($showcase->config['custom_theme_force'])) {
        $showcase->mybb->user['style'] = (int)$showcase->config['custom_theme_id'];
    }
}

/**
 * Add global notices for unapproved and reported showcases
 *
 */
function global_intermediate(): bool
{
    global $mybb, $db, $lang;
    global $myShowcaseGlobalMessagesUnapprovedEntries, $myShowcaseGlobalMessagesReportedEntries;

    $myShowcaseGlobalMessagesUnapprovedEntries = $myShowcaseGlobalMessagesReportedEntries = '';

    return false;

    global $db, $cache;

    $cache_manager = new CacheManager($db, $cache);

    $moderatorsCache = $cache_manager->get(CacheDataTypes::Moderators);

    $unapprovedEntriesNotices = $reportedEntriesNotices = [];

    $unapprovedEntriesNotices[] = $showcaseObject->render_manager->templateGet('globalMessageUnapprovedEntries', [
        'unapprovedEntriesUrl' => $unapprovedEntriesUrl,
        'unapprovedText' => $unapprovedText,
    ]);

    foreach (
        $cache_manager->get(
            CacheDataTypes::Config
        ) as $showcaseID => $showcaseData
    ) {
        $showcaseObject = new Showcase($db, $mybb, $lang, showcase_id: $showcaseID);

        //if showcase is is_enabled...
        if ($showcaseObject->config['is_enabled']) {
            //load language if we are going to use it
            if ($showcaseObject->current_user->hasPermission(
                    UserPermissions::EntriesCanView
                ) || $showcaseObject->current_user->hasPermission(UserPermissions::CommentsCanView)) {
                loadLanguage();
            }

            //awaiting approval
            if ($showcaseObject->current_user->hasPermission(UserPermissions::EntriesCanView)) {
                /*
                 $unapprovedEntriesUrl = url(
                    \MyShowcase\Plugin\RouterUrls::Main,
                    getParams: array_merge($showcaseObject->urlParams, ['unapproved' => 1])
                )->getRelativeUrl();
                */
                $unapprovedEntriesUrl = '';

                $totalUnapprovedEntries = $showcaseObject->entries_repository->queryEntryCount(
                    ["status='" . $showcaseObject->entries_manager::STATUS_PENDING . "'"]
                );

                if ($totalUnapprovedEntries > 0) {
                    $unapprovedText = $lang->sprintf(
                        $lang->myshowcase_unapproved_count,
                        $showcaseObject->config['name'],
                        my_number_format($totalUnapprovedEntries)
                    );

                    $unapprovedEntriesNotices[] = $showcaseObject->render_manager->templateGet(
                        'globalMessageUnapprovedEntries',
                        [
                            'unapprovedEntriesUrl' => $unapprovedEntriesUrl,
                            'unapprovedText' => $unapprovedText,
                        ]
                    );
                }
            }
        }
    }

    if (!empty($unapprovedEntriesNotices)) {
        $myShowcaseGlobalMessagesUnapprovedEntries = implode('', $unapprovedEntriesNotices);
    }

    if (!empty($reportedEntriesNotices)) {
        $myShowcaseGlobalMessagesReportedEntries = implode('', $reportedEntriesNotices);
    }

    return true;
}

//build info for who's online
function fetch_wol_activity_end(array &$user_activity): array
{
    global $user, $mybb, $cache;

    //get file_name of location
    $split_loc = explode('.php', $user_activity['location']);
    if ($split_loc[0] === $user['location']) {
        $filename = '';
    } else {
        $filename = my_substr($split_loc[0], -my_strpos(strrev($split_loc[0]), '/'));
    }

    $parameters = [];

    //get query params
    if ($split_loc[1]) {
        $temp = explode('&', my_substr($split_loc[1], 1));
        foreach ($temp as $param) {
            $temp2 = explode('=', $param, 2);
            $temp2[0] = str_replace('amp;', '', $temp2[0]);
            $parameters[$temp2[0]] = $temp2[1];
        }
    }

    //get cache of configured myshowcases
    //check cache for matching file_name
    //have to do it this way since the file_name can vary for each myshowcase
    foreach (
        (new CacheManager($db, $cache))->get(
            CacheDataTypes::Config
        ) as $id => $myshowcase
    ) {
        $split_mainfile = explode('.php', $myshowcase['route_identifier']);
        if ($split_mainfile[0] === $filename) {
            //preload here so we don't need to get it in next function
            $user_activity['myshowcase_filename'] = $filename;
            $user_activity['myshowcase_name'] = $myshowcase['name'];
            $user_activity['myshowcase_id'] = $myshowcase['showcase_id'];
            $user_activity['myshowcase_mainfile'] = $myshowcase['route_identifier'];

            if ($parameters['action'] === 'view') {
                $user_activity['activity'] = 'myShowcaseMainTableHeadView';
                if (is_numeric($parameters['entry_id'])) {
                    $user_activity['entry_id'] = $parameters['entry_id'];
                }
            } elseif ($parameters['action'] === 'new') {
                $user_activity['activity'] = 'myShowcaseLocationNewEntry';
            } elseif ($parameters['action'] === 'attachment') {
                $user_activity['activity'] = 'myshowcase_view_attach';
                if (is_numeric($parameters['attachment_id'])) {
                    $user_activity['attachment_id'] = $parameters['attachment_id'];
                }
            } elseif ($parameters['action'] === 'edit') {
                $user_activity['activity'] = 'myshowcase_edit';
                if (is_numeric($parameters['entry_id'])) {
                    $user_activity['entry_id'] = $parameters['entry_id'];
                }
            } else {
                $user_activity['activity'] = 'myshowcase_list';
            }

            //if here, we found the lcoation, so exit loop
            continue;
        }
    }

    return $user_activity;
}

//setup friendly WOL locations 
function build_friendly_wol_location_end(array &$plugin_array): array
{
    global $db, $lang, $mybb, $_SERVER, $user;

    loadLanguage();

    //get file_name of location
    $split_loc = explode('.php', $plugin_array['user_activity']['location']);
    if ($split_loc[0] === $user['location']) {
        $filename = '';
    } else {
        $filename = my_substr($split_loc[0], -my_strpos(strrev($split_loc[0]), '/'));
    }

    /* URL Definitions */
    if ($mybb->settings['seourls'] === 'yes' || ($mybb->settings['seourls'] === 'auto' && $_SERVER['SEO_SUPPORT'] === 1)) {
        $myshowcase_name = strtolower($plugin_array['user_activity']['myshowcase_name']);

        //clean the name and make it suitable for SEO
        //cleaning borrowed from Google SEO plugin
        $pattern = '!"#$%&\'( )*+,-./:;<=>?@[\]^_`{|}~';
        $pattern = preg_replace(
            "/[\\\\\\^\\-\\[\\]\\/]/u",
            "\\\\\\0",
            $pattern
        );

        // Cut off punctuation at beginning and end.
        $myshowcase_name = preg_replace(
            "/^[$pattern]+|[$pattern]+$/u",
            '',
            strtolower($myshowcase_name)
        );

        // Replace middle punctuation with one separator.
        $myshowcase_name = preg_replace(
            "/[$pattern]+/u",
            '-',
            $myshowcase_name
        );

        $myshowcase_url = $myshowcase_name . '.html';
        $myshowcase = $myshowcase_name . '-page-{page}.html';
        $myshowcase_url_view = $myshowcase_name . '-view-{entry_id}.html';
        $myshowcase_url_new = $myshowcase_name . '-new.html';
        $myshowcase_url_view_attach = $myshowcase_name . '-attachment-{attachment_id}.html';
    } else {
        $myshowcase_url = $plugin_array['user_activity']['myshowcase_mainfile'];
        $myshowcase_url_paged = $plugin_array['user_activity']['myshowcase_mainfile'] . '?page={page}';
        $myshowcase_url_view = $plugin_array['user_activity']['myshowcase_mainfile'] . '?action=view&entry_id={entry_id}';
        $myshowcase_url_new = $plugin_array['user_activity']['myshowcase_mainfile'] . '?action=new';
        $myshowcase_url_view_attach = $plugin_array['user_activity']['myshowcase_mainfile'] . '?action=attachment&attachment_id={attachment_id}';
    }

    switch ($plugin_array['user_activity']['activity']) {
        case 'myshowcase_list':
            $plugin_array['location_name'] = $lang->sprintf(
                $lang->viewing_myshowcase_list,
                $myshowcase_url,
                $plugin_array['user_activity']['myshowcase_name']
            );
            break;

        case 'myshowcase_view':
            if (array_key_exists('entry_id', $plugin_array['user_activity'])) {
                $showcaseID = (int)$plugin_array['user_activity']['myshowcase_id'];

                $entryID = $plugin_array['user_activity']['entry_id'];

                $showcase = new Showcase($db, $mybb, $lang, showcase_id: $showcaseID);

                $showcaseObjects = $showcase->entries_repository->queryEntry(["entry_id='{$entryID}'"], ['user_id']);

                foreach ($showcaseObjects as $entryID => $entryData) {
                    $uid = $entryData['user_id'];
                    $userinfo = get_user($uid);
                }
            }
            $plugin_array['location_name'] = $lang->sprintf(
                $lang->viewing_myshowcase,
                str_replace('{entry_id}', $plugin_array['user_activity']['entry_id'], $myshowcase_url_view),
                $plugin_array['user_activity']['myshowcase_name'],
                get_profile_link($uid),
                $userinfo['username']
            );
            break;

        case 'myShowcaseLocationNewEntry':
            $plugin_array['location_name'] = $lang->sprintf(
                $lang->viewing_myshowcase_new,
                $myshowcase_url_new,
                $plugin_array['user_activity']['myshowcase_name']
            );
            break;

        case 'myshowcase_edit':
            $plugin_array['location_name'] = $lang->sprintf(
                $lang->viewing_myshowcase_edit,
                $plugin_array['user_activity']['myshowcase_name']
            );
            break;

        case 'myshowcase_view_attach':
            if (array_key_exists('attachment_id', $plugin_array['user_activity'])) {
                $plugin_array['location_name'] = $$lang->viewing_myshowcase_attach;
            }

            break;
    }

    return $plugin_array;
}

//get the myshowcase counts for users that posted in the thread. keeps
//from having to do it every post, just every page view
function showthread_start(): bool
{
    global $db, $mybb, $lang, $thread, $cache, $showcase_objects;

    //get list of is_enabled myshowcases with postbit links turned on
    $showcase_objects = [];

    foreach (
        (new CacheManager($db, $cache))->get(
            CacheDataTypes::Config
        ) as $showcase_id => $showcase_data
    ) {
        if ($showcase_data['is_enabled'] && $showcase_data['display_in_posts']) {
            $showcase_objects[$showcase_data['showcase_id']] = ['uids' => []];
        }
    }

    //if we have any myshowcases to link....
    if (!empty($showcase_objects)) {
        $threadID = (int)$thread['tid'];

        //get uids for users that posted to the thread
        $query = $db->simple_select(
            'posts',
            'uid',
            "tid='{$threadID}' AND uid>'0'",
            ['group_by' => 'uid']
        );

        $uids = [];

        while ($result = $db->fetch_array($query)) {
            $uids[$result['uid']] = 0;
        }

        $userIDs = implode("','", array_keys($uids));

        unset($query, $result);

        //get myshowcase counts for users in thread
        if (count($uids)) {
            foreach ($showcase_objects as $showcaseID) {
                $showcase = new Showcase($db, $mybb, $lang, showcase_id: $showcaseID);

                foreach (
                    $showcase->entries_repository->queryEntries(
                        ["user_id IN ('{$userIDs}')", "status='1'"],
                        ['user_id', 'COUNT(user_id) AS total_user_entries'],
                        ['group_by' => 'user_id']
                    ) as $entryFieldID => $entryFieldData
                ) {
                    $showcase_objects[$showcaseID]['uids'][$entryFieldData['user_id']] = $entryFieldData['total_user_entries'];
                }
            }
        }
    }

    return true;
}

//add myshowcase links/counts
/**
 * @throws SyntaxError
 * @throws RuntimeError
 * @throws LoaderError
 */
function postbit(array &$post): array
{
    static $cache_manager = null;

    if ($cache_manager === null) {
        global $db, $cache;

        $cache_manager = new CacheManager($db, $cache);
    }

    if (str_contains($post['message'], '[attachment=')) {
        preg_match_all(
            '#\[attachment=([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})]#si',
            $post['message'],
            $matches
        );

        if (!empty($matches[1])) {
            global $db, $mybb, $lang;

            $attachment_hashes = implode("','", array_map([$db, 'escape_string'], $matches[1]));

            foreach (
                $cache_manager->get(
                    CacheDataTypes::Config
                ) as $showcase_id => $showcaseData
            ) {
                $showcase = new Showcase($db, $mybb, $lang, showcase_id: $showcase_id);

                // todo, cache attachment objects per showcase to reduce queries
                foreach (
                    $showcase->attachments_repository->queryAttachments(
                        ["attachment_hash IN ('{$attachment_hashes}')"], // todo, status clause
                        [
                            'attachment_id',
                            'showcase_id',
                            'entry_id',
                            'comment_id',
                            'field_id',
                            'post_hash',
                            'attachment_hash',
                            'user_id',
                            'description',
                            'file_name',
                            'mime_type',
                            'file_size',
                            'attachment_name',
                            'downloads',
                            'dateline',
                            'status',
                            'thumbnail_name',
                            'thumbnail_dimensions',
                            'dimensions',
                            'edit_stamp',
                        ],
                    ) as $attachment_data
                ) {
                    $showcase->attachments_manager->setAttachment($attachment_data);
                }

                $attachment_entry_ids = implode("','", $showcase->attachments_manager->getAttachmentsEntryIDs());

                foreach (
                    $showcase->entries_repository->queryEntries(
                        ["entry_id IN ('{$attachment_entry_ids}')"], // todo, status clause
                        [
                            'entry_id',
                            'entry_slug',
                            'entry_slug_custom',
                        ],
                    ) as $entry_id => $entry_data
                ) {
                    $showcase->entries_manager->addEntry($entry_data);
                }

                foreach ($showcase->attachments_manager->getAttachments() as $attachment_data) {
                    $entry_data = $showcase->entries_manager->getEntryByID($attachment_data->entry_id);

                    $attachment_data
                        ->setExtraData('file_size', get_friendly_size($attachment_data->file_size))
                        ->setExtraData(
                            'is_image',
                            str_starts_with($attachment_data->mime_type, 'image/')
                        )
                        ->setExtraData('icon', get_attachment_icon($attachment_data->attachment_name));

                    $attachment_data->has_thumbnail = !empty($attachment_data->thumbnail_name) && $showcase->config['attachments_display_thumbnails'];

                    $attachment_data->setExtraData(
                        'url',
                        $showcase->routing->generateUrl(
                            Routes::AttachmentDownload,
                            [
                                'attachment_hash' => $attachment_data->attachment_hash,
                                'attachment_name' => $attachment_data->attachment_name,
                            ],
                        )
                    );

                    if ($attachment_data->has_thumbnail) {
                        $attachment_data->setExtraData(
                            'url_thumbnail',
                            $showcase->routing->generateUrl(
                                Routes::AttachmentThumbnail,
                                [
                                    'attachment_hash' => $attachment_data->attachment_hash,
                                    'attachment_name' => $attachment_data->attachment_name,
                                ],
                            )
                        );
                    } else {
                        $attachment_data->setExtraData('url_thumbnail', $attachment_data->getExtraData('url'));
                    }

                    $entry_data->addAttachment($attachment_data);
                }
            }

            foreach (
                $cache_manager->get(
                    CacheDataTypes::Config
                ) as $showcase_id => $showcaseData
            ) {
                $showcase = new Showcase($db, $mybb, $lang, showcase_id: $showcase_id);

                foreach ($matches[1] as $attachment_hash) {
                    $attachment_data = $showcase->attachments_manager->getAttachmentByHash($attachment_hash);

                    if (!$attachment_data ||
                        !$showcase->entries_manager->getEntries()->offsetExists($attachment_data->entry_id)) {
                        continue;
                    }

                    $entry_data = $showcase->entries_manager->getEntryByID($attachment_data->entry_id);

                    if (!$entry_data) {
                        continue;
                    }

                    $attachedThumbnail = $showcase->render_manager->templateGet(
                        'postBitAttachmentThumbnail',
                        [
                            'thumbnail' => $attachment_data,
                        ]
                    );

                    $post['message'] = str_replace(
                        "[attachment={$attachment_data->attachment_hash}]",
                        $attachedThumbnail,
                        $post['message']
                    );
                }
            }
        }
    }
    // todo, maybe integrate with link tools instead
    // todo, add setting for image attachments to use mybb->asset_url() method for urls

    return $post;

    global $mybb, $_SERVER, $lang, $showcase_objects;

    if (!empty($showcase_objects)) {
        foreach ($showcase_objects as $showcase_id => $showcase_data) {
            $showcase_data = $cache_manager->get(
                CacheDataTypes::Config
            )[$showcase_id] ?? [];

            if ($showcase_data['uids'][$post['uid']] > 0) {
                $post['user_details'] .= '<br />' . $showcase_data['name'] . ':  <a href="' . $showcase_data['showcase_url'] . $showcase_data['route_identifier'] . '?search_field=username&keywords=' . rawurlencode(
                        $post['username']
                    ) . '&exact_match=1">' . $showcase_data['uids'][$post['uid']] . '</a>';
            }
        }
    }

    return $post;
}

function report_type(): void
{
    global $db, $mybb, $lang;

    // todo, id1=showcase_id, id2=report_type, id3=comment_id/entry_id/attachment_id
    if ($mybb->get_input('type') === 'showcase_entries') {
        global $report_type, $error, $verified, $id, $id2, $id3, $checkid, $report_type_db, $button;

        loadLanguage();

        $report_type = $mybb->get_input('type');

        $entryID = $mybb->get_input('pid', MyBB::INPUT_INT);

        $showcaseID = $mybb->get_input('showcaseID', MyBB::INPUT_INT);

        $showcase = new Showcase($db, $mybb, $lang, showcase_id: $showcaseID);

        $entryData = $showcase->entries_repository->queryEntry(
            ["entry_id='{$entryID}'"],
            ['user_id'],
        );

        if (empty($entryData)) {
            global $lang;

            $error = $lang->myShowcaseReportEntryInvalid;

            return;
        }

        $verified = true;

        $id = $entryID;

        $id2 = $checkid = (int)$entryData['user_id'];

        $id3 = (int)$entryData['user_id'];

        $report_type_db = "type='{$report_type}'";

        $button = '#commentBody' . $entryID;
    }

    if ($mybb->get_input('type') === 'showcase_comments') {
        global $report_type, $error, $verified, $id, $id2, $id3, $checkid, $report_type_db, $button;

        loadLanguage();

        $report_type = $mybb->get_input('type');

        $commentID = $mybb->get_input('pid', MyBB::INPUT_INT);

        $showcaseID = $mybb->get_input('showcaseID', MyBB::INPUT_INT);

        $showcase = new Showcase($db, $mybb, $lang, showcase_id: $showcaseID);

        $commentData = $showcase->comments_repository->queryComment(["comment_id='{$commentID}'"],
            ['entry_id', 'user_id']
        );

        $entryData = $showcase->entries_repository->queryEntry(
            ["entry_id='{$commentData['entry_id']}'"],
            ['user_id'],
        );

        if (empty($commentData) || empty($entryData)) {
            global $lang;

            $error = $lang->myShowcaseReportCommentInvalid;

            return;
        }

        $verified = true;

        $id = $commentID;

        $id2 = $checkid = (int)$commentData['user_id'];

        $id3 = (int)$entryData['user_id'];

        $report_type_db = "type='{$report_type}'";

        $button = '#commentBody' . $commentID;
    }
}

function modcp_reports_report(): void
{
    global $db, $mybb, $lang;
    global $report, $usercache, $report_data;

    if ($report['type'] === 'showcase_entries') {
        loadLanguage();

        $showcaseID = (int)$report['id'];

        $entryID = (int)$report['id2'];

        $showcaseObject = new Showcase($db, $mybb, $lang, showcase_id: $showcaseID);

        $entryData = $showcaseObject->entries_repository->queryEntry(
            ["entry_id='{$entryID}'"],
            ['user_id', 'entry_slug', 'entry_slug_custom'],
        );

        $report_data['content'] = $lang->sprintf(
            $lang->myShowcaseReportEntryContent,
            $showcaseObject->routing->generateUrl(
                Routes::EntryView,
                [
                    'entry_slug' => $entryData['entry_slug'],
                    'entry_slug_custom' => $entryData['entry_slug_custom']
                ]
            ) . '#entry_id' . $entryID,
            build_profile_link(
                htmlspecialchars_uni(get_user($report['id2'])['username'] ?? ''),
                $report['id2'] ?? 0
            )
        );
    }

    if ($report['type'] === 'showcase_comments') {
        loadLanguage();

        $showcaseID = (int)$report['id'];

        $commentID = (int)$report['id2'];

        $showcaseObject = new Showcase($db, $mybb, $lang, showcase_id: $showcaseID);

        $commentData = $showcaseObject->comments_repository->queryComment(
            ["comment_id='{$commentID}'"],
            ['showcase_id', 'entry_id', 'user_id', 'comment_slug'],
        );

        $entryID = (int)$commentData['entry_id'];

        $entryData = $showcaseObject->entries_repository->queryEntry(
            ["entry_id='{$entryID}'"],
            ['user_id', 'entry_slug', 'entry_slug_custom'],
        );

        $report_data['content'] = $lang->sprintf(
            $lang->myShowcaseReportCommentContent,
            $showcaseObject->getBasicUrl(
            ) . "/view/{$entryData['entry_slug']}/{$entryData['entry_slug_custom']}/comment/{$commentData['comment_slug']}" . '#commentID' . $commentData['comment_slug'],
            build_profile_link(
                htmlspecialchars_uni(get_user($report['id2'])['username'] ?? ''),
                $report['id2'] ?? 0
            )
        );

        $report_data['content'] .= $lang->sprintf(
            $lang->myShowcaseReportCommentContentEntryUser,
            build_profile_link(
                htmlspecialchars_uni(get_user($report['id3'])['username']),
                $report['id3'] ?? 0
            )
        );
    }
}