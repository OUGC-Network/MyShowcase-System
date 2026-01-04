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

use captcha;
use MyBB;
use JetBrains\PhpStorm\NoReturn;
use MyShowcase\Plugin\RouterUrls;
use MyShowcase\System\FieldDefaultTypes;
use MyShowcase\System\FieldHtmlTypes;
use MyShowcase\System\ModeratorPermissions;
use MyShowcase\System\UserPermissions;
use Symfony\Component\Routing\Route;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

use function MyShowcase\Plugin\Functions\attachmentGet;
use function MyShowcase\Plugin\Functions\attachmentUpload;
use function MyShowcase\Plugin\Functions\cacheGet;
use function MyShowcase\Plugin\Functions\cleanSlug;
use function MyShowcase\Plugin\Functions\commentsGet;
use function MyShowcase\Plugin\Functions\fieldDataGet;
use function MyShowcase\Plugin\Functions\fieldGetObject;
use function MyShowcase\Plugin\Functions\dataHandlerGetObject;
use function MyShowcase\Plugin\Functions\entryGet;
use function MyShowcase\Plugin\Functions\formatField;
use function MyShowcase\Plugin\Functions\generateUUIDv4;
use function MyShowcase\Plugin\Functions\getSetting;
use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\moderatorGet;
use function MyShowcase\Plugin\Functions\postParser;

use function redirect;

use const MyShowcase\Plugin\Core\ORDER_DIRECTION_DESCENDING;
use const MyShowcase\Plugin\Core\CACHE_TYPE_ATTACHMENT_TYPES;
use const MyShowcase\Plugin\Core\HTTP_CODE_PERMANENT_REDIRECT;
use const MyShowcase\Plugin\Core\COMMENT_STATUS_PENDING_APPROVAL;
use const MyShowcase\Plugin\Core\COMMENT_STATUS_SOFT_DELETED;
use const MyShowcase\Plugin\Core\COMMENT_STATUS_VISIBLE;
use const MyShowcase\Plugin\Core\DATA_HANDLER_METHOD_UPDATE;
use const MyShowcase\Plugin\Core\DATA_TABLE_STRUCTURE;
use const MyShowcase\Plugin\Core\ENTRY_STATUS_PENDING_APPROVAL;
use const MyShowcase\Plugin\Core\ENTRY_STATUS_SOFT_DELETED;
use const MyShowcase\Plugin\Core\ENTRY_STATUS_VISIBLE;
use const MyShowcase\Plugin\Core\ORDER_DIRECTION_ASCENDING;

class Entries extends Base
{
    public const NO_PERMISSION = 1;

    public const INVALID_ENTRY = 2;

    public const HAS_PERMISSION = 3;

    public const STATUS_PENDING_APPROVAL = 0;

    public const STATUS_VISIBLE = 1;

    public const STATUS_SOFT_DELETE = 2;

    public const STATUS_DELETE = 3;

    public function __construct()
    {
        parent::__construct();

        if (!$this->showcaseObject->user_permissions[UserPermissions::CanView]) {
            error_no_permission();
        }
    }

    public int $entryID = 0;

    public array $entryData = [];

    public function verifyPermission(string $entrySlug, array $entryFields = []): int
    {
        global $db;

        if (!(
            $this->showcaseObject->user_permissions[UserPermissions::CanViewEntries] ||
            $this->showcaseObject->user_permissions[ModeratorPermissions::CanManageEntries]
        )) {
            return self::NO_PERMISSION;
        }

        global $mybb;

        $current_user_id = (int)$mybb->user['uid'];

        $entryData = entryGet(
            $this->showcaseObject->showcase_id,
            ["entry_slug='{$db->escape_string($entrySlug)}'"],
            array_merge(['entry_id', 'status', 'user_id'], $entryFields),
            ['limit' => 1]
        );

        if (empty($entryData)) {
            return self::INVALID_ENTRY;
        }

        $entryStatus = (int)$entryData['status'];

        $entryUserID = (int)$entryData['user_id'];

        if ($entryStatus === ENTRY_STATUS_PENDING_APPROVAL && $current_user_id !== $entryUserID ||
            $entryStatus === ENTRY_STATUS_SOFT_DELETED && !$this->showcaseObject->user_permissions[ModeratorPermissions::CanManageEntries]) {
            return self::INVALID_ENTRY;
        }

        $this->entry_id = (int)$entryData['entry_id'];

        $this->entryData = $entryData;

        return self::HAS_PERMISSION;
    }

    #[NoReturn] public function redirect(string $entrySlug): void
    {
        switch ($this->verifyPermission($entrySlug)) {
            case self::NO_PERMISSION:
                error_no_permission();

                break;
            case self::INVALID_ENTRY:
                global $lang;

                error($lang->myShowcaseReportErrorInvalidEntry);
        }

        $entryUrl = $this->showcaseObject->urlGetEntry($entrySlug);

        redirect($entryUrl, HTTP_CODE_PERMANENT_REDIRECT);

        exit;
    }

