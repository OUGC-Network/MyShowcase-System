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

use MyBB;
use JetBrains\PhpStorm\NoReturn;
use MyShowcase\Plugin\RouterUrls;
use MyShowcase\System\FieldHtmlTypes;
use MyShowcase\System\ModeratorPermissions;
use MyShowcase\System\UserPermissions;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

use function MyBB\View\template;
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
use function MyShowcase\Plugin\Functions\getSetting;
use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\moderatorGet;
use function MyShowcase\Plugin\Functions\urlHandlerBuild;
use function MyShowcase\SimpleRouter\url;

use const MyShowcase\ROOT;
use const MyShowcase\Plugin\Core\CACHE_TYPE_ATTACHMENT_TYPES;
use const MyShowcase\Plugin\Core\HTTP_CODE_PERMANENT_REDIRECT;
use const MyShowcase\Plugin\Core\ATTACHMENT_THUMBNAIL_SMALL;
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

        require_once ROOT . '/System/FieldHtmlTypes.php';
        require_once ROOT . '/System/FormatTypes.php';
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

        $currentUserID = (int)$mybb->user['uid'];

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

        if ($entryStatus === ENTRY_STATUS_PENDING_APPROVAL && $currentUserID !== $entryUserID ||
            $entryStatus === ENTRY_STATUS_SOFT_DELETED && !$this->showcaseObject->user_permissions[ModeratorPermissions::CanManageEntries]) {
            return self::INVALID_ENTRY;
        }

        $this->entryID = (int)$entryData['entry_id'];

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

        \MyShowcase\SimpleRouter\redirect($entryUrl, HTTP_CODE_PERMANENT_REDIRECT);

        exit;
    }

    #[NoReturn] public function mainPage(
        int $currentPage = 1,
        int $userID = 0,
        int $limit = 0,
        int $limitStart = 0,
        string $groupBy = '',
        string $orderBy = 'user_id',
        string $orderDirection = ORDER_DIRECTION_ASCENDING,
        string $filterField = '',
        mixed $filterValue = '',
        array $whereClauses = []
    ): void {
        $this->mainView(userID: $userID, currentPage: $currentPage);
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
        int $currentPage = 1,
    ): string {
        global $lang, $mybb, $db;

        $currentUserID = (int)$mybb->user['uid'];

        if ($this->showcaseObject->config['entries_per_page'] < 1) {
            $this->showcaseObject->config['entries_per_page'] = 1;
        }

        $templatesContext = [
            'showcase' => $this->showcaseObject->config,
            'url_main' => $this->showcaseObject->urlGenerate(RouterUrls::Main, $this->showcaseObject->urlParams),
            'sort_by_field' => $this->showcaseObject->sort_by_field,
            'orderBy' => $this->showcaseObject->orderBy,
            'pageCurrent' => $this->renderObject->pageCurrent,
            'searchField' => $this->showcaseObject->searchField,
            'searchKeyWords' => $this->renderObject->searchKeyWords,
            'searchExactMatch' => $this->renderObject->searchExactMatch,
            'page_meta' => [
                [
                    'property' => 'og:description',
                    'content' => $this->showcaseObject->config['description'],
                ],
                [
                    'property' => 'og:title',
                    'content' => $this->showcaseObject->config['name'],
                ],
            ],
            'moderators' => [],
            'users_browsing' => [],
            'users_browsing_counter' => [
                'guests' => 0,
                'members' => 0,
                'invisible' => 0,
            ],
            'url_base' => $this->showcaseObject->url_base,
        ];

        // Fetch each moderator from the cache and format it, appending it to the list
        foreach (
            moderatorGet(["showcase_id='{$this->showcaseObject->showcase_id}'"], ['user_id', 'is_group']) as $moderator
        ) {
            if ($moderator['is_group'] && $group_data = $mybb->cache->read('usergroups')[$moderator['user_id']]) {
                $templatesContext['moderators']['groups'][$moderator['user_id']] = [
                    'title' => $group_data['title'],
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
                        (int)$user_data['uid'] === $currentUserID) {
                        $templatesContext['users_browsing'][$user_data['uid']] = $user_data;
                    }
                }
            }

            // The user was counted as invisible user --> correct the inviscount
            if ($mybb->user['invisible'] == 1) {
                $templatesContext['users_browsing_counter']['invisible'] -= 1;
            }
        }

        $hookArguments = [
            'templatesContext' => &$templatesContext,
        ];

        $hookArguments = hooksRun('output_main_start', $hookArguments);

        $templatesContext['user_permissions'] = $this->showcaseObject->user_permissions;

        /*
                switch ($this->showcaseObject->config['filter_force_field']) {
                    case FILTER_TYPE_USER_ID:
                        $displayCreateButton = $this->showcaseObject->user_permissions[UserPermissions::CanCreateEntries] && $userID === $currentUserID;

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
                $this->showcaseObject->urlParams
            );
        }

        $templatesContext['orderBy'] = $this->showcaseObject->orderBy;

        switch ($this->showcaseObject->orderBy) {
            case ORDER_DIRECTION_ASCENDING:
                $inputOrderText = $lang->myshowcase_desc;
                break;
            default:
                $inputOrderText = $lang->myshowcase_asc;
                break;
        }

        //build sort_by option list
        $templatesContext['sort_by_field'] = $this->showcaseObject->sort_by_field;

        $templatesContext['fieldset_fields_display_fields'] = $this->showcaseObject->fieldset_fields_display_fields;

        $templatesContext['selectOptionsSearchField'] = '';

        foreach ($this->renderObject->fieldSetFieldsSearchFields as $field_key => $field_display_name) {
            $optionSelectedElement = '';

            if ($this->showcaseObject->searchField === $field_key) {
                $optionSelectedElement = 'selected="selected"';
            }

            $templatesContext['selectOptionsSearchField'] .= $this->renderObject->templateGet(
                'pageMainSelectOption',
                [
                    'field_key' => $field_key,
                    'selectedElement' => $selectedElement,
                    'field_display_name' => $field_display_name,
                ]
            );
        }

        $templatesContext['inputElementExactMatch'] = '';

        if ($this->renderObject->searchExactMatch) {
            $templatesContext['inputElementExactMatch'] = 'checked="checked"';
        }

        $urlSortRow = urlHandlerBuild(
            array_merge($this->renderObject->urlParams, ['order_by' => $this->showcaseObject->orderBy])
        );

        $templatesContext['orderInputs'] = array_map(function (string $value): string {
            return '';
        }, $this->showcaseObject->fieldset_fields_display_fields);

        if ($inputOrderText) {
            $templatesContext['orderInputs'][$this->showcaseObject->sort_by_field] = $this->renderObject->templateGet(
                'pageMainTableHeadFieldSort',
                [
                    'url_base' => $this->showcaseObject->url_base,
                    'urlSortRow' => $urlSortRow,
                    'inputOrderText' => $inputOrderText,
                ]
            );
        }

        $templatesContext['fieldSetFieldsOrder'] = $this->showcaseObject->fieldSetFieldsOrder;

        $templatesContext['fieldSetCache'] = array_map(
            function (array &$fieldDefinition) use (&$alternativeBackground, &$fieldTabIndex): array {
                return $fieldDefinition;
            },
            array_filter($this->showcaseObject->fieldSetCache, function ($fieldDefinition): bool {
                return $fieldDefinition['display_in_create_update_page'] &&
                    is_member($fieldDefinition['allowed_groups_fill']);
            })
        );

        foreach ($this->showcaseObject->fieldSetFieldsOrder as $renderOrder => $fieldID) {
            $field_key = $this->showcaseObject->fieldSetCache[$fieldID]['field_key'];

            if (!isset($lang->{"myshowcase_field_{$field_key}"})) {
                $lang->{"myshowcase_field_{$field_key}"} = ucfirst($field_key);
            }
        }

        //setup joins for query and build where clause based on search_field terms

        $queryTables = ['users userData ON (userData.uid = entryData.user_id)'];

        $queryFields = array_merge(array_map(function (string $fieldName): string {
            return 'entryData.' . $fieldName;
        }, array_keys(DATA_TABLE_STRUCTURE['myshowcase_data'])), [
            'userData.username',
            'userData.usergroup',
            'userData.displaygroup'
        ]);

        $searchDone = false;

        $allowedStatuses = [ENTRY_STATUS_VISIBLE];

        if ($this->showcaseObject->user_permissions[ModeratorPermissions::CanManageEntries]) {
            $allowedStatuses[] = ENTRY_STATUS_SOFT_DELETED;
        }

        $allowedStatuses = implode("','", $allowedStatuses);

        $whereClauseStatus = ["entryData.status IN ('{$allowedStatuses}')"];

        $statusPendingApproval = ENTRY_STATUS_PENDING_APPROVAL;

        $whereClauseStatus[] = "(entryData.user_id='{$currentUserID}' AND entryData.status='{$statusPendingApproval}')";

        $whereClauseStatus = implode(' OR ', $whereClauseStatus);

        $whereClauses[] = "({$whereClauseStatus})";

        $whereClauses = array_merge($whereClauses, $this->showcaseObject->whereClauses);

        $keywords = explode(' ', $this->renderObject->searchKeyWords);

        foreach ($this->showcaseObject->fieldSetCache as $fieldID => $fieldDefinition) {
            $field_key = $fieldDefinition['field_key'];

            $htmlType = $fieldDefinition['html_type'];

            if ($htmlType === FieldHtmlTypes::Select || $htmlType === FieldHtmlTypes::Radio) {
                $queryTables[] = "myshowcase_field_data table_{$field_key} ON (table_{$field_key}.field_data_id = entryData.{$field_key} AND table_{$field_key}.field_id = '{$fieldDefinition['field_id']}')";

                $queryFields[] = "table_{$field_key}.display_style AS {$field_key}";

                if ($this->renderObject->searchKeyWords && $this->showcaseObject->searchField === $field_key) {
                    if ($this->renderObject->searchExactMatch) {
                        $whereClauses[] = "table_{$field_key}.value='{$db->escape_string($this->renderObject->searchKeyWords)}'";
                    } else {
                        $whereClauses[] = "table_{$field_key}.value LIKE '%{$db->escape_string($this->renderObject->searchKeyWords)}%'";
                    }

                    $whereClauses[] = "table_{$field_key}.set_id='{$this->showcaseObject->config['field_set_id']}'";
                }
            } elseif ($this->showcaseObject->searchField === 'username' && !$searchDone) {
                $queryTables[] = 'users us ON (entryData.user_id = us.uid)';

                $queryFields[] = $field_key;

                if ($this->renderObject->searchKeyWords) {
                    if ($this->renderObject->searchExactMatch) {
                        $whereClauses[] = "us.username='{$db->escape_string($this->renderObject->searchKeyWords)}'";
                    } else {
                        $whereClauses[] = "us.username LIKE '%{$db->escape_string($this->renderObject->searchKeyWords)}%'";
                    }
                }

                $searchDone = true;
            } else {
                $queryFields[] = $field_key;

                if ($this->renderObject->searchKeyWords && $this->showcaseObject->searchField === $field_key) {
                    if ($this->renderObject->searchExactMatch) {
                        $whereClauses[] = "entryData.{$field_key}='{$db->escape_string($this->renderObject->searchKeyWords)}'";
                    } else {
                        $whereClauses[] = "entryData.{$field_key} LIKE '%{$db->escape_string($this->renderObject->searchKeyWords)}%'";
                    }
                }
            }
        }

        $queryOptions = [
            'order_by' => 'entry_id',
            'order_dir' => $this->showcaseObject->orderBy,
        ];

        if ($this->showcaseObject->sort_by_field !== 'dateline') {
            $queryOptions['order_by'] = "{$db->escape_string($this->showcaseObject->sort_by_field)} {$this->showcaseObject->orderBy}, entry_id";

            $queryOptions['order_dir'] = $this->showcaseObject->orderBy;
        }

        $totalEntries = (int)(entryGet(
            $this->showcaseObject->showcase_id,
            $whereClauses,
            ['COUNT(entryData.entry_id) AS total_entries'],
            array_merge(['limit' => 1, 'group_by' => 'entryData.entry_id'], $queryOptions),
            $queryTables
        )['total_entries'] ?? 0);

        $templatesContext['pagination'] = '';

        $templatesContextEntry = ['alternativeBackground' => alt_trow(true)];

        $hookArguments = hooksRun('output_main_intermediate', $hookArguments);

        $templatesContext['totalEntries'] = $totalEntries;

        if ($totalEntries) {
            $entriesPerPage = $this->showcaseObject->config['entries_per_page'];

            if ($currentPage > 0) {
                $pageStart = ($currentPage - 1) * $entriesPerPage;

                $pageTotal = $totalEntries / $entriesPerPage;

                $pageTotal = ceil($pageTotal);

                if ($currentPage > $pageTotal) {
                    $pageStart = 0;

                    $currentPage = 1;
                }
            } else {
                $pageStart = 0;

                $currentPage = 1;
            }

            $upper = $pageStart + $entriesPerPage;

            if ($upper > $totalEntries) {
                $upper = $totalEntries;
            }

            $queryOptions['limit'] = $entriesPerPage;

            $queryOptions['limit_start'] = $pageStart;

            $templatesContext['pagination'] = multipage(
                $totalEntries,
                $this->showcaseObject->config['entries_per_page'],
                $currentPage,
                $this->showcaseObject->urlGenerate(
                    RouterUrls::MainPage,
                    [
                        'user_id' => $userID,
                        'page_id' => '{page}'
                    ],
                )
            );

            $entriesObjects = entryGet(
                $this->showcaseObject->showcase_id,
                $whereClauses,
                $queryFields,
                $queryOptions,
                $queryTables
            );

            // get first attachment for each showcase on this page
            $entryAttachmentsCache = [];

            if ($this->showcaseObject->config['attachments_main_render_first']) {
                $entryIDs = implode("','", array_column($entriesObjects, 'entry_id'));

                $attachmentObjects = attachmentGet(
                    ["showcase_id='{$this->showcaseObject->showcase_id}'", "entry_id IN ('{$entryIDs}')", "status='1'"],
                    [
                        'entry_id',
                        'MIN(attachment_id) as attachment_id',
                        'mime_type',
                        'file_name',
                        'attachment_name',
                        'thumbnail_name',
                        'attachment_hash',
                        'thumbnail_dimensions',
                    ],
                    // todo, seems like MIN(attachment_id) as attachment_id is unnecessary
                    ['group_by' => 'entry_id']
                );

                foreach ($attachmentObjects as $attachmentID => $attachmentData) {
                    $entryAttachmentsCache[$attachmentData['entry_id']] = [
                        'attachment_id' => $attachmentID,
                        'mime_type' => $attachmentData['mime_type'],
                        'file_name' => $attachmentData['file_name'],
                        'attachment_name' => $attachmentData['attachment_name'],
                        'thumbnail_name' => $attachmentData['thumbnail_name'],
                        'attachment_hash' => $attachmentData['attachment_hash'],
                        'thumbnail_dimensions' => $attachmentData['thumbnail_dimensions'],
                    ];
                }
            }

            $inlineModerationCount = 0;

            if ($this->showcaseObject->config['entries_per_page'] === 1) {
                $entriesObjects = [$entriesObjects];
            }

            $templatesContext['entriesObjects'] = &$entriesObjects;

            foreach ($entriesObjects as &$entryFieldData) {
                $templatesContextEntry['entrySlug'] = $entrySlug = $entryFieldData['entry_slug'];

                $this->showcaseObject->entryDataSet($entryFieldData);

                $entryStatus = (int)$entryFieldData['status'];

                $templatesContextEntry['styleClass'] = '';

                switch ($entryStatus) {
                    case ENTRY_STATUS_PENDING_APPROVAL:
                        $templatesContextEntry['styleClass'] = 'trow_shaded';
                        break;
                    case ENTRY_STATUS_SOFT_DELETED:
                        $templatesContextEntry['styleClass'] = 'trow_shaded trow_deleted';
                        break;
                }

                //change style is unapproved
                if (empty($entryFieldData['approved'])) {
                    //$templatesContextEntry['alternativeBackground'] .= ' trow_shaded';
                }

                $entryID = (int)$entryFieldData['entry_id'];

                $entryFieldData['username'] ??= $lang->guest;

                $entryUsername = $templatesContextEntry['entryUsernameFormatted'] = htmlspecialchars_uni(
                    $entryFieldData['username']
                );

                $templatesContextEntry['entryUnapproved'] = ''; // todo, show ({Unapproved}) in the list view

                $templatesContextEntry['entryPagination'] = ''; // todo, show pagination in the list view

                $templatesContextEntry['viewAttachmentsCount'] = ''; // todo, show attachment count in the list view

                if (!empty($entryFieldData['user_id'])) {
                    $templatesContextEntry['entryUsernameFormatted'] = build_profile_link(
                        format_name(
                            $entryFieldData['username'],
                            $entryFieldData['usergroup'],
                            $entryFieldData['displaygroup']
                        ),
                        $entryFieldData['user_id']
                    );
                }

                $templatesContextEntry['viewLastCommenter'] = $templatesContextEntry['entryUsernameFormatted']; // todo, show last commenter in the list view

                $entryFieldData['entryUrl'] = $this->showcaseObject->urlGenerate(
                    RouterUrls::EntryView,
                    [
                        'entry_slug' => $this->showcaseObject->entryData['entry_slug'],
                        'entry_slug_custom' => $this->showcaseObject->entryData['entry_slug_custom']
                    ],
                    $this->showcaseObject->urlParams
                );

                $viewLastCommentID = 0; // todo, show last comment ID in the list view

                $entryFieldData['entryUrlLastComment'] = str_replace(
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

                $entryImageText = str_replace('{username}', $entryUsername, $lang->myshowcase_view_user);

                $templatesContextEntry['entryImage'] = '';

                //use showcase attachment if one exists, scaled of course
                if ($this->showcaseObject->config['attachments_main_render_first'] &&
                    !empty($entryAttachmentsCache[$entryFieldData['entry_id']])) {
                    $attachmentUrl = $this->showcaseObject->urlGenerate(
                        RouterUrls::AttachmentView,
                        [
                            'entry_slug' => $entrySlug,
                            'entry_slug_custom' => $this->showcaseObject->entryData['entry_slug_custom'],
                            'attachment_hash' => $entryAttachmentsCache[$entryFieldData['entry_id']]['attachment_hash']
                        ]
                    );

                    $thumbnailUrl = $this->showcaseObject->urlGenerate(
                        RouterUrls::ThumbnailView,
                        [
                            'entry_slug' => $entrySlug,
                            'entry_slug_custom' => $this->showcaseObject->entryData['entry_slug_custom'],
                            'attachment_hash' => $entryAttachmentsCache[$entryFieldData['entry_id']]['attachment_hash']
                        ]
                    );

                    if (stristr(
                            $entryAttachmentsCache[$entryFieldData['entry_id']]['mime_type'] ?? '',
                            'image/'
                        ) && $entryAttachmentsCache[$entryFieldData['entry_id']]['attachment_id']) {
                        if ((int)$entryAttachmentsCache[$entryFieldData['entry_id']]['thumbnail_dimensions'] === ATTACHMENT_THUMBNAIL_SMALL) {
                            $urlImage = $attachmentUrl;
                        } else {
                            $urlImage = $thumbnailUrl;
                        }

                        $templatesContextEntry['entryImage'] = $this->renderObject->templateGet(
                            'pageMainTableRowsImage',
                            [
                                'urlImage' => $urlImage,
                                'entryImageText' => $entryImageText,
                            ]
                        );
                    }
                }

                //build custom list items based on field settings
                $showcaseTableRowExtra = [];

                $entrySubject = $entryFieldData['fieldCache'] = [];

                foreach ($this->showcaseObject->fieldSetCache as $fieldID => $fieldDefinition) {
                    $field_key = $fieldDefinition['field_key'];

                    $htmlType = $fieldDefinition['html_type'];

                    $entryFieldText = $entryFieldData[$field_key] ?? '';

                    if (!$fieldDefinition['parse']) {
                        // todo, remove this legacy updating the database and updating the format field to TINYINT
                        formatField((int)$fieldDefinition['format'], $entryFieldText);
                        /*if ($htmlType === FieldHtmlTypes::Date) {
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

                    $entryFieldData['fieldCache'][$fieldID] = $entryFieldText;

                    if ($fieldDefinition['enable_subject'] && !empty($entryFieldText)) {
                        $entrySubject[] = $entryFieldText;
                    }
                }

                $entryFieldData['entrySubject'] = trim(
                    implode(' ', $entrySubject)
                ) ?? $lang->myShowcaseMainTableHeadView;

                if ($this->showcaseObject->config['attachments_allow_entries'] &&
                    $this->showcaseObject->user_permissions[UserPermissions::CanViewAttachments]) {
                    $this->renderObject->entryBuildAttachments(
                        $showcaseTableRowExtra,
                        $this->renderObject::POST_TYPE_ENTRY
                    );
                }

                $templatesContextEntry['tableRowExtra'] = implode('', $showcaseTableRowExtra);

                /*$templatesContext['showcaseEntriesList'] .= $this->renderObject->templateGet(
                    'pageMainTableRows',
                    $templatesContextEntry
                );*/

                if ($this->showcaseObject->user_permissions[ModeratorPermissions::CanManageEntries] &&
                    isset($mybb->cookies['inlinemod_showcase' . $this->showcaseObject->showcase_id]) &&
                    my_strpos(
                        $mybb->cookies['inlinemod_showcase' . $this->showcaseObject->showcase_id],
                        "|{$entryFieldData['entry_id']}|"
                    ) !== false) {
                    $entryFieldData['moderator_check'] = true;
                }

                $templatesContextEntry['alternativeBackground'] = alt_trow();

                $comment_data = commentsGet(
                    ["showcase_id={$this->showcaseObject->showcase_id}", "entry_id={$entryID}"],
                    ['comment_slug', 'showcase_id', 'entry_id', 'user_id', 'dateline',],
                );

                $entryFieldData['last_commenter_user_data'] = get_user($comment_data['user_id']);
            }
        } else {
            $templatesContext['searchKeyWords'] = $this->renderObject->searchKeyWords;
        }

        $page_title = $this->showcaseObject->config['name'];

        $templatesContext['sortByUsernameUrl'] = urlHandlerBuild(
            array_merge($this->renderObject->urlParams, ['sort_by' => 'username'])
        );

        $templatesContext['sortByCommentsUrl'] = urlHandlerBuild(
            array_merge($this->renderObject->urlParams, ['sort_by' => 'comments'])
        );

        $templatesContext['sortByViewsUrl'] = urlHandlerBuild(
            array_merge($this->renderObject->urlParams, ['sort_by' => 'views'])
        );

        $templatesContext['sortByDatelineUrl'] = urlHandlerBuild(
            array_merge($this->renderObject->urlParams, ['sort_by' => 'dateline'])
        );

        $templatesContext['inlineModeration'] = '';

        $templatesContext['can_manage_entries'] = $this->showcaseObject->user_permissions[ModeratorPermissions::CanManageEntries];

        return template(
            '@ext.myshowcase/pageMain.twig',
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
        bool $isEditPage = false,
        string $entrySlug = '',
    ): void {
        global $lang, $mybb, $db;
        global $header, $headerinclude, $footer, $theme;

        $hookArguments = [
            'this' => &$this,
            'isEditPage' => $isEditPage,
        ];

        $extractVariables = [];

        $hookArguments['extractVariables'] = &$extractVariables;

        $currentUserID = (int)$mybb->user['uid'];

        $entryUserData = get_user($this->showcaseObject->entryUserID);

        $showcaseUserPermissions = $this->showcaseObject->userPermissionsGet($this->showcaseObject->entryUserID);

        $showcase_watermark = '';

        if ($isEditPage) {
            $this->showcaseObject->setEntry($entrySlug, true);
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

        $templatesContext = [];

        $templatesContext['isEditPage'] = $isEditPage;

        $templatesContext['entryPreview'] = '';

        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));

            //$this->showcaseObject->post_hash = $mybb->get_input('post_hash');

            if ($this->showcaseObject->config['attachments_allow_entries']) {
                _dump(965);
                global $mybb;

                echo json_encode([$mybb->input]);
                exit;
                $this->uploadAttachment();
            }

            $isPreview = isset($mybb->input['preview']);

            $insertData = [];

            $insertData['ipaddress'] = $mybb->session->packedip;

            if (!$isEditPage) {
                $insertData['user_id'] = $currentUserID;

                $insertData['ipaddress'] = $mybb->session->packedip;

                $insertData['dateline'] = TIME_NOW;

                if ($this->showcaseObject->post_hash) {
                    $insertData['post_hash'] = $this->showcaseObject->post_hash;
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

            $entrySlug = [];

            foreach ($this->showcaseObject->fieldSetCache as $fieldID => $fieldDefinition) {
                $field_key = $fieldDefinition['field_key'];

                $insertData[$field_key] = match ($fieldDefinition['html_type']) {
                    /*FieldHtmlTypes::CheckBox,
                    FieldHtmlTypes::Select => $mybb->get_input(
                        $field_key,
                        MyBB::INPUT_ARRAY
                    ), */
                    default => $mybb->get_input($field_key),
                };

                if ($fieldDefinition['enable_slug']) {
                    if (is_array($insertData[$field_key])) {
                        foreach ($insertData[$field_key] as $field_data_id) {
                            $field_data_id = (int)$field_data_id;

                            if (!isset($this->showcaseObject->fieldSetCache[$field_data_id])) {
                                continue;
                            }

                            foreach (
                                fieldDataGet(
                                    [
                                        "field_data_id='{$field_data_id}'"
                                    ],
                                    ['value', 'allowed_groups_fill'],
                                    ['order_by' => 'display_order']
                                ) as $field_data_id => $field_data_data
                            ) {
                                if (is_member($field_data_data['allowed_groups_fill'])) {
                                    $entrySlug[] = $field_data_data['value'];
                                }
                            }
                        }
                    } elseif (is_member($fieldDefinition['allowed_groups_fill'])) {
                        $entrySlug[] = $insertData[$field_key];
                    }
                }
            }

            $entrySlug = cleanSlug(implode('-', $entrySlug));

            /* $i = 1;

             while ($this->showcaseObject->dataGet(
                 ["entry_slug='{$db->escape_string($entrySlug)}'", "entry_id!='{$this->showcaseObject->entryID}'"],
                 queryOptions: ['limit' => 1]
             )) {
                 $entrySlug .= '-' . $i;

                 ++$i;
             }*/

            if (!$isEditPage) {
                $insertData['entry_slug_custom'] = $entrySlug;
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
                $this->showcaseObject->entryData = array_merge($this->showcaseObject->entryData, $mybb->input);

                $templatesContext['entryPreview'] = $this->renderObject->buildEntry(true);
            }
        } elseif ($isEditPage) {
            $mybb->input = array_merge($this->showcaseObject->entryData, $mybb->input);
        }

        if ($this->showcaseObject->errorMessages) {
            $templatesContext['errors'] = inline_error($this->showcaseObject->errorMessages);
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

                $fieldObject = fieldGetObject($this->showcaseObject, $fieldDefinition);

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


                $fieldDefinition['entryField'] .= $fieldObject->setUserValue(
                    $this->showcaseObject->entryData[$fieldDefinition['field_key']] ?? ''
                )->renderCreateUpdate($alternativeBackground, $fieldTabIndex);

                ++$fieldTabIndex;

                $alternativeBackground = alt_trow();

                return $fieldDefinition;
            },
            array_filter($this->showcaseObject->fieldSetCache, function ($fieldDefinition): bool {
                return $fieldDefinition['display_in_create_update_page'] &&
                    is_member($fieldDefinition['allowed_groups_fill']);
            })
        );

        $templatesContext['entryFields'] = '';

        foreach ($this->showcaseObject->fieldSetCache as $fieldID => $fieldDefinition) {
            continue;
            $fieldObject = fieldGetObject($this->showcaseObject, $fieldDefinition);

            // todo, field data values shouldn't be NULL ?
            if ($fieldDefinition['html_type'] === FieldHtmlTypes::Radio) {
                //$this->showcaseObject->entryData[$fieldDefinition['field_key']] ??= '';
            }

            $templatesContext['entryFields'] .= $fieldObject->setUserValue(
                $this->showcaseObject->entryData[$fieldDefinition['field_key']] ?? ''
            )->renderCreateUpdate($alternativeBackground, $fieldTabIndex);

            /*
            $field_key = $fieldDefinition['field_key'];

            $htmlType = $fieldDefinition['html_type'];

            $fieldID = $this->showcaseObject->fieldSetFieldsIDs[$field_key] ?? 0;

            $fieldKeyEscaped = $db->escape_string($field_key);

            $fieldTitle = $lang->{'myshowcase_field_' . $field_key} ?? $field_key;

            $fieldElementRequired = '';

            if ($fieldDefinition['is_required']) {
                $fieldElementRequired = 'required="required"';
            }

            $fieldInput = '';

            switch ($htmlType) {
                case FieldHtmlTypes::Text:
                    $fieldValue = htmlspecialchars_uni($mybb->get_input($fieldKey));

                    $fieldInput = eval($this->renderObject->templateGet('pageEntryCreateUpdateDataTextBox'));
                    break;
                case FieldHtmlTypes::Url:
                    $fieldValue = htmlspecialchars_uni($mybb->get_input($fieldKey));

                    $fieldInput = eval($this->renderObject->templateGet('pageEntryCreateUpdateDataTextUrl'));
                    break;
                case FieldHtmlTypes::TextArea:
                    $editorCodeButtons = $editorSmilesInserter = '';

                    $this->renderObject->buildEditor($editorCodeButtons, $editorSmilesInserter, $fieldKey);

                    $fieldValue = htmlspecialchars_uni($mybb->get_input($fieldKey));

                    $fieldInput = eval($this->renderObject->templateGet('pageEntryCreateUpdateDataTextArea'));
                    break;
                case FieldHtmlTypes::Radio:
                    $fieldDataObjects = fieldDataGet(
                        ["set_id='{$this->showcaseObject->config['field_set_id']}'", "field_id='{$fieldID}'"],
                        ['value_id', 'value'],
                        ['order_by' => 'display_order']
                    );

                    if ($fieldDataObjects) {
                        $fieldOptions = [];

                        foreach ($fieldDataObjects as $fieldDataID => $fieldDefinition) {
                            $valueID = (int)$fieldDefinition['value_id'];

                            $valueName = htmlspecialchars_uni($fieldDefinition['value']);

                            $checkedElement = '';

                            if ($mybb->get_input($fieldKey, MyBB::INPUT_INT) === $valueID) {
                                $checkedElement = 'checked="checked"';
                            }

                            $fieldOptions[] = eval(
                            $this->renderObject->templateGet(
                                'pageEntryCreateUpdateDataFieldRadio'
                            )
                            );
                        }

                        $fieldInput = implode('', $fieldOptions);
                    }

                    break;
                case FieldHtmlTypes::CheckBox:
                    $valueID = 1;

                    $valueName = htmlspecialchars_uni($fieldKey);

                    $checkedElement = '';

                    if ($mybb->get_input($fieldKey, MyBB::INPUT_INT) === 1) {
                        $checkedElement = 'checked="checked"';
                    }

                    $fieldInput = eval($this->renderObject->templateGet('pageEntryCreateUpdateDataFieldCheckBox'));
                    break;
                case FieldHtmlTypes::Select:
                    $fieldDataObjects = fieldDataGet(
                        [
                            "set_id='{$this->showcaseObject->config['field_set_id']}'",
                            "field_id='{$fieldID}'"
                        ],
                        ['field_data_id', 'value_id', 'value'],
                        ['order_by' => 'display_order']
                    );

                    if ($fieldDataObjects) {
                        $fieldOptions = [];

                        foreach ($fieldDataObjects as $fieldDataID => $fieldDefinition) {
                            $valueID = (int)$fieldDefinition['field_data_id'];

                            $valueName = htmlspecialchars_uni($fieldDefinition['value']);

                            $selectedElement = '';

                            if ($mybb->get_input($fieldKey, MyBB::INPUT_INT) === $valueID) {
                                $selectedElement = 'selected="selected"';
                            }

                            $fieldOptions[] = eval(
                            $this->renderObject->templateGet(
                                'pageEntryCreateUpdateDataFieldDataBaseOption'
                            )
                            );
                        }

                        $fieldOptions = implode('', $fieldOptions);

                        $fieldInput = eval($this->renderObject->templateGet('pageEntryCreateUpdateDataFieldDataBase'));
                    }
                    break;

                case FieldHtmlTypes::Date:
                    list($mybb->input[$fieldKey . '_month'], $mybb->input[$fieldKey . '_day'], $mybb->input[$fieldKey . '_year']) = array_pad(
                        array_map('intval', explode('|', $mybb->get_input($fieldKey))),
                        3,
                        0
                    );

                    $daySelect = (function (string $fieldKey) use (
                        $mybb,
                        $lang,
                        $fieldTabIndex,
                        $fieldElementRequired,
                    ): string {
                        $valueID = 0;

                        $selectedElement = '';

                        $valueName = $lang->myshowcase_day;

                        $fieldOptions = [
                            eval(
                            $this->renderObject->templateGet(
                                'pageEntryCreateUpdateDataFieldDataBaseOption'
                            )
                            )
                        ];

                        for ($valueID = 1; $valueID <= 31; ++$valueID) {
                            $valueName = $valueID;

                            $selectedElement = '';

                            if ($mybb->get_input($fieldKey . '_day', MyBB::INPUT_INT) === $valueID) {
                                $selectedElement = 'selected="selected"';
                            }

                            $fieldOptions[] = eval(
                            $this->renderObject->templateGet(
                                'pageEntryCreateUpdateDataFieldDataBaseOption'
                            )
                            );
                        }

                        $fieldOptions = implode('', $fieldOptions);

                        $fieldKey = $fieldKey . '_day';

                        return eval($this->renderObject->templateGet('pageEntryCreateUpdateDataFieldDataBase'));
                    })(
                        $fieldKey
                    );

                    $monthSelect = (function (string $fieldKey) use (
                        $mybb,
                        $lang,
                        $fieldTabIndex,
                        $fieldElementRequired,
                    ): string {
                        $valueID = 0;

                        $selectedElement = '';

                        $valueName = $lang->myshowcase_month;

                        $fieldOptions = [
                            eval(
                            $this->renderObject->templateGet(
                                'pageEntryCreateUpdateDataFieldDataBaseOption'
                            )
                            )
                        ];

                        for ($valueID = 1; $valueID <= 12; ++$valueID) {
                            $valueName = $valueID;

                            $selectedElement = '';

                            if ($mybb->get_input($fieldKey . '_month', MyBB::INPUT_INT) === $valueID) {
                                $selectedElement = 'selected="selected"';
                            }

                            $fieldOptions[] = eval(
                            $this->renderObject->templateGet(
                                'pageEntryCreateUpdateDataFieldDataBaseOption'
                            )
                            );
                        }

                        $fieldOptions = implode('', $fieldOptions);

                        $fieldKey = $fieldKey . '_month';

                        return eval($this->renderObject->templateGet('pageEntryCreateUpdateDataFieldDataBase'));
                    })(
                        $fieldKey
                    );

                    $yearSelect = (function (string $fieldKey) use (
                        $mybb,
                        $lang,
                        $fieldTabIndex,
                        $fieldElementRequired,
                        $fieldDefinition
                    ): string {
                        $valueID = 0;

                        $selectedElement = '';

                        $valueName = $lang->myshowcase_year;

                        $fieldOptions = [
                            eval(
                            $this->renderObject->templateGet(
                                'pageEntryCreateUpdateDataFieldDataBaseOption'
                            )
                            )
                        ];

                        for ($valueID = $fieldDefinition['minimum_length']; $valueID <= $fieldDefinition['maximum_length']; ++$valueID) {
                            $valueName = $valueID;

                            $selectedElement = '';

                            if ($mybb->get_input($fieldKey . '_year', MyBB::INPUT_INT) === $valueID) {
                                $selectedElement = 'selected="selected"';
                            }

                            $fieldOptions[] = eval(
                            $this->renderObject->templateGet(
                                'pageEntryCreateUpdateDataFieldDataBaseOption'
                            )
                            );
                        }

                        $fieldOptions = implode('', $fieldOptions);

                        $fieldKey = $fieldKey . '_year';

                        return eval($this->renderObject->templateGet('pageEntryCreateUpdateDataFieldDataBase'));
                    })(
                        $fieldKey
                    );

                    $fieldInput = eval($this->renderObject->templateGet('pageEntryCreateUpdateDataFieldDate'));
                    break;
            }

            $templatesContext['entryFields'] .= $this->renderObject->templateGet('pageEntryCreateUpdateDataField', [
                'alternativeBackground' => $alternativeBackground,
                'fieldData' => $fieldDefinition,
                'fieldHeader' => $fieldHeader,
                'fieldItems' => $fieldItems,
            ]);*/

            ++$fieldTabIndex;

            $alternativeBackground = alt_trow();
        }

        $hookArguments = hooksRun('output_new_end', $hookArguments);

        if ($isEditPage) {
            $templatesContext['createUpdateUrl'] = $this->showcaseObject->urlGenerate(
                RouterUrls::EntryUpdate,
                ['entry_slug' => $this->showcaseObject->entryData['entry_slug']],
                $this->showcaseObject->urlParams
            );
        } else {
            $templatesContext['createUpdateUrl'] = $this->showcaseObject->urlGenerate(
                RouterUrls::EntryCreate,
                $this->showcaseObject->urlParams
            );
        }

        $templatesContext['attachmentsUpload'] = $this->renderObject->buildAttachmentsUpload($isEditPage);

        $hookArguments = hooksRun('entry_create_update_end', $hookArguments);

        extract($hookArguments['extractVariables']);

        if ($templatesContext['entryPreview']) {
            $templatesContext['preview'] = true;
        }

        output_page(
            $this->renderObject->templateGet(
                'pageEntryCreateUpdate',
                $templatesContext,
            )
        );
    }

    #[NoReturn] public function updateEntry(
        string $entrySlug,
    ): void {
        $this->createEntry(true, $entrySlug);
    }

    /**
     * @throws RuntimeError
     * @throws LoaderError
     * @throws SyntaxError
     */
    #[NoReturn] public function viewEntry(
        string $entrySlug,
        string $entrySlugCustom,
        int $currentPage = 1,
    ): void {
        $this->showcaseObject->setEntry($entrySlug, true);

        global $mybb, $lang, $db, $theme;

        //$lang->load('showthread');

        $hookArguments = [
            'this' => &$this
        ];

        $extractVariables = [];

        $hookArguments['extractVariables'] = &$extractVariables;

        $currentUserID = (int)$mybb->user['uid'];

        if (empty($this->showcaseObject->entryID)) {
            $this->showcaseObject->setEntry($entrySlug, true);
        }

        if (!$this->showcaseObject->entryID || empty($this->showcaseObject->entryData)) {
            error($lang->myshowcase_invalid_id);
        }

        if ($currentUserID !== $this->showcaseObject->entryUserID &&
            !$this->showcaseObject->user_permissions[ModeratorPermissions::CanManageEntries] &&
            (int)$this->showcaseObject->entryData['status'] !== ENTRY_STATUS_VISIBLE
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

        $templatesContext = [];

        $templatesContext['entrySubject'] = $this->renderObject->buildEntrySubject();

        $entryUrl = $this->showcaseObject->urlGenerate(
            RouterUrls::EntryView,
            [
                'entry_slug' => $this->showcaseObject->entryData['entry_slug'],
                'entry_slug_custom' => $this->showcaseObject->entryData['entry_slug_custom']
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

        if ($this->showcaseObject->entryData['username'] === '') {
            $this->showcaseObject->entryData['username'] = $lang->guest;
            $this->showcaseObject->entryData['user_id'] = 0;
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
                "entry_id='{$this->showcaseObject->entryID}'",
                "showcase_id='{$this->showcaseObject->showcase_id}'"
            ];

            $statusVisible = COMMENT_STATUS_VISIBLE;

            $statusPendingApproval = COMMENT_STATUS_PENDING_APPROVAL;

            $statusSoftDeleted = COMMENT_STATUS_SOFT_DELETED;

            $whereClausesClauses = [
                "status='{$statusVisible}'",
                "user_id='{$currentUserID}' AND status='{$statusPendingApproval}'",
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

            //$currentPage = $mybb->get_input('page', MyBB::INPUT_INT);

            $totalPages = $totalComments / $this->showcaseObject->config['comments_per_page'];

            $totalPages = ceil($totalPages);

            if ($currentPage > $totalPages || $currentPage <= 0) {
                $currentPage = 1;
            }

            if ($currentPage) {
                $queryOptions['limit_start'] = ($currentPage - 1) * $this->showcaseObject->config['comments_per_page'];
            } else {
                $queryOptions['limit_start'] = 0;

                $currentPage = 1;
            }

            $commentsCounter = 0;

            if ($currentPage > 1) {
                $url_params['page'] = $currentPage;

                $commentsCounter += ($currentPage - 1) * $this->showcaseObject->config['comments_per_page'];
            }

            $urlParams = [
                //'page' => '{page}'
            ];

            //SimpleRouter::get('/product-view/{id}', 'ProductsController@show', ['as' => 'product']);

            $entryUrl = $this->showcaseObject->urlGenerate(
                RouterUrls::EntryView,
                [
                    'entry_slug' => $this->showcaseObject->entryData['entry_slug'],
                    'entry_slug_custom' => $this->showcaseObject->entryData['entry_slug_custom']
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
                $currentPage,
                $this->showcaseObject->urlGenerate(
                    RouterUrls::EntryViewPage,
                    [
                        'entry_slug' => $this->showcaseObject->entryData['entry_slug'],
                        'entry_slug_custom' => $this->showcaseObject->entryData['entry_slug_custom'],
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
                    ['entry_slug' => $this->showcaseObject->entryData['entry_slug']]
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
                !$currentUserID &&
                (
                    ($mybb->session->is_spider && getSetting('ViewsCountSpider')) ||
                    (!$mybb->session->is_spider && getSetting('ViewsCountGuests'))
                )
            ) ||
            (
                $currentUserID && (getSetting('ViewsCountAuthor') ||
                    $currentUserID !== $this->showcaseObject->entryUserID)
            )
        ) {
            $db->shutdown_query(
                "UPDATE {$db->table_prefix}{$this->showcaseObject->dataTableName} SET views=views+1 WHERE entry_id='{$this->showcaseObject->entryID}'"
            );
        }

        $hookArguments = hooksRun('entry_view_end', $hookArguments);

        extract($hookArguments['extractVariables']);

        $templatesContext['canSearch'] = $this->showcaseObject->user_permissions[UserPermissions::CanSearch];

        output_page($this->renderObject->templateGet('pageView', $templatesContext));
    }

    #[NoReturn] public function approveEntry(
        string $entrySlug,
        int $status = ENTRY_STATUS_VISIBLE
    ): void {
        global $lang;

        $this->showcaseObject->setEntry($entrySlug);

        if (!$this->showcaseObject->user_permissions[ModeratorPermissions::CanManageEntries] ||
            !$this->showcaseObject->entryID) {
            error_no_permission();
        }

        $this->showcaseObject->dataUpdate(['status' => $status]);

        $entryUrl = $this->showcaseObject->urlGenerate(
                RouterUrls::EntryView,
                ['entry_slug' => $this->showcaseObject->entryData['entry_slug']]
            ) . '#entryID' . $this->showcaseObject->entryID;

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

        $currentUserID = (int)$mybb->user['uid'];

        $this->showcaseObject->setEntry($entrySlug);

        if (!$this->showcaseObject->entryID ||
            !(
                $this->showcaseObject->user_permissions[ModeratorPermissions::CanManageEntries] ||
                ($this->showcaseObject->entryUserID === $currentUserID && $this->showcaseObject->user_permissions[UserPermissions::CanDeleteEntries])
            ) || !$this->showcaseObject->entryID) {
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

        $currentUserID = (int)$mybb->user['uid'];

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