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
use Random\RandomException;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

use function MyShowcase\Plugin\Functions\attachmentGet;
use function MyShowcase\Plugin\Functions\attachmentUpload;
use function MyShowcase\Plugin\Functions\cacheGet;
use function MyShowcase\Plugin\Functions\fieldDataGet;
use function MyShowcase\Plugin\Functions\fieldGetObject;
use function MyShowcase\Plugin\Functions\formatField;
use function MyShowcase\Plugin\Functions\generateUUIDv4;
use function MyShowcase\Plugin\Functions\getSetting;
use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\moderatorGet;
use function MyShowcase\Plugin\Functions\postParser;

use const MyShowcase\Plugin\Core\FILTER_TYPE_USER_ID;
use const MyShowcase\Plugin\Core\ORDER_DIRECTION_DESCENDING;
use const MyShowcase\Plugin\Core\CACHE_TYPE_ATTACHMENT_TYPES;
use const MyShowcase\Plugin\Core\HTTP_CODE_PERMANENT_REDIRECT;
use const MyShowcase\Plugin\Core\DATA_TABLE_STRUCTURE;
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

        if (!$this->showcase->user_permissions[UserPermissions::CanView]) {
            error_no_permission();
        }
    }

    public int $entryID = 0;

    public array $entryData = [];

    public function verifyPermission(string $entrySlug, array $entryFields = []): int
    {
        global $db;

        if (!(
            $this->showcase->user_permissions[UserPermissions::CanViewEntries] ||
            $this->showcase->user_permissions[ModeratorPermissions::CanManageEntries]
        )) {
            return self::NO_PERMISSION;
        }

        $entryData = $this->showcase->entryManager->queryEntry(
            ["entry_slug='{$db->escape_string($entrySlug)}'"],
            array_merge(['entry_id', 'status', 'user_id'], $entryFields),
        );

        if (empty($entryData)) {
            return self::INVALID_ENTRY;
        }

        $entryStatus = (int)$entryData['status'];

        $entryUserID = (int)$entryData['user_id'];

        if ($entryStatus === $this->showcase->entryManager::STATUS_PENDING && $this->showcase->current_user_id !== $entryUserID ||
            $entryStatus === $this->showcase->entryManager::STATUS_DELETED && !$this->showcase->user_permissions[ModeratorPermissions::CanManageEntries]) {
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

        $entryUrl = $this->showcase->urlGetEntry($entrySlug);

        redirect($entryUrl, HTTP_CODE_PERMANENT_REDIRECT);

        exit;
    }

    /**
     * @throws RuntimeError
     * @throws LoaderError
     * @throws SyntaxError
     */
    #[NoReturn] public function mainView(
        ?int $userID = null,
        int $limit = 0,
        int $limitStart = 0,
        string $groupBy = '',
        string $orderBy = 'user_id',
        string $orderDirection = ORDER_DIRECTION_ASCENDING,
        string $filterField = '',
        mixed $filterValue = '',
    ): string {
        global $lang, $mybb, $db;

        if ($mybb->get_input('action') === 'inline_moderation') {
            switch ($mybb->get_input('moderate')) {
                case 'open':
                    _dump(156, $mybb->input);
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

        if ($this->showcase->config['entries_per_page'] < 1) {
            $this->showcase->config['entries_per_page'] = 1;
        }

        if ($userID !== null) {
            $this->showcase->urlParamsAppend(['user_id' => $userID]);
        }

        if ($mybb->get_input('sort')) {
            $this->showcase->urlParamsAppend(['sort' => $mybb->get_input('sort')]);
        }

        if ($mybb->get_input('direction')) {
            $this->showcase->urlParamsAppend(['direction' => $mybb->get_input('direction')]);
        }

        if ($mybb->get_input('days', MyBB::INPUT_INT) > 0) {
            $this->showcase->urlParamsAppend(['days' => $mybb->get_input('days', MyBB::INPUT_INT)]);
        }

        $templatesContext = [
            'meta' => [
                'canonical' => $this->showcase->urlGenerate(RouterUrls::Main),
                'page' => [
                    [
                        'property' => 'og:description',
                        'content' => $this->showcase->config['description'],
                    ],
                    [
                        'property' => 'og:title',
                        'content' => $this->showcase->config['name'],
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
                'field' => $this->showcase->sort_field,
                'direction' => $this->showcase->sort_direction,
            ],
            'inline_selected_count' => 0,
            'url' => [
                'sort' => [
                    'subject' => $this->showcase->urlGenerate(
                        RouterUrls::Main,
                        array_merge(
                            $this->showcase->url_params,
                            [
                                'sort' => 'subject',
                                'direction' => $this->showcase->sort_direction === ORDER_DIRECTION_ASCENDING ? ORDER_DIRECTION_DESCENDING : ORDER_DIRECTION_ASCENDING
                            ]
                        )
                    ),
                    'username' => $this->showcase->urlGenerate(
                        RouterUrls::Main,
                        array_merge($this->showcase->url_params, [
                            'sort' => 'username',
                            'direction' => $this->showcase->sort_direction === ORDER_DIRECTION_ASCENDING ? ORDER_DIRECTION_DESCENDING : ORDER_DIRECTION_ASCENDING
                        ])
                    ),
                    'comments' => $this->showcase->urlGenerate(
                        RouterUrls::Main,
                        array_merge($this->showcase->url_params, [
                            'sort' => 'comments',
                            'direction' => $this->showcase->sort_direction === ORDER_DIRECTION_ASCENDING ? ORDER_DIRECTION_DESCENDING : ORDER_DIRECTION_ASCENDING
                        ])
                    ),
                    'last_comment' => $this->showcase->urlGenerate(
                        RouterUrls::Main,
                        array_merge($this->showcase->url_params, [
                            'sort' => 'dateline',
                            'direction' => $this->showcase->sort_direction === ORDER_DIRECTION_ASCENDING ? ORDER_DIRECTION_DESCENDING : ORDER_DIRECTION_ASCENDING
                        ])
                    ),
                ],
            ],
            'entry_normal_objects' => [],
            'entry_sticky_objects' => [],
        ];


        // Fetch each moderator from the cache and format it, appending it to the list
        foreach (
            moderatorGet(["showcase_id='{$this->showcase->showcase_id}'"], ['user_id', 'is_group']) as $moderator
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
                "uid = 0 AND time > $time_cut AND location LIKE '%{$this->showcase->selfPhpScript}%' AND nopermission != 1"
            );

            $templatesContext['users_browsing_counter']['guests'] = $db->fetch_field($query, 'guest_count');

            $query = $db->simple_select(
                "sessions s LEFT JOIN {$db->table_prefix}users u ON (s.uid=u.uid)",
                's.ip, s.uid, u.username, u.avatar, s.time, u.invisible, u.usergroup, u.usergroup, u.displaygroup',
                "s.uid != 0 AND s.time > $time_cut AND location LIKE '%{$this->showcase->selfPhpScript}%' AND nopermission != 1",
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
                        (int)$user_data['uid'] === $this->showcase->current_user_id) {
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

        switch ($this->showcase->config['filter_force_field']) {
            case FILTER_TYPE_USER_ID:
                $this->showcase->urlParamsAppend(
                    ['user_id' => $userID ?? $this->showcase->current_user_id]
                );

                $userData = get_user($this->showcase->url_params['user_id']);

                if (empty($userData['uid']) || (empty($mybb->usergroup['canviewprofiles']) && (int)$userData['uid'] !== $this->showcase->current_user_id)) {
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

        $mainUrl = $this->showcase->urlGenerate(
            RouterUrls::Main,
            $this->showcase->url_params
        );

        add_breadcrumb(
            $this->showcase->config['name_friendly'],
            $mainUrl
        );

        if ($this->showcase->user_permissions[UserPermissions::CanCreateEntries]) {
            $templatesContext['url_entry_create'] = $this->showcase->urlGenerate(
                RouterUrls::EntryCreate,
            );
        }

        $templatesContext['fieldSetFieldsOrder'] = $this->showcase->fieldSetFieldsOrder;

        $templatesContext['fieldSetCache'] = array_map(
            function (array &$fieldDefinition) use (&$templatesContext): array {
                $templatesContext['url']['sort'][$fieldDefinition['field_key']] = $this->showcase->urlGenerate(
                    RouterUrls::Main,
                    array_merge($this->showcase->url_params, [
                        'sort' => $fieldDefinition['field_key'],
                        'direction' => $this->showcase->sort_direction === ORDER_DIRECTION_ASCENDING ? ORDER_DIRECTION_DESCENDING : ORDER_DIRECTION_ASCENDING
                    ])
                );

                return $fieldDefinition;
            },
            array_filter($this->showcase->fields_cache, function ($fieldDefinition): bool {
                return $fieldDefinition['display_in_create_update_page'] &&
                    is_member($fieldDefinition['allowed_groups_fill']);
            })
        );

        foreach ($this->showcase->fieldSetFieldsOrder as $renderOrder => $fieldID) {
            $field_key = $this->showcase->fields_cache[$fieldID]['field_key'];

            if (!isset($lang->{"myshowcase_fieldset_field_{$field_key}"})) {
                $lang->{"myshowcase_fieldset_field_{$field_key}"} = $field_key;
            }
        }

        $this->showcase->entryManager->fetchEntries(
            $this->showcase->user_permissions,
            $this->showcase->fields_cache,
            $this->showcase->fields_searchable,
            url_params: $this->showcase->url_params,
            search_keywords: $this->showcase->search_keywords,
            search_field: $this->showcase->search_field,
            search_exact: $this->showcase->search_exact,
            sort_direction: $this->showcase->sort_direction,
            sort_field: $this->showcase->sort_field,
        );

        $templatesContext['entries_count'] = $this->showcase->entryManager->getTotalEntries();

        $hookArguments = hooksRun('output_main_intermediate', $hookArguments);

        if ($templatesContext['entries_count']) {
            $templatesContext['pagination'] = multipage(
                $templatesContext['entries_count'],
                $this->showcase->config['entries_per_page'],
                $this->showcase->entryManager->getCurrentPage(),
                $this->showcase->config['url_showcase'] . '/' . $this->showcase->urlGenerate(
                    RouterUrls::Main,
                    $this->showcase->url_params
                ),
            );

            foreach ($this->showcase->entryManager->getEntries() as $entry_data) {
                $this->showcase->entriesAppend($entry_data);
            }

            // get first attachment for each showcase on this page
            if ($this->showcase->config['attachments_main_render_first']) {
                $this->showcase->attachmentsAppend(
                    attachmentGet(
                        [
                            "showcase_id='{$this->showcase->showcase_id}'",
                            "entry_id IN ('" . implode("','", $this->showcase->entryManager->getEntriesIDs()) . "')",
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

            $inlinecookie = 'inlinemod_forum' . $this->showcase->showcase_id;

            foreach ($this->showcase->entryManager->getEntries() as $entry_id => $entry_data) {
                $entry_data->setExtraData('unread_comments', false)
                    ->setExtraData('modChecked', false);

                $entry_data->setExtraData('url', '');

                if ($this->showcase->user_permissions[ModeratorPermissions::CanManageEntries] &&
                    isset($mybb->cookies[$inlinecookie]) &&
                    my_strpos($mybb->cookies[$inlinecookie], "|{$entry_data->entry_slug}|") !== false) {
                    $entry_data->setExtraData('modChecked', true);

                    ++$templatesContext['inline_selected_count'];

                    $this->showcase->entryDataSet($entry_data);

                    $entryStatus = $entry_data->status;

                    if (!$entry_data->getExtraData('username')) {
                        $entry_data->setExtraData('username', $lang->guest);
                    }

                    $entry_data->setExtraData(
                        'url',
                        $this->showcase->urlGenerate(
                            RouterUrls::EntryView,
                            [
                                'entry_slug' => $this->showcase->entry_data['entry_slug'],
                                'entry_slug_custom' => $this->showcase->entry_data['entry_slug_custom']
                            ],
                            $this->showcase->url_params
                        )
                    );

                    $viewLastCommentID = 0; // todo, show last comment ID in the list view
                }

                $entry_data
                    ->setExtraData(
                        'entryUrlLastComment',
                        str_replace(
                            '{entry_id}',
                            (string)$entry_data->entry_id,
                            $this->showcase->urlViewComment
                        )
                    )
                    //build a link for the list view, starting with basic text
                    ->setExtraData(
                        'attachments',
                        $this->showcase->attachmentsGetByEntryID($entry_data->entry_id)
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

                $entrySubject = $fieldCache = [];

                foreach ($this->showcase->fields_cache as $fieldID => $fieldDefinition) {
                    $entryFieldText = $entry_data->getExtraData($fieldDefinition['field_key']) ?? '';

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
                        $entryFieldText = $this->showcase->parseMessage($entryFieldText);
                    }

                    $fieldCache[$fieldID] = $entryFieldText;

                    if ($fieldDefinition['enable_subject'] && !empty($entryFieldText)) {
                        $entrySubject[] = $entryFieldText;
                    }
                }

                $entry_data
                    ->setExtraData('fields_cache', $fieldCache)
                    ->setExtraData(
                        'subject',
                        trim(
                            implode(' ', $entrySubject)
                        ) ?? $lang->myShowcaseMainTableHeadView
                    )
                    ->setExtraData('moderator_check', false);

                if ($this->showcase->search_keywords) {
                    $entry_data->setExtraData(
                        'subject',
                        postParser()->highlight_message(
                            $entry_data->getExtraData('subject'),
                            ['highlight' => $this->showcase->search_keywords]
                        )
                    );
                }

                if ($this->showcase->user_permissions[ModeratorPermissions::CanManageEntries] &&
                    isset($mybb->cookies['inlinemod_showcase' . $this->showcase->showcase_id]) &&
                    my_strpos(
                        $mybb->cookies['inlinemod_showcase' . $this->showcase->showcase_id],
                        "|{$entry_data->entry_id}|"
                    ) !== false) {
                    $entry_data->setExtraData('moderator_check', true);
                }

                if ($entry_data->visible == $this->showcase->entryManager::STATUS_PENDING) {
                    $entry_data->setExtraData('background_class', 'trow_shaded');
                } elseif ($entry_data->visible == $this->showcase->entryManager::STATUS_DELETED && $templatesContext['permissions']['can_manage_entries]']) {
                    $entry_data->setExtraData('background_class', 'trow_shaded trow_deleted');
                }

                $comment_data = $this->showcase->commentManager->queryComment(
                    ["entry_id={$entry_data->entry_id}"],
                    ['comment_slug', 'showcase_id', 'entry_id', 'user_id', 'dateline'],
                    [
                        'order_by' => 'dateline',
                        'order_dir' => ORDER_DIRECTION_DESCENDING
                    ]
                );

                $entry_data
                    ->setExtraData('last_commenter_user_data', get_user($comment_data['user_id']))
                    ->setExtraData(
                        'last_comment_url',
                        $comment_data['comment_slug'] ? $this->showcase->urlGenerate(
                            RouterUrls::Comment,
                            ['comment_slug' => $comment_data['comment_slug']]
                        ) : ''
                    )
                    ->setExtraData('last_comment_date', $comment_data['dateline'])
                    ->setExtraData(
                        'attachments_count',
                        attachmentGet(
                            [
                                "entry_id='{$this->showcase->entry_id}'",
                                "showcase_id='{$this->showcase->showcase_id}'"
                            ],
                            [
                                'COUNT(attachment_id) AS attachment_count'
                            ],
                            ['limit' => 1],
                        )['attachment_count'] ?? 0
                    )
                    ->setExtraData(
                        'comments_by_you',
                        $this->showcase->commentManager->queryCommentCount(
                            [
                                "entry_id='{$this->showcase->entry_id}'",
                                "user_id='{$this->showcase->current_user_id}'",
                            ]
                        )
                    );

                if (empty($entry_data->is_sticky)) {
                    $templatesContext['entry_normal_objects'][$entry_id] = $entry_data;
                } else {
                    $templatesContext['entry_sticky_objects'][$entry_id] = $entry_data;
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
        $statusUnapproved = $this->showcase->entryManager::STATUS_PENDING;

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

        $entryUserData = get_user($this->showcase->entryUserID);

        $showcaseUserPermissions = $this->showcase->userPermissionsGet($this->showcase->entryUserID);

        if ($isEditPage) {
            $this->showcase->setEntry($entry_slug, true);
        }

        switch ($this->showcase->config['filter_force_field']) {
            case FILTER_TYPE_USER_ID:
                $this->showcase->urlParamsAppend(
                    ['user_id' => $userID ?? $this->showcase->current_user_id]
                );

                $userData = get_user($this->showcase->url_params['user_id']);

                if (empty($userData['uid']) || (empty($mybb->usergroup['canviewprofiles']) && (int)$userData['uid'] !== $this->showcase->current_user_id)) {
                    error_no_permission();
                }

                $lang->load('member');

                $userName = htmlspecialchars_uni($userData['username']);

                add_breadcrumb(
                    $lang->sprintf($lang->nav_profile, $userName),
                    $mybb->settings['bburl'] . '/' . get_profile_link($userData['uid'])
                );

                break;
            default:
                $mainUrl = $this->showcase->urlGenerate(
                    RouterUrls::Main,
                    $this->showcase->url_params
                );

                add_breadcrumb($this->showcase->config['name_friendly'], $mainUrl);
        }

        if ($isEditPage) {
            add_breadcrumb(
                $lang->myShowcaseButtonEntryUpdate,
                $this->showcase->urlGenerate(
                    RouterUrls::EntryUpdate,
                    [
                        'entry_slug' => $this->showcase->entry_data['entry_slug'],
                        'entry_slug_custom' => $this->showcase->entry_data['entry_slug_custom'],
                    ]
                )
            );
        } else {
            add_breadcrumb(
                $lang->myShowcaseButtonEntryCreate,
                $this->showcase->urlGenerate(
                    RouterUrls::EntryCreate,
                ),
            );
        }

        $this->showcase->urlParamsAppend([
            'entry_slug' => $this->showcase->entry_data['entry_slug']
        ]);

        if (!$isEditPage && empty($this->showcase->entry_data['post_hash'])) {
            if ($mybb->get_input('post_hash')) {
                $this->showcase->entryDataSet(['post_hash' => $mybb->get_input('post_hash')]);
            } else {
                $this->showcase->entryDataSet(['post_hash' => generateUUIDv4()]);
            }
        }

        $templatesContext = [
            'url' => [
                'crate_update' => $isEditPage ? $this->showcase->urlGenerate(
                    RouterUrls::EntryUpdate,
                    array_merge($this->showcase->url_params, [
                        'entry_slug' => $this->showcase->entry_data['entry_slug'],
                        'entry_slug_custom' => $this->showcase->entry_data['entry_slug_custom'],
                    ])
                ) : $this->showcase->urlGenerate(
                    RouterUrls::EntryCreate,
                    $this->showcase->url_params
                ),
                'upload' => $isEditPage ? $this->showcase->urlGenerate(
                    RouterUrls::EntryUpdateUpload,
                    [
                        'entry_slug' => $this->showcase->entry_data['entry_slug'],
                        'entry_slug_custom' => $this->showcase->entry_data['entry_slug_custom'],
                        'my_post_key' => $mybb->post_code,
                    ]
                ) : $this->showcase->urlGenerate(
                    RouterUrls::EntryCreateUpload,
                    [
                        'post_hash' => $this->showcase->entry_data['post_hash'],
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

                    foreach ($this->showcase->fields_cache as $fied_id => $field_definition) {
                        $default_fields[$field_definition['field_key']] = match ($field_definition['default_type']) {
                            FieldDefaultTypes::AsDefined => $field_definition['default_value'],
                            FieldDefaultTypes::CurrentTimestamp => date('Y-m-d H:i:s', TIME_NOW),
                            FieldDefaultTypes::UUID => generateUUIDv4(),
                            default => '',
                        };
                    }

                    return $default_fields;
                })(),
                $this->showcase->entry_data,
                $mybb->input
            ),
            'attachment_objects' => $this->showcase->attachmentsGetFromEntry(),
        ];

        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));

            //$this->showcaseObject->post_hash = $mybb->get_input('post_hash');

            $isPreview = isset($mybb->input['preview']);

            $insertData = [];

            $insertData['ipaddress'] = $mybb->session->packedip;

            if (!$isEditPage) {
                $insertData['user_id'] = $this->showcase->current_user_id;

                $insertData['ipaddress'] = $mybb->session->packedip;

                $insertData['dateline'] = TIME_NOW;

                if ($this->showcase->entry_data['post_hash']) {
                    $insertData['post_hash'] = $this->showcase->entry_data['post_hash'];
                }
            }

            if ($isEditPage && (
                    $this->showcase->config['moderate_entries_update'] ||
                    $this->showcase->user_permissions[UserPermissions::ModerateEntryUpdate]
                )) {
                $insertData['status'] = $this->showcase->entryManager::STATUS_PENDING;
            } elseif (!$isEditPage && $this->showcase->config['moderate_entries_create'] && (
                    $this->showcase->config['moderate_entries_create'] ||
                    $this->showcase->user_permissions[UserPermissions::ModerateEntryCreate]
                )) {
                $insertData['status'] = $this->showcase->entryManager::STATUS_PENDING;
            }

            // Set up a showcase handler.
            //Require_once MYBB_ROOT . 'inc/datahandlers/myshowcase_dh.php';

            if ($isEditPage) {
                $dataHandler = $this->showcase->dataHandler($this->showcase::DATA_HANDLER_METHOD_UPDATE);
            } else {
                $insertData['dateline'] = TIME_NOW;

                $dataHandler = $this->showcase->dataHandler();
            }

            foreach ($this->showcase->fields_cache as $fieldID => $fieldDefinition) {
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
                $this->showcase->errorMessages = array_merge(
                    $this->showcase->errorMessages,
                    $dataHandler->get_friendly_errors()
                );
            }

            if (!$isPreview && !$this->showcase->errorMessages) {
                if ($isEditPage) {
                    $dataHandler->updateEntry();
                } else {
                    $dataHandler->entryInsert();
                }

                if (isset($dataHandler->returnData['status']) && $dataHandler->returnData['status'] === $this->showcase->entryManager::STATUS_DELETED) {
                    $mainUrl = $this->showcase->urlGenerate(
                        RouterUrls::Main,
                        $this->showcase->url_params
                    );

                    redirect(
                        $mainUrl,
                        $isEditPage ? $lang->myShowcaseEntryEntryUpdatedStatus : $lang->myShowcaseEntryEntryCreatedStatus
                    );
                } else {
                    $entryUrl = $this->showcase->urlGenerate(
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
                $this->showcase->entry_data = array_merge(
                    array_map(
                        function ($field_definition) {
                            return $field_definition['default'] ?? '';
                        },
                        DATA_TABLE_STRUCTURE['myshowcase_data']
                    ),
                    $this->showcase->entry_data,
                    $mybb->input
                );

                $templatesContext['entry_preview'] = $this->renderObject->buildEntry(true);

                $templatesContext['is_preview_page'] = true;
            }
        }

        if ($isEditPage && !$this->showcase->user_permissions[UserPermissions::CanUpdateEntries]) {
            error_no_permission();
        } elseif (!$isEditPage && !$this->showcase->user_permissions[UserPermissions::CanCreateEntries]) {
            error_no_permission();
        }

        $hookArguments = hooksRun('output_new_start', $hookArguments);

        $alternativeBackground = alt_trow(true);

        $fieldTabIndex = 1;

        $templatesContext['fieldSetCache'] = array_map(
            function (array &$fieldDefinition) use (&$alternativeBackground, &$fieldTabIndex): array {
                $field_id = (int)$fieldDefinition['field_id'];

                $fieldObject = fieldGetObject(
                    $this->showcase,
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
                        $this->showcase->renderObject->buildEditor(
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
                        )[$this->showcase->showcase_id] ?? [];

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
            array_filter($this->showcase->fields_cache, function ($fieldDefinition): bool {
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
        $this->showcase->setEntry($entry_slug, true);

        global $mybb, $lang, $db, $theme;

        $this->renderObject->current_page = $mybb->get_input('page', MyBB::INPUT_INT);

        //$lang->load('showthread');

        $hookArguments = [
            'this' => &$this
        ];

        $extractVariables = [];

        $hookArguments['extractVariables'] = &$extractVariables;

        if (empty($this->showcase->entry_id)) {
            $this->showcase->setEntry($entry_slug, true);
        }

        $entry_data = $this->showcase->entryManager->getEntryByID($this->showcase->entry_id);

        if (!$this->showcase->entry_id || empty($entry_data)) {
            error($lang->myshowcase_invalid_id);
        }

        if ($this->showcase->current_user_id !== $this->showcase->entryUserID &&
            !$this->showcase->user_permissions[ModeratorPermissions::CanManageEntries] &&
            $entry_data->status !== $this->showcase->entryManager::STATUS_VISIBLE
        ) {
            error($lang->myshowcase_invalid_id);
        }

        switch ($this->showcase->config['filter_force_field']) {
            case FILTER_TYPE_USER_ID:
                $this->showcase->urlParamsAppend(
                    ['user_id' => $userID ?? $this->showcase->current_user_id]
                );

                $userData = get_user($this->showcase->url_params['user_id']);

                if (empty($userData['uid']) || (empty($mybb->usergroup['canviewprofiles']) && (int)$userData['uid'] !== $this->showcase->current_user_id)) {
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

        $mainUrl = $this->showcase->urlGenerate(
            RouterUrls::Main,
            $this->showcase->url_params
        );

        add_breadcrumb(
            $this->showcase->config['name_friendly'],
            $mainUrl
        );

        $templatesContext = [
            'entry_data' => &$entry_data,
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
                'entry' => $this->showcase->urlGenerate(
                    RouterUrls::EntryView,
                    [
                        'entry_slug' => $entry_data->entry_slug,
                        'entry_slug_custom' => $entry_data->entry_slug_custom
                    ]
                ),
                'comment' => $this->showcase->urlGenerate(
                    RouterUrls::CommentCreate,
                    [
                        'entry_slug' => $entry_data->entry_slug,
                        'entry_slug_custom' => $entry_data->entry_slug_custom,
                    ]
                ),
            ]
        ];

        $templatesContext['subject'] = $this->renderObject->buildEntrySubject();

        $entryUrl = $this->showcase->urlGenerate(
            RouterUrls::EntryView,
            [
                'entry_slug' => $entry_data->entry_slug,
                'entry_slug_custom' => $entry_data->entry_slug_custom
            ],
        );

        $templatesContext['page_canonical'] = $this->showcase->url_base . $entryUrl;

        $templatesContext['page_meta'] = [
            [
                'property' => 'og:url',
                'content' => $this->showcase->url_base . $entryUrl,
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
            $templatesContext['subject'],
            $entryUrl
        );

        if (!$entry_data->getExtraData('username')) {
            $entry_data->setExtraData('username', $lang->guest);
        }

        $entryUrl = str_replace(
            '{entry_id}',
            (string)$mybb->get_input('entry_id'),
            $this->showcase->urlViewEntry
        );

        //$this->showcaseObject->post_hash = $this->showcaseObject->entryData['post_hash'];

        //trick MyBB into thinking its in archive so it adds bburl to smile link inside parser
        //doing this now should not impact anyhting. no issues with gomobile beta4
        define('IN_ARCHIVE', 1);

        $entry_data->setRenderEntry(
            $this->renderObject->buildEntry()
        );

        $templatesContext['commentsForm'] = '';

        if ($this->showcase->config['comments_allow'] && $this->showcase->user_permissions[UserPermissions::CanViewComments]) {
            $url_params['page'] = $this->renderObject->current_page;

            $this->showcase->commentManager->fetchComments(
                $this->showcase->entry_id,
                $this->showcase->user_permissions
            );

            $templatesContext['commentObjects'] = [];

            $commentsCounter = 0;

            if ($this->showcase->commentManager->getCurrentPage()) {
                $commentsCounter += ($this->showcase->commentManager->getCurrentPage() - 1) *
                    $this->showcase->config['comments_per_page'];
            }

            $templatesContext['rendered_comments'] = [];

            $templatesContext['pagination'] = multipage(
                $this->showcase->commentManager->getTotalComments(),
                $this->showcase->config['comments_per_page'],
                $this->showcase->commentManager->getCurrentPage(),
                $this->showcase->config['url_showcase'] . '/' . $this->showcase->urlGenerate(
                    RouterUrls::EntryView,
                    array_merge($this->showcase->url_params, [
                        'entry_slug' => $entry_data->entry_slug,
                        'entry_slug_custom' => $entry_data->entry_slug_custom,
                        'page_id' => '{page}'
                    ])
                )
            );

            foreach ($this->showcase->commentManager->getTopLevelComments() as $comment_data) {
                ++$commentsCounter;

                $comment_data->setRenderComment(
                    $this->renderObject->buildComment(
                        $comment_data,
                        $commentsCounter,
                    )
                );

                $templatesContext['commentObjects'][$comment_data->comment_id] = $comment_data;
            }

            $hookArguments = hooksRun('entry_view_comment_form_end', $hookArguments);

            $templatesContext['can_create_comments'] = $this->showcase->user_permissions[UserPermissions::CanCreateComments];

            if ($this->showcase->user_permissions[UserPermissions::CanCreateComments]) {
                global $collapsedthead, $collapsedimg, $expaltext, $collapsed;

                isset($collapsedthead) || $collapsedthead = [];

                isset($collapsedimg) || $collapsedimg = [];

                isset($collapsed) || $collapsed = [];

                $collapsedthead['quickreply'] ??= '';

                $collapsedimg['quickreply'] ??= '';

                $collapsed['quickreply_e'] ??= '';

                $templatesContext['commentLengthLimitNote'] = $lang->sprintf(
                    $lang->myshowcase_comment_text_limit,
                    my_number_format($this->showcase->config['comments_minimum_length']),
                    my_number_format($this->showcase->config['comments_maximum_length'])
                );

                $templatesContext['createUpdateUrl'] = $this->showcase->urlGenerate(
                    RouterUrls::CommentCreate,
                    [
                        'entry_slug' => $entry_data->entry_slug,
                        'entry_slug_custom' => $entry_data->entry_slug_custom,
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
                !$this->showcase->current_user_id &&
                (
                    ($mybb->session->is_spider && getSetting('ViewsCountSpider')) ||
                    (!$mybb->session->is_spider && getSetting('ViewsCountGuests'))
                )
            ) ||
            (
                $this->showcase->current_user_id && (getSetting('ViewsCountAuthor') ||
                    $this->showcase->current_user_id !== $this->showcase->entryUserID)
            )
        ) {
            $db->shutdown_query(
                "UPDATE {$db->table_prefix}{$this->showcase->dataTableName} SET views=views+1 WHERE entry_id='{$this->showcase->entry_id}'"
            );
        }

        $hookArguments = hooksRun('entry_view_end', $hookArguments);

        extract($hookArguments['extractVariables']);

        if ($this->showcase->user_permissions[UserPermissions::CanCreateComments] &&
            empty($mybb->user['suspendposting']) &&
            (!$entry_data->is_closed || $this->showcase->user_permissions[ModeratorPermissions::CanManageEntries]) &&
            !empty($mybb->settings['quickreply']) &&
            !empty($mybb->user['showquickreply']) &&
            $entry_data->user_id === $this->showcase->current_user_id ||
            true) {
            $templatesContext['showquickreply'] = true;

            // Show captcha image for guests if enabled
            if ($mybb->settings['captchaimage'] && !$this->showcase->current_user_id or true) {
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

    /**
     * @throws RandomException
     */
    #[NoReturn] public function approveEntry(
        string $entrySlug,
        int $status
    ): void {
        global $lang;

        $this->showcase->setEntry($entrySlug);

        if (!$this->showcase->user_permissions[ModeratorPermissions::CanManageEntries] ||
            !$this->showcase->entry_id) {
            error_no_permission();
        }

        $this->showcase->entryManager->updateEntry(['status' => $status], $this->showcase->entry_id);

        $entryUrl = $this->showcase->urlGenerate(
                RouterUrls::EntryView,
                ['entry_slug' => $this->showcase->entry_data['entry_slug']]
            ) . '#entry_id' . $this->showcase->entry_id;

        switch ($status) {
            case $this->showcase->entryManager::STATUS_PENDING:
                $redirectMessage = $lang->myShowcaseEntryEntryUnapproved;
                break;
            case $this->showcase->entryManager::STATUS_VISIBLE:
                $redirectMessage = $lang->myShowcaseEntryEntryApproved;
                break;
            case $this->showcase->entryManager::STATUS_DELETED:
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
            $this->showcase->entryManager::STATUS_PENDING
        );
    }

    #[NoReturn] public function softDeleteEntry(
        string $entrySlug
    ): void {
        $this->approveEntry(
            $entrySlug,
            $this->showcase->entryManager::STATUS_DELETED
        );
    }

    #[NoReturn] public function restoreEntry(
        string $entrySlug
    ): void {
        $this->approveEntry(
            $entrySlug,
            $this->showcase->entryManager::STATUS_VISIBLE
        );
    }

    #[NoReturn] public function deleteEntry(
        string $entrySlug
    ): void {
        global $mybb, $lang;

        $this->showcase->setEntry($entrySlug);

        if (!$this->showcase->entry_id ||
            !(
                $this->showcase->user_permissions[ModeratorPermissions::CanManageEntries] ||
                ($this->showcase->entryUserID === $this->showcase->current_user_id && $this->showcase->user_permissions[UserPermissions::CanDeleteEntries])
            ) || !$this->showcase->entry_id) {
            error_no_permission();
        }

        $this->showcase->entryManager->deleteEntry($this->showcase->entry_id, $this->showcase->commentManager);

        $mainUrl = $this->showcase->urlGenerate(
            RouterUrls::Main,
            $this->showcase->url_params
        );

        redirect($mainUrl, $lang->myShowcaseEntryEntryDeleted);

        exit;
    }

    #[NoReturn] private function uploadAttachment(): void
    {
        return;
        if (!$this->showcase->config['attachments_allow_entries']) {
            return;
        }

        global $mybb;

        $is_upload_request =
            isset($_SERVER['HTTP_UPLOAD_LENGTH']) ||
            isset($_SERVER['HTTP_UPLOAD_OFFSET']) ||
            isset($_SERVER['HTTP_UPLOAD_FILENAME']);

        if (!$is_upload_request) {
            _dump(128, $mybb->input);
            return;
        }

        _dump(119, $mybb->request_method);

        verify_post_check($mybb->get_input('my_post_key'));

        _dump(774, $mybb->request_method);

        echo json_encode([$mybb->input]);
        exit;

        $process_file = (
            !empty($_FILES['attachment']) &&
            !empty($_FILES['attachment']['name'])
        );

        if (!$process_file) {
            return;
        }

        if (!$this->showcase->user_permissions[UserPermissions::CanUploadAttachments]) {
            error_no_permission();
        }

        global $mybb, $lang;

        $mybb->input['preview'] = true;

        require_once MYBB_ROOT . 'inc/functions_upload.php';

        $fileObject = attachmentUpload(
            $this->showcase,
            $_FILES['attachment'],
            watermarkImage: $mybb->get_input('attachment_watermark_file', MyBB::INPUT_BOOL),
        );

        if (isset($fileObject['error'])) {
            $this->showcase->errorMessages = array_merge(
                $this->showcase->errorMessages,
                (array)$fileObject['error']
            );
        }
    }
}

//todo review hooks here