    /**
     * @throws RuntimeError
     * @throws LoaderError
     * @throws SyntaxError
     */
    #[NoReturn] public function mainView(
        int $userID = 0,
        int $limit = 0,
        int $limitStart = 0,
        string $groupBy = '',
        string $orderBy = 'user_id',
        string $orderDirection = ORDER_DIRECTION_ASCENDING,
        string $filterField = '',
        mixed $filterValue = '',
        array $whereClauses = [],
    ): string {
        global $lang, $mybb, $db;

        if ($mybb->get_input('action') === 'inline_moderation') {
            switch ($mybb->get_input('moderate')) {
                case 'open':
                    _dump($mybb->input);
                    break;
                case 'close':
                    break;
                case 'stick':
                    break;
                case 'unstick':
                    break;
                case 'approve':
                    break;
                case 'unapprove':
                    break;
                case 'soft_delete':
                    break;
                case 'restore':
                    break;
                case 'delete':
                    break;
            }
        }

        $this->renderObject->current_page = $mybb->get_input('page', MyBB::INPUT_INT);

        $current_user_id = (int)$mybb->user['uid'];

        if ($this->showcaseObject->config['entries_per_page'] < 1) {
            $this->showcaseObject->config['entries_per_page'] = 1;
        }

        if ($userID) {
            $this->showcaseObject->urlParamsAppend(['user_id' => $userID]);
        }

        if ($mybb->get_input('sort')) {
            $this->showcaseObject->urlParamsAppend(['sort' => $mybb->get_input('sort')]);
        }

        if ($mybb->get_input('direction')) {
            $this->showcaseObject->urlParamsAppend(['direction' => $mybb->get_input('direction')]);
        }

        if ($mybb->get_input('days')) {
            $this->showcaseObject->urlParamsAppend(['days' => $mybb->get_input('days')]);
        }

        // First, the date cut for the entries.
        if (empty($mybb->input['days']) && !empty($mybb->user['daysprune'])) {
            $days_cut = $mybb->user['daysprune'];
        } else {
            $days_cut = $mybb->get_input('days', MyBB::INPUT_INT);
        }

        if ($days_cut > 0) {
            $this->showcaseObject->urlParamsAppend(['days' => $days_cut]);

            $whereClauses[] = "(entryData.dateline>='" . (TIME_NOW - ($days_cut * 86400)) . "' OR entryData.is_sticky='1') ";
            // todo, add setting to force showing featured entries regardless of date filter
        }

        $templatesContext = [
            'meta' => [
                'canonical' => $this->showcaseObject->urlGenerate(RouterUrls::Main),
                'page' => [
                    [
                        'property' => 'og:description',
                        'content' => $this->showcaseObject->config['description'],
                    ],
                    [
                        'property' => 'og:title',
                        'content' => $this->showcaseObject->config['name'],
                    ],
                ],
            ],
            'moderators' => [],
            'users_browsing' => [],
            'users_browsing_counter' => [
                'guests' => 0,
                'members' => 0,
                'invisible' => 0,
            ],
            'sorting' => [
                'field' => $this->showcaseObject->sort_field,
                'direction' => $this->showcaseObject->sort_direction,
            ],
            'inline_selected_count' => 0,
            'url' => [
                'sort' => [
                    'subject' => $this->showcaseObject->urlGenerate(
                        RouterUrls::Main,
                        array_merge(
                            $this->showcaseObject->urlParams,
                            [
                                'sort' => 'subject',
                                'direction' => $this->showcaseObject->sort_direction === ORDER_DIRECTION_ASCENDING ? ORDER_DIRECTION_DESCENDING : ORDER_DIRECTION_ASCENDING
                            ]
                        )
                    ),
                    'username' => $this->showcaseObject->urlGenerate(
                        RouterUrls::Main,
                        array_merge($this->showcaseObject->urlParams, [
                            'sort' => 'username',
                            'direction' => $this->showcaseObject->sort_direction === ORDER_DIRECTION_ASCENDING ? ORDER_DIRECTION_DESCENDING : ORDER_DIRECTION_ASCENDING
                        ])
                    ),
                    'comments' => $this->showcaseObject->urlGenerate(
                        RouterUrls::Main,
                        array_merge($this->showcaseObject->urlParams, [
                            'sort' => 'comments',
                            'direction' => $this->showcaseObject->sort_direction === ORDER_DIRECTION_ASCENDING ? ORDER_DIRECTION_DESCENDING : ORDER_DIRECTION_ASCENDING
                        ])
                    ),
                    'last_comment' => $this->showcaseObject->urlGenerate(
                        RouterUrls::Main,
                        array_merge($this->showcaseObject->urlParams, [
                            'sort' => 'dateline',
                            'direction' => $this->showcaseObject->sort_direction === ORDER_DIRECTION_ASCENDING ? ORDER_DIRECTION_DESCENDING : ORDER_DIRECTION_ASCENDING
                        ])
                    ),
                ],
            ],
            'entry_normal_objects' => [],
            'entry_sticky_objects' => [],
        ];


        // Fetch each moderator from the cache and format it, appending it to the list
        foreach (
            moderatorGet(["showcase_id='{$this->showcaseObject->showcase_id}'"], ['user_id', 'is_group']) as $moderator
        ) {
            if ($moderator['is_group'] && $group_data = $mybb->cache->read('usergroups')[$moderator['user_id']]) {
                $templatesContext['moderators']['groups'][$moderator['user_id']] = [
                    'title' => $group_data['title'],
                    'group_id' => $moderator['user_id'],
                ];
            } elseif ($user_data = get_user($moderator['user_id'])) {
                $templatesContext['moderators']['users'][$moderator['user_id']] = [
                    'user_id' => $moderator['user_id'],
                    'username' => format_name(
                        htmlspecialchars_uni($user_data['username']),
                        $user_data['usergroup'],
                        $user_data['displaygroup']
                    ),
                ];
            }
        }

        // Get the users browsing this showcase
        if (!empty($mybb->settings['browsingthisforum'])) {
            $time_cut = TIME_NOW - $mybb->settings['wolcutoff'];

            $query = $db->simple_select(
                'sessions',
                'COUNT(DISTINCT ip) AS guest_count',
                "uid = 0 AND time > $time_cut AND location LIKE '%{$this->showcaseObject->selfPhpScript}%' AND nopermission != 1"
            );

            $templatesContext['users_browsing_counter']['guests'] = $db->fetch_field($query, 'guest_count');

            $query = $db->simple_select(
                "sessions s LEFT JOIN {$db->table_prefix}users u ON (s.uid=u.uid)",
                's.ip, s.uid, u.username, u.avatar, s.time, u.invisible, u.usergroup, u.usergroup, u.displaygroup',
                "s.uid != 0 AND s.time > $time_cut AND location LIKE '%{$this->showcaseObject->selfPhpScript}%' AND nopermission != 1",
                ['order_by' => 'u.username ASC, s.time DESC']
            );

            while ($user_data = $db->fetch_array($query)) {
                if (empty($templatesContext['users_browsing'][$user_data['uid']]) ||
                    $templatesContext['users_browsing'][$user_data['uid']]['time'] < $user_data['time']) {
                    ++$templatesContext['users_browsing_counter']['members'];

                    if (!empty($user_data['invisible'])) {
                        ++$templatesContext['users_browsing_counter']['invisible'];
                    }

                    if (empty($user_data['invisible']) ||
                        !empty($mybb->usergroup['canviewwolinvis']) ||
                        (int)$user_data['uid'] === $current_user_id) {
                        $templatesContext['users_browsing'][$user_data['uid']] = $user_data;
                    }
                }
            }

            // The user was counted as invisible user --> correct the invisible_members_count
            if ($mybb->user['invisible'] == 1) {
                $templatesContext['users_browsing_counter']['invisible'] -= 1;
            }
        }

        $hookArguments = [
            'templatesContext' => &$templatesContext,
        ];

        $hookArguments = hooksRun('output_main_start', $hookArguments);

        /*
                switch ($this->showcaseObject->config['filter_force_field']) {
                    case FILTER_TYPE_USER_ID:
                        $displayCreateButton = $this->showcaseObject->user_permissions[UserPermissions::CanCreateEntries] && $userID === $current_user_id;

                        $whereClauses[] = "user_id='{$userID}'";

                        //$this->showcaseObject->urlParams['user_id'] = $userID;

                        $userData = get_user($userID);

                        if (empty($userData['uid']) || empty($mybb->usergroup['canviewprofiles'])) {
                            error_no_permission();
                        }

                        $lang->load('member');

                        $userName = htmlspecialchars_uni($userData['username']);

                        add_breadcrumb(
                            $lang->sprintf($lang->nav_profile, $userName),
                            $mybb->settings['bburl'] . '/' . get_profile_link($userData['uid'])
                        );

                        break;
                }
        */
        $mainUrl = $this->showcaseObject->urlGenerate(
            RouterUrls::Main,
            $this->showcaseObject->urlParams
        );

        add_breadcrumb(
            $this->showcaseObject->config['name_friendly'],
            $mainUrl
        );

        if ($this->showcaseObject->user_permissions[UserPermissions::CanCreateEntries]) {
            $templatesContext['url_entry_create'] = $this->showcaseObject->urlGenerate(
                RouterUrls::EntryCreate,
            );
        }

        $templatesContext['fieldSetFieldsOrder'] = $this->showcaseObject->fieldSetFieldsOrder;

        $templatesContext['fieldSetCache'] = array_map(
            function (array &$fieldDefinition) use (&$templatesContext): array {
                $templatesContext['url']['sort'][$fieldDefinition['field_key']] = $this->showcaseObject->urlGenerate(
                    RouterUrls::Main,
                    array_merge($this->showcaseObject->urlParams, [
                        'sort' => $fieldDefinition['field_key'],
                        'direction' => $this->showcaseObject->sort_direction === ORDER_DIRECTION_ASCENDING ? ORDER_DIRECTION_DESCENDING : ORDER_DIRECTION_ASCENDING
                    ])
                );

                return $fieldDefinition;
            },
            array_filter($this->showcaseObject->fieldSetCache, function ($fieldDefinition): bool {
                return $fieldDefinition['display_in_create_update_page'] &&
                    is_member($fieldDefinition['allowed_groups_fill']);
            })
        );

        foreach ($this->showcaseObject->fieldSetFieldsOrder as $renderOrder => $fieldID) {
            $field_key = $this->showcaseObject->fieldSetCache[$fieldID]['field_key'];

            if (!isset($lang->{"myshowcase_fieldset_field_{$field_key}"})) {
                $lang->{"myshowcase_fieldset_field_{$field_key}"} = $field_key;
            }
        }

        //setup joins for query and build where clause based on search_field terms

        $queryTables = ['users user_data ON (user_data.uid = entryData.user_id)'];

        $queryFields = array_merge(array_map(function (string $fieldName): string {
            return 'entryData.' . $fieldName;
        }, array_keys(DATA_TABLE_STRUCTURE['myshowcase_data'])), [
            'user_data.username',
            'user_data.usergroup',
            'user_data.displaygroup'
        ]);

        $searchDone = false;

        $allowedStatuses = [ENTRY_STATUS_VISIBLE];

        if ($this->showcaseObject->user_permissions[ModeratorPermissions::CanManageEntries]) {
            $allowedStatuses[] = ENTRY_STATUS_SOFT_DELETED;
        }

        $allowedStatuses = implode("','", $allowedStatuses);

        $whereClauseStatus = ["entryData.status IN ('{$allowedStatuses}')"];

        $statusPendingApproval = ENTRY_STATUS_PENDING_APPROVAL;

        $whereClauseStatus[] = "(entryData.user_id='{$current_user_id}' AND entryData.status='{$statusPendingApproval}')";

        $whereClauseStatus = implode(' OR ', $whereClauseStatus);

        $whereClauses[] = "({$whereClauseStatus})";

        $whereClauses = array_merge($whereClauses, $this->showcaseObject->whereClauses);

        $keywords = explode(' ', $this->renderObject->keywords);

        foreach ($this->showcaseObject->fieldSetCache as $fieldID => $fieldDefinition) {
            $field_key = $fieldDefinition['field_key'];

            $htmlType = $fieldDefinition['html_type'];

            if ($htmlType === FieldHtmlTypes::Select || $htmlType === FieldHtmlTypes::Radio) {
                $queryTables[] = "myshowcase_field_data table_{$field_key} ON (table_{$field_key}.field_data_id = entryData.{$field_key} AND table_{$field_key}.field_id = '{$fieldDefinition['field_id']}')";

                $queryFields[] = "table_{$field_key}.display_style AS {$field_key}";

                if ($this->renderObject->keywords && $this->showcaseObject->searchField === $field_key) {
                    if ($this->renderObject->search_exact) {
                        $whereClauses[] = "table_{$field_key}.value='{$db->escape_string($this->renderObject->keywords)}'";
                    } else {
                        $whereClauses[] = "table_{$field_key}.value LIKE '%{$db->escape_string($this->renderObject->keywords)}%'";
                    }

                    $whereClauses[] = "table_{$field_key}.set_id='{$this->showcaseObject->config['field_set_id']}'";
                }
            } elseif ($this->showcaseObject->searchField === 'username' && !$searchDone) {
                $queryTables[] = 'users us ON (entryData.user_id = us.uid)';

                $queryFields[] = $field_key;

                if ($this->renderObject->keywords) {
                    if ($this->renderObject->search_exact) {
                        $whereClauses[] = "us.username='{$db->escape_string($this->renderObject->keywords)}'";
                    } else {
                        $whereClauses[] = "us.username LIKE '%{$db->escape_string($this->renderObject->keywords)}%'";
                    }
                }

                $searchDone = true;
            } else {
                $queryFields[] = $field_key;

                if ($this->renderObject->keywords && $this->showcaseObject->searchField === $field_key) {
                    if ($this->renderObject->search_exact) {
                        $whereClauses[] = "entryData.{$field_key}='{$db->escape_string($this->renderObject->keywords)}'";
                    } else {
                        $whereClauses[] = "entryData.{$field_key} LIKE '%{$db->escape_string($this->renderObject->keywords)}%'";
                    }
                }
            }

            if ($this->renderObject->keywords &&
                isset($this->showcaseObject->fieldSetSearchableFields[$fieldDefinition['field_key']])) {
                foreach ($keywords as $keyword) {
                    if ($this->renderObject->search_exact) {
                        $whereClauses[] = "entryData.{$field_key}='{$db->escape_string($keyword)}'";
                    } else {
                        $whereClauses[] = "entryData.{$field_key} LIKE '%{$db->escape_string($keyword)}%'";
                    }
                }
            }
        }

        $queryOptions = [
            'order_by' => "is_sticky {$this->showcaseObject->sort_direction}, entry_id",
            'order_dir' => $this->showcaseObject->sort_direction,
        ];

        if ($this->showcaseObject->sort_field !== 'dateline') {
            $queryOptions['order_by'] = "{$db->escape_string($this->showcaseObject->sort_field)} {$this->showcaseObject->sort_direction}, is_sticky {$this->showcaseObject->sort_direction}, entry_id";

            $queryOptions['order_dir'] = $this->showcaseObject->sort_direction;
        }

        $templatesContext['entries_count'] = (int)(entryGet(
            $this->showcaseObject->showcase_id,
            $whereClauses,
            ['COUNT(entryData.entry_id) AS total_entries'],
            array_merge(['limit' => 1], $queryOptions),
            $queryTables
        )['total_entries'] ?? 0);

        $hookArguments = hooksRun('output_main_intermediate', $hookArguments);

        if ($templatesContext['entries_count']) {
            $entriesPerPage = $this->showcaseObject->config['entries_per_page'];

            if ($this->renderObject->current_page > 0) {
                $pageStart = ($this->renderObject->current_page - 1) * $entriesPerPage;

                $pageTotal = $templatesContext['entries_count'] / $entriesPerPage;

                $pageTotal = ceil($pageTotal);

                if ($this->renderObject->current_page > $pageTotal) {
                    $pageStart = 0;

                    $this->renderObject->current_page = 1;
                }
            } else {
                $pageStart = 0;

                $this->renderObject->current_page = 1;
            }

            $upper = $pageStart + $entriesPerPage;

            if ($upper > $templatesContext['entries_count']) {
                $upper = $templatesContext['entries_count'];
            }

            $queryOptions['limit'] = $entriesPerPage;

            $queryOptions['limit_start'] = $pageStart;

            $templatesContext['pagination'] = multipage(
                $templatesContext['entries_count'],
                $this->showcaseObject->config['entries_per_page'],
                $this->renderObject->current_page,
                $this->showcaseObject->config['url_showcase'] . '/' . $this->showcaseObject->urlGenerate(
                    RouterUrls::Main,
                    $this->showcaseObject->urlParams
                ),
            );

            $this->showcaseObject->entriesAppend(
                entryGet(
                    $this->showcaseObject->showcase_id,
                    $whereClauses,
                    $queryFields,
                    $queryOptions,
                    $queryTables
                )
            );

            // get first attachment for each showcase on this page
            if ($this->showcaseObject->config['attachments_main_render_first']) {
                $this->showcaseObject->attachmentsAppend(
                    attachmentGet(
                        [
                            "showcase_id='{$this->showcaseObject->showcase_id}'",
                            "entry_id IN ('" . implode("','", $this->showcaseObject->entriesGetIDs()) . "')",
                            "status='1'"
                        ],
                        [
                            'entry_id',
                            'mime_type',
                            'file_size',
                            'file_name',
                            'attachment_name',
                            'downloads',
                            'dateline',
                            'thumbnail_name',
                            'attachment_hash',
                        ],
                        // todo, seems like MIN(attachment_id) as attachment_id is unnecessary
                        ['group_by' => 'entry_id']
                    )
                )
                    ->attachmentsParseObjects();
            }

            $inlineModerationCount = 0;

            $inlinecookie = 'inlinemod_forum' . $this->showcaseObject->showcase_id;

            $entry_objects = $this->showcaseObject->entriesGetObjects();

            $entry_objects = array_map(
                function (array $entryData) use (
                    &$templatesContext,
                    $inlinecookie,
                    $lang,
                ): array {
                    $entryData['unread_comments'] = false;

                    $entryData['modChecked'] = false;

                    if ($this->showcaseObject->user_permissions[ModeratorPermissions::CanManageEntries] &&
                        isset($mybb->cookies[$inlinecookie]) &&
                        my_strpos($mybb->cookies[$inlinecookie], "|{$entryData['entry_slug']}|") !== false) {
                        $entryData['modChecked'] = true;

                        ++$templatesContext['inline_selected_count'];
                    }

                    $this->showcaseObject->entryDataSet($entryData);

                    $entryStatus = (int)$entryData['status'];

                    $entryID = (int)$entryData['entry_id'];

                    $entryData['username'] ??= $lang->guest;

                    $entryData['url'] = $this->showcaseObject->urlGenerate(
                        RouterUrls::EntryView,
                        [
                            'entry_slug' => $this->showcaseObject->entry_data['entry_slug'],
                            'entry_slug_custom' => $this->showcaseObject->entry_data['entry_slug_custom']
                        ],
                        $this->showcaseObject->urlParams
                    );

                    $viewLastCommentID = 0; // todo, show last comment ID in the list view

                    $entryData['entryUrlLastComment'] = str_replace(
                        '{entry_id}',
                        (string)$entryID,
                        $this->showcaseObject->urlViewComment
                    );

                    //add bits for search_field highlighting
                    /*if ($this->renderObject->searchKeyWords) {
                        $urlBackup = urlHandlerGet();

                        urlHandlerSet($entryUrl);

                        $entryUrl = urlHandlerBuild([
                            //'search_field' => $this->showcaseObject->searchField,
                            'highlight' => urlencode($this->renderObject->searchKeyWords)
                        ]);

                        urlHandlerSet($urlBackup);
                    }*/

                    //build a link for the list view, starting with basic text

                    $entryData['attachments'] = $this->showcaseObject->attachmentsGetByEntryID($entryID);

                    $entrySubject = $entryData['fieldCache'] = [];

                    foreach ($this->showcaseObject->fieldSetCache as $fieldID => $fieldDefinition) {
                        $entryFieldText = $entryData[$fieldDefinition['field_key']] ?? '';

                        if (!$fieldDefinition['parse']) {
                            // todo, remove this legacy updating the database and updating the format field to TINYINT
                            formatField((int)$fieldDefinition['format'], $entryFieldText);
                            /*if ($fieldDefinition['html_type'] === FieldHtmlTypes::Date) {
                                if ((int)$entryFieldText === 0 || (string)$entryFieldText === '') {
                                    $entryFieldText = '';
                                } else {
                                    $entryFieldDateValue = explode('|', $entryFieldText);

                                    $entryFieldDateValue = array_map('intval', $entryFieldDateValue);

                                    if ($entryFieldDateValue[0] > 0 && $entryFieldDateValue[1] > 0 && $entryFieldDateValue[2] > 0) {
                                        $entryFieldText = my_date(
                                            $mybb->settings['dateformat'],
                                            mktime(
                                                0,
                                                0,
                                                0,
                                                $entryFieldDateValue[0],
                                                $entryFieldDateValue[1],
                                                $entryFieldDateValue[2]
                                            )
                                        );
                                    } else {
                                        $entryFieldText = [];

                                        if (!empty($entryFieldDateValue[0])) {
                                            $entryFieldText[] = $entryFieldDateValue[0];
                                        }

                                        if (!empty($entryFieldDateValue[1])) {
                                            $entryFieldText[] = $entryFieldDateValue[1];
                                        }

                                        if (!empty($entryFieldDateValue[2])) {
                                            $entryFieldText[] = $entryFieldDateValue[2];
                                        }

                                        $entryFieldText = implode('-', $entryFieldText);
                                    }
                                }
                            }*/
                        } else {
                            $entryFieldText = $this->showcaseObject->parseMessage($entryFieldText);
                        }

                        $entryData['fieldCache'][$fieldID] = $entryFieldText;

                        if ($fieldDefinition['enable_subject'] && !empty($entryFieldText)) {
                            $entrySubject[] = $entryFieldText;
                        }
                    }

                    $entryData['entrySubject'] = trim(
                        implode(' ', $entrySubject)
                    ) ?? $lang->myShowcaseMainTableHeadView;

                    if ($this->renderObject->keywords) {
                        $entryData['entrySubject'] = postParser()->highlight_message(
                            $entryData['entrySubject'],
                            ['highlight' => $this->renderObject->keywords]
                        );
                    }

                    $entryData['moderator_check'] = false;

                    if ($this->showcaseObject->user_permissions[ModeratorPermissions::CanManageEntries] &&
                        isset($mybb->cookies['inlinemod_showcase' . $this->showcaseObject->showcase_id]) &&
                        my_strpos(
                            $mybb->cookies['inlinemod_showcase' . $this->showcaseObject->showcase_id],
                            "|{$entryData['entry_id']}|"
                        ) !== false) {
                        $entryData['moderator_check'] = true;
                    }

                    if ($entryData['visible'] == ENTRY_STATUS_PENDING_APPROVAL) {
                        $entryData['alternativeBackground'] = 'trow_shaded';
                    } elseif ($entryData['visible'] == ENTRY_STATUS_SOFT_DELETED && $templatesContext['permissions']['can_manage_entries]']) {
                        $entryData['alternativeBackground'] = 'trow_shaded trow_deleted';
                    } else {
                        $entryData['alternativeBackground'] = alt_trow();
                    }

                    $comment_data = $this->showcaseObject->commentGet(
                        ["entry_id={$entryID}"],
                        ['comment_slug', 'showcase_id', 'entry_id', 'user_id', 'dateline',],
                        [
                            'limit' => 1,
                            'order_by' => 'dateline',
                            'order_dir' => ORDER_DIRECTION_DESCENDING
                        ]
                    );

                    $entryData['last_commenter_user_data'] = get_user($comment_data['user_id']);

                    $entryData['last_comment_url'] = $comment_data['comment_slug'] ? $this->showcaseObject->urlGenerate(
                        RouterUrls::Comment,
                        ['comment_slug' => $comment_data['comment_slug']]
                    ) : '';

                    $entryData['last_comment_date'] = $comment_data['dateline'] ?? 0;

                    $entryData['attachments_count'] = attachmentGet(
                        [
                            "entry_id='{$this->showcaseObject->entry_id}'",
                            "showcase_id='{$this->showcaseObject->showcase_id}'"
                        ],
                        [
                            'COUNT(attachment_id) AS attachment_count'
                        ],
                        ['limit' => 1],
                    )['attachment_count'] ?? 0;

                    $entryData['comments_by_you'] = $this->showcaseObject->commentGet(
                        [
                            "entry_id='{$this->showcaseObject->entry_id}'",
                            "user_id='{$current_user_id}'",
                        ],
                        [
                            'COUNT(comment_id) AS comments_count'
                        ],
                        ['limit' => 1],
                    )['comments_count'] ?? 0;

                    return $entryData;
                },
                $entry_objects
            );

            foreach ($entry_objects as $entry_id => $entryFieldData) {
                if (empty($entryFieldData['is_sticky'])) {
                    $templatesContext['entry_normal_objects'][$entry_id] = $entryFieldData;
                } else {
                    $templatesContext['entry_sticky_objects'][$entry_id] = $entryFieldData;
                }
            }
        }

        return $this->renderObject->templateGet(
            'pageMain',
            $templatesContext
        );
    }

    #[NoReturn] public function mainUser(
        int $userID,
        int $limit = 10,
        int $limitStart = 0,
        string $groupBy = '',
        string $orderBy = 'user_id',
        string $orderDirection = ORDER_DIRECTION_ASCENDING
    ): void {
        $this->mainView(
            $userID,
            $limit,
            $limitStart,
            $groupBy,
            $orderBy,
            $orderDirection,
            filterField: 'user_id',
            filterValue: $userID
        );
    }

    #[NoReturn] public function mainUnapproved(
        int $userID = 0,
        int $limit = 10,
        int $limitStart = 0,
        string $groupBy = '',
        string $orderBy = 'user_id',
        string $orderDirection = ORDER_DIRECTION_ASCENDING
    ): void {
        $statusUnapproved = ENTRY_STATUS_PENDING_APPROVAL;

        $this->mainView(
            $userID,
            $limit,
            $limitStart,
            $groupBy,
            $orderBy,
            $orderDirection,
            whereClauses: ["status='{$statusUnapproved}'"]
        );
    }

    /**
     * @throws SyntaxError
     * @throws RuntimeError
     * @throws LoaderError
     */
    #[NoReturn] public function createEntry(
        string $entry_slug = '',
        string $entry_slug_custom = '',
        bool $isEditPage = false,
    ): string {
        global $lang, $mybb, $db;

        $hookArguments = [
            'this' => &$this,
        ];

        $extractVariables = [];

        $hookArguments['extractVariables'] = &$extractVariables;

        $current_user_id = (int)$mybb->user['uid'];

        $entryUserData = get_user($this->showcaseObject->entryUserID);

        $showcaseUserPermissions = $this->showcaseObject->userPermissionsGet($this->showcaseObject->entryUserID);

        if ($isEditPage) {
            $this->showcaseObject->setEntry($entry_slug, true);
        }

        switch (1 || $this->showcaseObject->config['filter_force_field']) {
            /*case FILTER_TYPE_USER_ID:
                $userData = get_user($this->showcaseObject->entryUserID);

                if (empty($userData['uid']) || empty($mybb->usergroup['canviewprofiles'])) {
                    error_no_permission();
                }

                $lang->load('member');

                $userName = htmlspecialchars_uni($userData['username']);

                add_breadcrumb(
                    $lang->sprintf($lang->nav_profile, $userName),
                    $mybb->settings['bburl'] . '/' . get_profile_link($userData['uid'])
                );

                $mainUrl = str_replace(
                    '/user/',
                    '/user/' . $this->showcaseObject->entryUserID,
                    $this->showcaseObject->urlGenerate(\MyShowcase\Plugin\RouterUrls::MainUser)
                );

                break;*/
            default:
                $mainUrl = $this->showcaseObject->urlGenerate(
                    RouterUrls::Main,
                    $this->showcaseObject->urlParams
                );

                break;
        }

        add_breadcrumb(
            $this->showcaseObject->config['name_friendly'],
            $mainUrl
        );

        if ($isEditPage) {
            add_breadcrumb(
                $lang->myShowcaseButtonEntryUpdate,
                $this->showcaseObject->urlBuild($this->showcaseObject->urlUpdateEntry)
            );
        } else {
            add_breadcrumb(
                $lang->myShowcaseButtonEntryCreate,
                $this->showcaseObject->urlBuild($this->showcaseObject->urlCreateEntry)
            );
        }

        $this->showcaseObject->urlParamsAppend([
            'entry_slug' => $this->showcaseObject->entry_data['entry_slug']
        ]);

        if (!$isEditPage && empty($this->showcaseObject->entry_data['post_hash'])) {
            if ($mybb->get_input('post_hash')) {
                $this->showcaseObject->entryDataSet(['post_hash' => $mybb->get_input('post_hash')]);
            } else {
                $this->showcaseObject->entryDataSet(['post_hash' => generateUUIDv4()]);
            }
        }

        $templatesContext = [
            'url' => [
                'crate_update' => $isEditPage ? $this->showcaseObject->urlGenerate(
                    RouterUrls::EntryUpdate,
                    array_merge($this->showcaseObject->urlParams, [
                        'entry_slug' => $this->showcaseObject->entry_data['entry_slug'],
                        'entry_slug_custom' => $this->showcaseObject->entry_data['entry_slug_custom'],
                    ])
                ) : $this->showcaseObject->urlGenerate(
                    RouterUrls::EntryCreate,
                    $this->showcaseObject->urlParams
                ),
                'upload' => $isEditPage ? $this->showcaseObject->urlGenerate(
                    RouterUrls::EntryUpdateUpload,
                    [
                        'entry_slug' => $this->showcaseObject->entry_data['entry_slug'],
                        'entry_slug_custom' => $this->showcaseObject->entry_data['entry_slug_custom'],
                        'my_post_key' => $mybb->post_code,
                    ]
                ) : $this->showcaseObject->urlGenerate(
                    RouterUrls::EntryCreateUpload,
                    [
                        'post_hash' => $this->showcaseObject->entry_data['post_hash'],
                        'my_post_key' => $mybb->post_code,
                    ]
                ),
            ],
            'is_update_page' => $isEditPage,
            'is_preview_page' => false,
            'entry_preview' => '',
            'entry_data' => array_merge(
                array_map(
                    function ($field_definition) {
                        return $field_definition['default'] ?? '';
                    },
                    DATA_TABLE_STRUCTURE['myshowcase_data']
                ),
                (function () use ($db): array {
                    $default_fields = [];

                    foreach ($this->showcaseObject->fieldSetCache as $fied_id => $field_definition) {
                        $default_fields[$field_definition['field_key']] = match ($field_definition['default_type']) {
                            FieldDefaultTypes::AsDefined => $field_definition['default_value'],
                            FieldDefaultTypes::CurrentTimestamp => date('Y-m-d H:i:s', TIME_NOW),
                            FieldDefaultTypes::UUID => generateUUIDv4(),
                            default => '',
                        };
                    }

                    return $default_fields;
                })(),
                $this->showcaseObject->entry_data,
                $mybb->input
            ),
            'attachment_objects' => $this->showcaseObject->attachmentsGetFromEntry(),
        ];

        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));

            //$this->showcaseObject->post_hash = $mybb->get_input('post_hash');

            $isPreview = isset($mybb->input['preview']);

            $insertData = [];

            $insertData['ipaddress'] = $mybb->session->packedip;

            if (!$isEditPage) {
                $insertData['user_id'] = $current_user_id;

                $insertData['ipaddress'] = $mybb->session->packedip;

                $insertData['dateline'] = TIME_NOW;

                if ($this->showcaseObject->entry_data['post_hash']) {
                    $insertData['post_hash'] = $this->showcaseObject->entry_data['post_hash'];
                }
            }

            if ($isEditPage && (
                    $this->showcaseObject->config['moderate_entries_update'] ||
                    $this->showcaseObject->user_permissions[UserPermissions::ModerateEntryUpdate]
                )) {
                $insertData['status'] = ENTRY_STATUS_PENDING_APPROVAL;
            } elseif (!$isEditPage && $this->showcaseObject->config['moderate_entries_create'] && (
                    $this->showcaseObject->config['moderate_entries_create'] ||
                    $this->showcaseObject->user_permissions[UserPermissions::ModerateEntryCreate]
                )) {
                $insertData['status'] = ENTRY_STATUS_PENDING_APPROVAL;
            }

            // Set up a showcase handler.
            //Require_once MYBB_ROOT . 'inc/datahandlers/myshowcase_dh.php';

            if ($isEditPage) {
                $dataHandler = dataHandlerGetObject($this->showcaseObject, DATA_HANDLER_METHOD_UPDATE);
            } else {
                $insertData['dateline'] = TIME_NOW;

                $dataHandler = dataHandlerGetObject($this->showcaseObject);
            }

            foreach ($this->showcaseObject->fieldSetCache as $fieldID => $fieldDefinition) {
                $insertData[$fieldDefinition['field_key']] = match ($fieldDefinition['html_type']) {
                    /*FieldHtmlTypes::CheckBox,
                    FieldHtmlTypes::Select => $mybb->get_input(
                        $fieldDefinition['field_key'],
                        MyBB::INPUT_ARRAY
                    ), */
                    default => $mybb->get_input($fieldDefinition['field_key']),
                };
            }

            // todo, slug should load all field data after update/insert to build

            $dataHandler->dataSet($insertData);

            if (!$dataHandler->entryValidate()) {
                $this->showcaseObject->errorMessages = array_merge(
                    $this->showcaseObject->errorMessages,
                    $dataHandler->get_friendly_errors()
                );
            }

            if (!$isPreview && !$this->showcaseObject->errorMessages) {
                if ($isEditPage) {
                    $dataHandler->updateEntry();
                } else {
                    $dataHandler->entryInsert();
                }

                if (isset($dataHandler->returnData['status']) && $dataHandler->returnData['status'] === ENTRY_STATUS_SOFT_DELETED) {
                    $mainUrl = $this->showcaseObject->urlGenerate(
                        RouterUrls::Main,
                        $this->showcaseObject->urlParams
                    );

                    switch (1 || $this->showcaseObject->config['filter_force_field']) {
                        /*case FILTER_TYPE_USER_ID:
                            $mainUrl = str_replace(
                                '/user/',
                                '/user/' . $this->showcaseObject->entryUserID,
                                $this->showcaseObject->urlGenerate(\MyShowcase\Plugin\RouterUrls::MainUser)
                            );

                            break;*/
                        default:
                            $mainUrl = $this->showcaseObject->urlGenerate(
                                RouterUrls::Main,
                                $this->showcaseObject->urlParams
                            );
                            break;
                    }

                    redirect(
                        $mainUrl,
                        $isEditPage ? $lang->myShowcaseEntryEntryUpdatedStatus : $lang->myShowcaseEntryEntryCreatedStatus
                    );
                } else {
                    $entryUrl = $this->showcaseObject->urlGenerate(
                        RouterUrls::EntryView,
                        [
                            'entry_slug' => $dataHandler->returnData['entry_slug'],
                            'entry_slug_custom' => $dataHandler->returnData['entry_slug_custom']
                        ]
                    );

                    redirect(
                        $entryUrl,
                        $isEditPage ? $lang->myShowcaseEntryEntryUpdated : $lang->myShowcaseEntryEntryCreated
                    );
                }

                exit;
            }

            if ($isPreview) {
                $this->showcaseObject->entry_data = array_merge(
                    array_map(
                        function ($field_definition) {
                            return $field_definition['default'] ?? '';
                        },
                        DATA_TABLE_STRUCTURE['myshowcase_data']
                    ),
                    $this->showcaseObject->entry_data,
                    $mybb->input
                );

                $templatesContext['entry_preview'] = $this->renderObject->buildEntry(true);

                $templatesContext['is_preview_page'] = true;
            }
        }

        if ($isEditPage && !$this->showcaseObject->user_permissions[UserPermissions::CanUpdateEntries]) {
            error_no_permission();
        } elseif (!$isEditPage && !$this->showcaseObject->user_permissions[UserPermissions::CanCreateEntries]) {
            error_no_permission();
        }

        $hookArguments = hooksRun('output_new_start', $hookArguments);

        $alternativeBackground = alt_trow(true);

        $fieldTabIndex = 1;

        $templatesContext['fieldSetCache'] = array_map(
            function (array &$fieldDefinition) use (&$alternativeBackground, &$fieldTabIndex): array {
                $field_id = (int)$fieldDefinition['field_id'];

                $fieldObject = fieldGetObject(
                    $this->showcaseObject,
                    $fieldDefinition,
                );

                $fieldDefinition['description'] = $fieldObject->getFieldDescription();

                $fieldDefinition['editorCodeButtons'] = $fieldDefinition['editorSmilesInserter'] = '';

                if (in_array($fieldDefinition['html_type'], [
                    FieldHtmlTypes::Text,
                    //FieldHtmlTypes::Search,
                    FieldHtmlTypes::TextArea,
                ])) {
                    if ($fieldDefinition['enable_editor']) {
                        $this->showcaseObject->renderObject->buildEditor(
                            $fieldDefinition['editorCodeButtons'],
                            $fieldDefinition['editorSmilesInserter'],
                            $fieldDefinition['field_key']
                        );
                    }
                }

                switch ($fieldDefinition['html_type']) {
                    case FieldHtmlTypes::CheckBox:
                    case FieldHtmlTypes::Radio:
                    case FieldHtmlTypes::Select:
                        $fieldDefinition['fieldDataObjects'] = [];

                        foreach (
                            fieldDataGet(
                                ["field_id='{$field_id}'"],
                                ['value', 'allowed_groups_fill'],
                                ['order_by' => 'display_order']
                            ) as $field_data_id => $field_data_data
                        ) {
                            if (is_member($fieldDefinition['allowed_groups_fill'])) {
                                $fieldDefinition['fieldDataObjects'][$field_data_id] = $field_data_data;
                            }
                        }

                        break;
                    case FieldHtmlTypes::File:
                        $attachmentTypes = cacheGet(
                            CACHE_TYPE_ATTACHMENT_TYPES
                        )[$this->showcaseObject->showcase_id] ?? [];

                        $acceptElement = implode(
                            ',',
                            array_merge(
                                array_column($attachmentTypes, 'mime_type'),
                                array_column($attachmentTypes, 'file_extension')
                            )
                        );

                        $fieldDefinition['acceptElement'] = $acceptElement;

                        switch ($fieldDefinition['file_capture']) {
                            case 1:
                                $fieldDefinition['captureElement'] = 'user';

                                break;
                            case 2:
                                $fieldDefinition['captureElement'] = 'environment';

                                break;
                        }
                }

                $fieldDefinition['attachment_objects'] = $fieldObject->attachmentsBuild()
                    ->attachmentsGet();

                ++$fieldTabIndex;

                $alternativeBackground = alt_trow();

                return $fieldDefinition;
            },
            array_filter($this->showcaseObject->fieldSetCache, function ($fieldDefinition): bool {
                return $fieldDefinition['display_in_create_update_page'] &&
                    is_member($fieldDefinition['allowed_groups_fill']);
            })
        );

        $hookArguments = hooksRun('output_new_end', $hookArguments);

        $templatesContext['attachmentsUpload'] = $this->renderObject->buildAttachmentsUpload($isEditPage);

        $hookArguments = hooksRun('entry_create_update_end', $hookArguments);

        extract($hookArguments['extractVariables']);

        return $this->renderObject->templateGet(
            'pageEntryCreateUpdate',
            $templatesContext,
        );
    }

    /**
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError
     */
    #[NoReturn] public function updateEntry(
        string $entry_slug,
        string $entry_slug_custom,
    ): string {
        return $this->createEntry($entry_slug, $entry_slug_custom, true);
    }

    /**
     * @throws RuntimeError
     * @throws LoaderError
     * @throws SyntaxError
     */
    #[NoReturn] public function viewEntry(
        string $entry_slug,
        string $entry_slug_custom,
    ): string {
        $this->showcaseObject->setEntry($entry_slug, true);

        global $mybb, $lang, $db, $theme;

        $this->renderObject->current_page = $mybb->get_input('page', MyBB::INPUT_INT);

        //$lang->load('showthread');

        $hookArguments = [
            'this' => &$this
        ];

        $extractVariables = [];

        $hookArguments['extractVariables'] = &$extractVariables;

        $current_user_id = (int)$mybb->user['uid'];

        if (empty($this->showcaseObject->entry_id)) {
            $this->showcaseObject->setEntry($entry_slug, true);
        }

        if (!$this->showcaseObject->entry_id || empty($this->showcaseObject->entry_data)) {
            error($lang->myshowcase_invalid_id);
        }

        if ($current_user_id !== $this->showcaseObject->entryUserID &&
            !$this->showcaseObject->user_permissions[ModeratorPermissions::CanManageEntries] &&
            (int)$this->showcaseObject->entry_data['status'] !== ENTRY_STATUS_VISIBLE
        ) {
            error($lang->myshowcase_invalid_id);
        }

        switch (1 || $this->showcaseObject->config['filter_force_field']) {
            /*
                        case FILTER_TYPE_USER_ID:
                            $userData = get_user($this->showcaseObject->entryUserID);

                            if (empty($userData['uid']) || empty($mybb->usergroup['canviewprofiles'])) {
                                error_no_permission();
                            }

                            $lang->load('member');

                            $userName = htmlspecialchars_uni($userData['username']);

                            add_breadcrumb(
                                $lang->sprintf($lang->nav_profile, $userName),
                                $mybb->settings['bburl'] . '/' . get_profile_link($userData['uid'])
                            );

                            $mainUrl = str_replace(
                                '/user/',
                                '/user/' . $this->showcaseObject->entryUserID,
                                $this->showcaseObject->urlGenerate(\MyShowcase\Plugin\RouterUrls::MainUser)
                            );

                            break;
            */
            default:
                $mainUrl = $this->showcaseObject->urlGenerate(
                    RouterUrls::Main,
                    $this->showcaseObject->urlParams
                );
                break;
        }

        add_breadcrumb(
            $this->showcaseObject->config['name_friendly'],
            $mainUrl
        );

        $templatesContext = [
            'entry_data' => $this->showcaseObject->entry_data,
            'captcha' => '',
            'online_count' => [
                'users' => 0,
                'invisible' => 0,
                'guests' => 0,
                'members' => 0,
            ],
            'online_users' => [],
            'inline_selected_count' => 0,
            'comments_count' => 0,
            'url' => [
                'entry' => $this->showcaseObject->urlGenerate(
                    RouterUrls::EntryView,
                    [
                        'entry_slug' => $this->showcaseObject->entry_data['entry_slug'],
                        'entry_slug_custom' => $this->showcaseObject->entry_data['entry_slug_custom']
                    ]
                ),
                'comment' => $this->showcaseObject->urlGenerate(
                    RouterUrls::CommentCreate,
                    [
                        'entry_slug' => $this->showcaseObject->entry_data['entry_slug'],
                        'entry_slug_custom' => $this->showcaseObject->entry_data['entry_slug_custom'],
                    ]
                ),
            ]
        ];

        $templatesContext['entrySubject'] = $this->renderObject->buildEntrySubject();

        $entryUrl = $this->showcaseObject->urlGenerate(
            RouterUrls::EntryView,
            [
                'entry_slug' => $this->showcaseObject->entry_data['entry_slug'],
                'entry_slug_custom' => $this->showcaseObject->entry_data['entry_slug_custom']
            ],
        );

        $templatesContext['page_canonical'] = $this->showcaseObject->url_base . $entryUrl;

        $templatesContext['page_meta'] = [
            [
                'property' => 'og:url',
                'content' => $this->showcaseObject->url_base . $entryUrl,
            ],
            [
                'property' => 'og:type',
                'content' => 'article',
            ],
            [
                'property' => 'og:description',
                'content' => $showcaseDescription,
            ],
            /*
             [
                'property' => 'og:title',
                'content' => 'European Travel Destinations',
            ],
            [
                'property' => 'og:image',
                'content' => 'https://euro-travel-example.com/thumbnail.jpg',
            ],
            */
        ];

        add_breadcrumb(
            $templatesContext['entrySubject'],
            $entryUrl
        );

        if ($this->showcaseObject->entry_data['username'] === '') {
            $this->showcaseObject->entry_data['username'] = $lang->guest;
            $this->showcaseObject->entry_data['user_id'] = 0;
        }

        $entryUrl = str_replace(
            '{entry_id}',
            (string)$mybb->get_input('entry_id'),
            $this->showcaseObject->urlViewEntry
        );

        //$this->showcaseObject->post_hash = $this->showcaseObject->entryData['post_hash'];

        //trick MyBB into thinking its in archive so it adds bburl to smile link inside parser
        //doing this now should not impact anyhting. no issues with gomobile beta4
        define('IN_ARCHIVE', 1);

        $templatesContext['entryPost'] = $this->renderObject->buildEntry();

        $templatesContext['commentsForm'] = '';

        if ($this->showcaseObject->config['comments_allow'] && $this->showcaseObject->user_permissions[UserPermissions::CanViewComments]) {
            $whereClauses = [
                "entry_id='{$this->showcaseObject->entry_id}'",
                "showcase_id='{$this->showcaseObject->showcase_id}'"
            ];

            $statusVisible = COMMENT_STATUS_VISIBLE;

            $statusPendingApproval = COMMENT_STATUS_PENDING_APPROVAL;

            $statusSoftDeleted = COMMENT_STATUS_SOFT_DELETED;

            $whereClausesClauses = [
                "status='{$statusVisible}'",
                "user_id='{$current_user_id}' AND status='{$statusPendingApproval}'",
            ];

            if (ModeratorPermissions::CanManageEntries) {
                $whereClausesClauses[] = "status='{$statusPendingApproval}'";

                $whereClausesClauses[] = "status='{$statusSoftDeleted}'";
            }

            $whereClausesClauses = implode(' OR ', $whereClausesClauses);

            $whereClauses[] = "({$whereClausesClauses})";

            $queryOptions = ['order_by' => 'dateline', 'order_dir' => 'asc'];

            $queryOptions['limit'] = $this->showcaseObject->config['comments_per_page'];

            $hookArguments = hooksRun('entry_view_comment_form_start', $hookArguments);

            $totalComments = (int)(commentsGet(
                $whereClauses,
                ['COUNT(comment_id) AS total_comments'],
                ['limit' => 1, 'group_by' => 'comment_id']
            )['total_comments'] ?? 0);

            //$this->renderObject->current_page = $mybb->get_input('page', MyBB::INPUT_INT);

            $totalPages = $totalComments / $this->showcaseObject->config['comments_per_page'];

            $totalPages = ceil($totalPages);

            if ($this->renderObject->current_page > $totalPages || $this->renderObject->current_page <= 0) {
                $this->renderObject->current_page = 1;
            }

            if ($this->renderObject->current_page) {
                $queryOptions['limit_start'] = ($this->renderObject->current_page - 1) * $this->showcaseObject->config['comments_per_page'];
            } else {
                $queryOptions['limit_start'] = 0;

                $this->renderObject->current_page = 1;
            }

            $commentsCounter = 0;

            if ($this->renderObject->current_page > 1) {
                $url_params['page'] = $this->renderObject->current_page;

                $commentsCounter += ($this->renderObject->current_page - 1) * $this->showcaseObject->config['comments_per_page'];
            }

            $urlParams = [
                //'page' => '{page}'
            ];

            $entryUrl = $this->showcaseObject->urlGenerate(
                RouterUrls::EntryView,
                [
                    'entry_slug' => $this->showcaseObject->entry_data['entry_slug'],
                    'entry_slug_custom' => $this->showcaseObject->entry_data['entry_slug_custom']
                ],
                ['category' => 'shoes']
            );
            /*

                \MyShowcase\SimpleRouter123\$this->showcaseObject->urlGenerate('product', ['id' => 22], ['category' => 'shoes'])->getParam('category'),

                \MyShowcase\SimpleRouter123\$this->showcaseObject->urlGenerate('product', ['id' => 22], ['category' => 'shoes'])->getParams(),
            */

            $templatesContext['pagination'] = multipage(
                $totalComments,
                $this->showcaseObject->config['comments_per_page'],
                $this->renderObject->current_page,
                $this->showcaseObject->urlGenerate(
                    RouterUrls::EntryViewPage,
                    [
                        'entry_slug' => $this->showcaseObject->entry_data['entry_slug'],
                        'entry_slug_custom' => $this->showcaseObject->entry_data['entry_slug_custom'],
                        'page_id' => '{page}'
                    ],
                    $urlParams
                )
            );

            extract($hookArguments['extractVariables']);

            $extractVariables = [];

            $hookArguments = hooksRun('entry_view_comment_form_intermediate', $hookArguments);

            $commentObjects = commentsGet(
                $whereClauses,
                ['user_id', 'comment', 'dateline', 'ipaddress', 'status', 'edit_user_id', 'comment_slug'],
                $queryOptions
            );

            $templatesContext['alternativeBackground'] = alt_trow(true);

            $templatesContext['commentObjects'] = &$commentObjects;

            foreach ($commentObjects as $commentID => &$commentData) {
                ++$commentsCounter;

                $commentData['comment_formatted'] .= $this->renderObject->buildComment(
                    $commentData,
                    $templatesContext['alternativeBackground'],
                    $commentsCounter,
                );

                $templatesContext['alternativeBackground'] = alt_trow();
            }

            $hookArguments = hooksRun('entry_view_comment_form_end', $hookArguments);

            $templatesContext['can_create_comments'] = $this->showcaseObject->user_permissions[UserPermissions::CanCreateComments];

            if ($this->showcaseObject->user_permissions[UserPermissions::CanCreateComments]) {
                global $collapsedthead, $collapsedimg, $expaltext, $collapsed;

                isset($collapsedthead) || $collapsedthead = [];

                isset($collapsedimg) || $collapsedimg = [];

                isset($collapsed) || $collapsed = [];

                $collapsedthead['quickreply'] ??= '';

                $collapsedimg['quickreply'] ??= '';

                $collapsed['quickreply_e'] ??= '';

                $templatesContext['commentLengthLimitNote'] = $lang->sprintf(
                    $lang->myshowcase_comment_text_limit,
                    my_number_format($this->showcaseObject->config['comments_minimum_length']),
                    my_number_format($this->showcaseObject->config['comments_maximum_length'])
                );

                $templatesContext['alternativeBackground'] = alt_trow(true);

                $templatesContext['createUpdateUrl'] = $this->showcaseObject->urlGenerate(
                    RouterUrls::CommentCreate,
                    [
                        'entry_slug' => $this->showcaseObject->entry_data['entry_slug'],
                        'entry_slug_custom' => $this->showcaseObject->entry_data['entry_slug_custom'],
                    ]
                );

                $templatesContext['commentMessage'] = htmlspecialchars_uni($mybb->get_input('comment'));

                $templatesContext['editorCodeButtons'] = $templatesContext['editorSmilesInserter'] = '';

                $this->renderObject->buildCommentsFormEditor(
                    $templatesContext['editorCodeButtons'],
                    $templatesContext['editorSmilesInserter']
                );
            }
        }

        // Update view count
        if (
            (
                !$current_user_id &&
                (
                    ($mybb->session->is_spider && getSetting('ViewsCountSpider')) ||
                    (!$mybb->session->is_spider && getSetting('ViewsCountGuests'))
                )
            ) ||
            (
                $current_user_id && (getSetting('ViewsCountAuthor') ||
                    $current_user_id !== $this->showcaseObject->entryUserID)
            )
        ) {
            $db->shutdown_query(
                "UPDATE {$db->table_prefix}{$this->showcaseObject->dataTableName} SET views=views+1 WHERE entry_id='{$this->showcaseObject->entry_id}'"
            );
        }

        $hookArguments = hooksRun('entry_view_end', $hookArguments);

        extract($hookArguments['extractVariables']);

        if ($this->showcaseObject->user_permissions[UserPermissions::CanCreateComments] &&
            empty($mybb->user['suspendposting']) &&
            (empty($this->showcaseObject->entry_data['is_closed']) || $this->showcaseObject->user_permissions[ModeratorPermissions::CanManageEntries]) &&
            !empty($mybb->settings['quickreply']) &&
            !empty($mybb->user['showquickreply']) &&
            $this->showcaseObject->entry_data['uid'] === $this->showcaseObject->current_user_id ||
            true) {
            $templatesContext['showquickreply'] = true;

            // Show captcha image for guests if enabled
            if ($mybb->settings['captchaimage'] && !$this->showcaseObject->current_user_id or true) {
                require_once MYBB_ROOT . 'inc/class_captcha.php';

                $post_captcha = new captcha(true, 'post');

                if ($post_captcha->html) {
                    $templatesContext['captcha'] = $post_captcha->html;
                }
            }
        }

        // Get users viewing this thread
        if (!empty($mybb->settings['browsingthisthread'])) {
            $timecut = TIME_NOW - $mybb->settings['wolcutoff'];

            $doneusers = array();

            $query = $db->simple_select(
                "sessions s LEFT JOIN {$db->table_prefix}users u ON (s.uid=u.uid)",
                's.ip, s.uid, s.time, u.username, u.invisible, u.usergroup, u.displaygroup',
                "s.uid != 0 AND s.time > '$timecut' AND location2='$tid' AND nopermission != 1",
                [
                    'order_by' => 'u.username ASC, s.time',
                    'order_dir' => 'DESC',
                ]
            );

            while ($user_data = $db->fetch_array($query)) {
                if ($user_data['uid'] == 0) {
                    ++$templatesContext['online_count']['guests'];
                } elseif (empty($doneusers[$user_data['uid']]) || $doneusers[$user_data['uid']] < $user_data['time']) {
                    ++$templatesContext['online_count']['members'];
                    $doneusers[$user_data['uid']] = $user_data['time'];

                    $user_data['invisible_mark'] = '';
                    if ($user_data['invisible'] == 1) {
                        $user_data['invisible_mark'] = '*';

                        ++$templatesContext['online_count']['invisible'];
                    }

                    if ($user_data['invisible'] != 1 || $mybb->usergroup['canviewwolinvis'] == 1 || $user_data['uid'] == $mybb->user['uid']) {
                        $user_data['reading'] = my_date($mybb->settings['timeformat'], $user_data['time']);
                        ++$templatesContext['online_count']['users'];

                        $templatesContext['online_users'][] = $user_data;
                    }
                }
            }

            if (!empty($mybb->user['invisible'])) {
                // The user was counted as invisible user --> correct the online_count.invisible
                --$templatesContext['online_count']['invisible'];
            }
        }

        return $this->renderObject->templateGet('pageView', $templatesContext);
    }

    #[NoReturn] public function approveEntry(
        string $entrySlug,
        int $status = ENTRY_STATUS_VISIBLE
    ): void {
        global $lang;

        $this->showcaseObject->setEntry($entrySlug);

        if (!$this->showcaseObject->user_permissions[ModeratorPermissions::CanManageEntries] ||
            !$this->showcaseObject->entry_id) {
            error_no_permission();
        }

        $this->showcaseObject->dataUpdate(['status' => $status]);

        $entryUrl = $this->showcaseObject->urlGenerate(
                RouterUrls::EntryView,
                ['entry_slug' => $this->showcaseObject->entry_data['entry_slug']]
            ) . '#entry_id' . $this->showcaseObject->entry_id;

        switch ($status) {
            case ENTRY_STATUS_PENDING_APPROVAL:
                $redirectMessage = $lang->myShowcaseEntryEntryUnapproved;
                break;
            case ENTRY_STATUS_VISIBLE:
                $redirectMessage = $lang->myShowcaseEntryEntryApproved;
                break;
            case ENTRY_STATUS_SOFT_DELETED:
                $redirectMessage = $lang->myShowcaseEntryEntrySoftDeleted;
                break;
        }

        redirect($entryUrl, $redirectMessage);
    }

    #[NoReturn] public function unapproveEntry(
        string $entrySlug,
    ): void {
        $this->approveEntry(
            $entrySlug,
            ENTRY_STATUS_PENDING_APPROVAL
        );
    }

    #[NoReturn] public function softDeleteEntry(
        string $entrySlug
    ): void {
        $this->approveEntry(
            $entrySlug,
            ENTRY_STATUS_SOFT_DELETED
        );
    }

    #[NoReturn] public function restoreEntry(
        string $entrySlug
    ): void {
        $this->approveEntry(
            $entrySlug
        );
    }

    #[NoReturn] public function deleteEntry(
        string $entrySlug
    ): void {
        global $mybb, $lang;

        $current_user_id = (int)$mybb->user['uid'];

        $this->showcaseObject->setEntry($entrySlug);

        if (!$this->showcaseObject->entry_id ||
            !(
                $this->showcaseObject->user_permissions[ModeratorPermissions::CanManageEntries] ||
                ($this->showcaseObject->entryUserID === $current_user_id && $this->showcaseObject->user_permissions[UserPermissions::CanDeleteEntries])
            ) || !$this->showcaseObject->entry_id) {
            error_no_permission();
        }

        $this->showcaseObject->entryDelete();

        $mainUrl = $this->showcaseObject->urlGenerate(
            RouterUrls::Main,
            $this->showcaseObject->urlParams
        );

        redirect($mainUrl, $lang->myShowcaseEntryEntryDeleted);

        exit;
    }

    #[NoReturn] private function uploadAttachment(): void
    {
        return;
        if (!$this->showcaseObject->config['attachments_allow_entries']) {
            return;
        }

        global $mybb;

        $is_upload_request =
            isset($_SERVER['HTTP_UPLOAD_LENGTH']) ||
            isset($_SERVER['HTTP_UPLOAD_OFFSET']) ||
            isset($_SERVER['HTTP_UPLOAD_FILENAME']);

        if (!$is_upload_request) {
            _dump($mybb->input);
            return;
        }

        _dump($mybb->request_method);

        verify_post_check($mybb->get_input('my_post_key'));

        _dump($mybb->request_method);

        echo json_encode([$mybb->input]);
        exit;

        $process_file = (
            !empty($_FILES['attachment']) &&
            !empty($_FILES['attachment']['name'])
        );

        if (!$process_file) {
            return;
        }

        if (!$this->showcaseObject->user_permissions[UserPermissions::CanUploadAttachments]) {
            error_no_permission();
        }

        global $mybb, $lang;

        $mybb->input['preview'] = true;

        require_once MYBB_ROOT . 'inc/functions_upload.php';

        $fileObject = attachmentUpload(
            $this->showcaseObject,
            $_FILES['attachment'],
            watermarkImage: $mybb->get_input('attachment_watermark_file', MyBB::INPUT_BOOL),
        );

        if (isset($fileObject['error'])) {
            $this->showcaseObject->errorMessages = array_merge(
                $this->showcaseObject->errorMessages,
                (array)$fileObject['error']
            );
        }
    }
}

//todo review hooks here