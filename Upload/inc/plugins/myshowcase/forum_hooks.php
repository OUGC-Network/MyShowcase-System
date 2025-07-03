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

use MyBB;
use MyShowcase\System\ModeratorPermissions;

use function MyShowcase\Plugin\Functions\attachmentGet;
use function MyShowcase\Plugin\Functions\cacheGet;
use function MyShowcase\Plugin\Functions\commentsGet;
use function MyShowcase\Plugin\Functions\entryGetRandom;
use function MyShowcase\Plugin\Functions\loadLanguage;
use function MyShowcase\Plugin\Functions\renderGetObject;
use function MyShowcase\Plugin\Functions\entryGet;
use function MyShowcase\Plugin\Functions\showcaseGetObject;
use function MyShowcase\Plugin\Functions\showcaseGetObjectByScriptName;
use function MyShowcase\Plugin\Functions\urlHandlerGet;
use function MyShowcase\Plugin\Functions\urlHandlerSet;

use const MyShowcase\Plugin\Core\CACHE_TYPE_CONFIG;
use const MyShowcase\Plugin\Core\CACHE_TYPE_MODERATORS;

/**
 * Add global notices for unapproved and reported showcases
 *
 */
function global_start(): bool
{
    return false;
    global $templatelist;

    if (isset($templatelist)) {
        $templatelist .= ',';
    } else {
        $templatelist = '';
    }

    if (defined('THIS_SCRIPT')) {
        $templateObjects = [
            'globalMessageUnapprovedEntries',
        ];

        $showcaseObjects = cacheGet(CACHE_TYPE_CONFIG);

        foreach ($showcaseObjects as $showcaseID => $showcaseData) {
            //$showcaseObject = showcaseGetObjectByScriptName($showcaseData['script_name']);
            $showcaseObject = showcaseGetObjectByScriptName(THIS_SCRIPT);

            if (THIS_SCRIPT === 'showcase.php') {
                $templateObjects = array_merge($templateObjects, [
                    'pageEntryCommentCreateUpdateAttachmentsBox',
                    'pageEntryCommentCreateUpdateAttachmentsBoxViewLink',
                    'pageEntryCommentCreateUpdateContents',
                    'pageEntryCommentCreateUpdateContentsPreview',
                    'pageEntryCreateUpdateContents',
                    'pageEntryCreateUpdateContentsPreview',

                    'pageEntryCommentCreateUpdateAttachmentsBox',
                    'pageEntryCommentCreateUpdateAttachmentsBoxViewLink',
                    'pageEntryCommentCreateUpdateContents',
                    'pageEntryCommentCreateUpdateContentsPreview',
                    'pageEntryCreateUpdateContents',
                    'pageEntryCreateUpdateContentsPreview',
                    'pageEntryCreateUpdateDataField',
                    'pageEntryCreateUpdateDataFieldCheckBox',
                    'pageEntryCreateUpdateDataFieldCheckBoxItem',
                    'pageEntryCreateUpdateDataFieldColor',
                    'pageEntryCreateUpdateDataFieldDate',
                    'pageEntryCreateUpdateDataFieldDateTimeLocal',
                    'pageEntryCreateUpdateDataFieldDescription',
                    'pageEntryCreateUpdateDataFieldEmail',
                    'pageEntryCreateUpdateDataFieldFile',
                    'pageEntryCreateUpdateDataFieldMonth',
                    'pageEntryCreateUpdateDataFieldNumber',
                    'pageEntryCreateUpdateDataFieldPassword',
                    'pageEntryCreateUpdateDataFieldRadio',
                    'pageEntryCreateUpdateDataFieldRadioItem',
                    'pageEntryCreateUpdateDataFieldRange',
                    'pageEntryCreateUpdateDataFieldSearch',
                    'pageEntryCreateUpdateDataFieldSelect',
                    'pageEntryCreateUpdateDataFieldSelectEntries',
                    'pageEntryCreateUpdateDataFieldSelectItem',
                    'pageEntryCreateUpdateDataFieldSelectThreads',
                    'pageEntryCreateUpdateDataFieldSelectUsers',
                    'pageEntryCreateUpdateDataFieldTelephone',
                    'pageEntryCreateUpdateDataFieldText',
                    'pageEntryCreateUpdateDataFieldTextArea',
                    'pageEntryCreateUpdateDataFieldTime',
                    'pageEntryCreateUpdateDataFieldUrl',
                    'pageEntryCreateUpdateDataFieldWeek',
                    'pageEntryCreateUpdateDataTextUrl',
                    'pageMetaCanonical',
                    'pageView',
                    'pageViewCommentsComment',
                    'pageViewCommentsCommentButtonApprove',
                    'pageViewCommentsCommentButtonDelete',
                    'pageViewCommentsCommentButtonEdit',
                    'pageViewCommentsCommentButtonEmail',
                    'pageViewCommentsCommentButtonPrivateMessage',
                    'pageViewCommentsCommentButtonPurgeSpammer',
                    'pageViewCommentsCommentButtonReport',
                    'pageViewCommentsCommentButtonRestore',
                    'pageViewCommentsCommentButtonSoftDelete',
                    'pageViewCommentsCommentButtonUnapprove',
                    'pageViewCommentsCommentButtonWarn',
                    'pageViewCommentsCommentButtonWebsite',
                    'pageViewCommentsCommentDeletedBit',
                    'pageViewCommentsCommentIgnoredBit',
                    'pageViewCommentsCommentEditedBy',
                    'pageViewCommentsCommentUrl',
                    'pageViewCommentsCommentUserAvatar',
                    'pageViewCommentsCommentUserDetails',
                    'pageViewCommentsCommentUserGroupImage',
                    'pageViewCommentsCommentUserOnlineStatusAway',
                    'pageViewCommentsCommentUserOnlineStatusOffline',
                    'pageViewCommentsCommentUserOnlineStatusOnline',
                    'pageViewCommentsCommentUserReputation',
                    'pageViewCommentsCommentUserSignature',
                    'pageViewCommentsCommentUserStar',
                    'pageViewCommentsCommentUserWarningLevel',
                    'pageViewCommentsFormGuest',
                    'pageViewCommentsFormUser',
                    'pageViewCommentsNone',
                    'pageViewDataFieldCheckBox',
                    'pageViewDataFieldCheckBoxImage',
                    'pageViewDataFieldCheckBoxValue',
                    'pageViewDataFieldDataBase',
                    'pageViewDataFieldDate',
                    'pageViewDataFieldRadio',
                    'pageViewDataFieldSelect',
                    'pageViewDataFieldSelectValue',
                    'pageViewDataFieldText',
                    'pageViewDataFieldTextArea',
                    'pageViewDataFieldTextAreaValue',
                    'pageViewDataFieldTextBox',
                    'pageViewDataFieldTextValue',
                    'pageViewDataFieldUrl',
                    'pageViewEntry',
                    'pageViewEntryAttachments',
                    'pageViewEntryAttachmentsFiles',
                    'pageViewEntryAttachmentsFilesItem',
                    'pageViewEntryAttachmentsFilesItemUnapproved',
                    'pageViewEntryAttachmentsImages',
                    'pageViewEntryAttachmentsImagesItem',
                    'pageViewEntryAttachmentsThumbnails',
                    'pageViewEntryAttachmentsThumbnailsItem',
                    'pageViewEntryButtonApprove',
                    'pageViewEntryButtonDelete',
                    'pageViewEntryButtonEdit',
                    'pageViewEntryButtonEmail',
                    'pageViewEntryButtonPrivateMessage',
                    'pageViewEntryButtonPurgeSpammer',
                    'pageViewEntryButtonReport',
                    'pageViewEntryButtonRestore',
                    'pageViewEntryButtonSoftDelete',
                    'pageViewEntryButtonUnapprove',
                    'pageViewEntryButtonWarn',
                    'pageViewEntryUrl',
                    'pageViewEntryButtonWebsite',
                    'pageViewEntryDeletedBit',
                    'pageViewEntryIgnoredBit',
                    'pageViewEntryEditedBy',
                    'pageViewEntryUserAvatar',
                    'pageViewEntryUserDetails',
                    'pageViewEntryUserGroupImage',
                    'pageViewEntryUserOnlineStatusAway',
                    'pageViewEntryUserOnlineStatusOffline',
                    'pageViewEntryUserOnlineStatusOnline',
                    'pageViewEntryUserReputation',
                    'pageViewEntryUserSignature',
                    'pageViewEntryUserStar',
                    'pageViewEntryUserWarningLevel'
                ]);
            }
        }

        if (THIS_SCRIPT === 'showthread.php') {
            $templatelist .= ', ';
        }

        if (THIS_SCRIPT === 'editpost.php' || THIS_SCRIPT === 'newthread.php') {
            $templatelist .= ', ';
        }

        if (THIS_SCRIPT === 'forumdisplay.php') {
            $templatelist .= ', ';
        }

        $templatelist .= ', myShowcase_' . implode(', myShowcase_', $templateObjects);

        foreach ($showcaseObjects as $showcaseID => $showcaseData) {
            $showcaseObject = showcaseGetObjectByScriptName($showcaseData['script_name']);

            //if showcase is enabled...
            if ($showcaseObject->config['enabled']) {
                $templatelist .= ", myShowcase{$showcaseObject->showcase_id}_" . implode(
                        ", myShowcase{$showcaseObject->showcase_id}_",
                        $templateObjects
                    );
            }
        }
    }

    return true;
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

    $moderatorsCache = cacheGet(CACHE_TYPE_MODERATORS);

    $unapprovedEntriesNotices = $reportedEntriesNotices = [];

    foreach (cacheGet(CACHE_TYPE_CONFIG) as $showcaseID => $showcaseData) {
        //$showcaseObject = showcaseGetObjectByScriptName($showcaseData['script_name']);
        $showcaseObject = showcaseGetObjectByScriptName(THIS_SCRIPT);

        //if showcase is enabled...
        if ($showcaseObject->config['enabled']) {
            //load language if we are going to use it
            if ($showcaseObject->userPermissions[ModeratorPermissions::CanManageEntries] || $showcaseObject->userPermissions[ModeratorPermissions::CanManageComments]) {
                loadLanguage();
            }

            urlHandlerSet($showcaseObject->config['script_name']);

            $showcaseObject->urlSet(urlHandlerGet());

            //awaiting approval
            if ($showcaseObject->userPermissions[ModeratorPermissions::CanManageEntries]) {
                /*
                 $unapprovedEntriesUrl = url(
                    \MyShowcase\Plugin\RouterUrls::Main,
                    getParams: array_merge($showcaseObject->urlParams, ['unapproved' => 1])
                )->getRelativeUrl();
                */
                $unapprovedEntriesUrl = '';

                $totalUnapprovedEntries = $showcaseObject->entriesGetUnapprovedCount();

                if ($totalUnapprovedEntries > 0) {
                    $unapprovedText = $lang->sprintf(
                        $lang->myshowcase_unapproved_count,
                        $showcaseObject->config['name'],
                        my_number_format($totalUnapprovedEntries)
                    );

                    $renderObjects = renderGetObject($showcaseObject);

                    $unapprovedEntriesNotices[] = $renderObjects->templateGetTwig('globalMessageUnapprovedEntries', [
                        'urlBase' => $showcaseObject->urlBase,
                        'unapprovedEntriesUrl' => $unapprovedEntriesUrl,
                        'unapprovedText' => $unapprovedText,
                    ]);
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
    if ($split_loc[0] == $user['location']) {
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
    foreach (cacheGet(CACHE_TYPE_CONFIG) as $id => $myshowcase) {
        $split_mainfile = explode('.php', $myshowcase['script_name']);
        if ($split_mainfile[0] == $filename) {
            //preload here so we don't need to get it in next function
            $user_activity['myshowcase_filename'] = $filename;
            $user_activity['myshowcase_name'] = $myshowcase['name'];
            $user_activity['myshowcase_id'] = $myshowcase['showcase_id'];
            $user_activity['myshowcase_mainfile'] = $myshowcase['script_name'];

            if ($parameters['action'] == 'view') {
                $user_activity['activity'] = 'myShowcaseMainTableHeadView';
                if (is_numeric($parameters['entry_id'])) {
                    $user_activity['entry_id'] = $parameters['entry_id'];
                }
            } elseif ($parameters['action'] == 'new') {
                $user_activity['activity'] = 'myShowcaseLocationNewEntry';
            } elseif ($parameters['action'] == 'attachment') {
                $user_activity['activity'] = 'myshowcase_view_attach';
                if (is_numeric($parameters['attachment_id'])) {
                    $user_activity['attachment_id'] = $parameters['attachment_id'];
                }
            } elseif ($parameters['action'] == 'edit') {
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
    if ($split_loc[0] == $user['location']) {
        $filename = '';
    } else {
        $filename = my_substr($split_loc[0], -my_strpos(strrev($split_loc[0]), '/'));
    }

    /* URL Definitions */
    if ($mybb->settings['seourls'] == 'yes' || ($mybb->settings['seourls'] == 'auto' && $_SERVER['SEO_SUPPORT'] == 1)) {
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

                $showcaseObjects = entryGet($showcaseID, ["entry_id='{$entryID}'"], ['user_id']);

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
                $attachmentID = (int)$plugin_array['user_activity']['attachment_id'];

                $attachmentObjects = attachmentGet(["attachment_id='{$attachmentID}'"], ['user_id', 'entry_id']);

                foreach ($attachmentObjects as $attachmentID => $attachmentData) {
                    $uid = $attachmentData['user_id'];

                    $gid = $attachmentData['entry_id'];

                    $userinfo = get_user($uid);
                }
            }
            $plugin_array['location_name'] = $lang->sprintf(
                $lang->viewing_myshowcase_attach,
                str_replace(
                    '{attachment_id}',
                    $plugin_array['user_activity']['attachment_id'],
                    $myshowcase_url_view_attach
                ),
                str_replace('{entry_id}', $gid, $myshowcase_view_url ?? ''),
                $plugin_array['user_activity']['myshowcase_name'],
                get_profile_link($uid),
                $userinfo['username']
            );
            break;
    }

    return $plugin_array;
}

//get the myshowcase counts for users that posted in the thread. keeps
//from having to do it every post, just every page view
function showthread_start(): bool
{
    global $db, $mybb, $thread, $cache, $myshowcase_uids;

    //get list of enabled myshowcases with postbit links turned on
    $myshowcase_uids = [];

    $myshowcases = cacheGet(CACHE_TYPE_CONFIG);
    foreach ($myshowcases as $id => $myshowcase) {
        if ($myshowcase['enabled'] && $myshowcase['display_in_posts']) {
            $myshowcase_uids[$myshowcase['showcase_id']]['name'] = $myshowcase['name'];
            $myshowcase_uids[$myshowcase['showcase_id']]['script_name'] = $myshowcase['script_name'];
            $myshowcase_uids[$myshowcase['showcase_id']]['relative_path'] = $myshowcase['relative_path'];
        }
    }

    //if we have any myshowcases to link....
    if (count($myshowcase_uids) > 0) {
        $gidlist = implode(',', array_keys($myshowcase_uids));

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
            foreach ($myshowcase_uids as $gid => $data) {
                $entryFieldObjects = entryGet(
                    $gid,
                    ["user_id IN ('{$userIDs}')", "approved='1'"],
                    ['user_id', 'COUNT(user_id) AS total'],
                    ['group_by' => 'user_id']
                );

                foreach ($entryFieldObjects as $entryFieldID => $entryFieldData) {
                    $myshowcase_uids[$gid]['uids'][$entryFieldData['user_id']] = $entryFieldData['total'];
                }
            }
        }
        unset($query, $result);
    }

    return true;
}

//add myshowcase links/counts
function postbit(array &$post): array
{
    global $mybb, $_SERVER, $lang, $myshowcase_uids;

    if (count($myshowcase_uids) > 0) {
        foreach ($myshowcase_uids as $myshowcase => $data) {
            $showcase_name = $data['name'];
            $showcase_file = $data['script_name'];
            $showcase_fldr = $data['relative_path'];

            /* URL Definitions */
            if ($mybb->settings['seourls'] == 'yes' || ($mybb->settings['seourls'] == 'auto' && $_SERVER['SEO_SUPPORT'] == 1)) {
                $showcase_file = strtolower($data['name']) . '.html';
            } else {
                $showcase_file = $data['script_name'];
            }

            if ($data['uids'][$post['uid']] > 0) {
                $post['user_details'] .= '<br />' . $showcase_name . ':  <a href="' . $showcase_fldr . $showcase_file . '?search_field=username&keywords=' . rawurlencode(
                        $post['username']
                    ) . '&exact_match=1">' . $data['uids'][$post['uid']] . '</a>';
            }
        }
    }

    return $post;
}

//function to pull a random entry from a random showcase (if enabled)
function portal_start(): bool
{
    global $db, $lang, $mybb, $cache, $templates, $portal_rand_showcase;

    //if user is guest or no showcases set to show on portal output something else?
    /*
    if($currentUserID == 0)
    {
        //add code here to display something for guests
    }
    else
    */
    {
        $portal_rand_showcase = entryGetRandom();
        if (!$portal_rand_showcase) {
        }
    }

    return true;
}

function report_type(): void
{
    global $mybb;

    // todo, id1=showcase_id, id2=report_type, id3=comment_id/entry_id/attachment_id
    if ($mybb->get_input('type') === 'showcase_entries') {
        global $report_type, $error, $verified, $id, $id2, $id3, $checkid, $report_type_db, $button;

        loadLanguage();

        $report_type = $mybb->get_input('type');

        $entryID = $mybb->get_input('pid', MyBB::INPUT_INT);

        $showcaseID = $mybb->get_input('showcaseID', MyBB::INPUT_INT);

        $entryData = entryGet(
            $showcaseID,
            ["entry_id='{$entryID}'"],
            ['user_id'],
            ['limit' => 1]
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

        $commentData = commentsGet(
            ["comment_id='{$commentID}'"],
            ['entry_id', 'user_id'],
            ['limit' => 1]
        );

        $entryData = entryGet(
            $showcaseID,
            ["entry_id='{$commentData['entry_id']}'"],
            ['user_id'],
            ['limit' => 1]
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
    global $mybb, $lang;
    global $report, $usercache, $report_data;

    if ($report['type'] === 'showcase_entries') {
        loadLanguage();

        $entryID = (int)$report['id'];

        $entryData = entryGet(
            $showcaseID,
            ["entry_id='{$entryID}'"],
            ['showcase_id', 'user_id', 'entry_slug', 'entry_slug_custom'],
            ['limit' => 1]
        );

        $showcaseID = (int)$entryData['showcase_id'];

        $showcaseObject = showcaseGetObject($showcaseID);

        $report_data['content'] = $lang->sprintf(
            $lang->myShowcaseReportEntryContent,
            $showcaseObject->urlBuild(
                $showcaseObject->urlViewEntry,
                $entryData['entry_slug'],
                $entryData['entry_slug_custom']
            ) . '#entryID' . $entryID,
            build_profile_link(
                htmlspecialchars_uni(get_user($report['id2'])['username'] ?? ''),
                $report['id2'] ?? 0
            )
        );
    }

    if ($report['type'] === 'showcase_comments') {
        loadLanguage();

        $commentID = (int)$report['id'];

        $commentData = commentsGet(
            ["comment_id='{$commentID}'"],
            ['showcase_id', 'entry_id', 'user_id', 'comment_slug'],
            ['limit' => 1]
        );

        $showcaseID = (int)$commentData['showcase_id'];

        $entryID = (int)$commentData['entry_id'];

        $entryData = entryGet(
            $showcaseID,
            ["entry_id='{$entryID}'"],
            ['user_id', 'entry_slug', 'entry_slug_custom'],
            ['limit' => 1]
        );

        $showcaseObject = showcaseGetObject($showcaseID);

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