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
use JetBrains\PhpStorm\NoReturn;
use MyBB;
use MyShowcase\Entity\Entry;
use MyShowcase\Enum\FieldDefaultTypes;
use MyShowcase\Enum\FieldHtmlTypes;
use MyShowcase\Enum\ModeratorPermissions;
use MyShowcase\Enum\UserPermissions;
use MyShowcase\Enum\Routes;
use Random\RandomException;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

use function MyShowcase\Plugin\Functions\fieldDataGet;
use function MyShowcase\Plugin\Functions\formatField;
use function MyShowcase\Plugin\Functions\generateUUIDv4;
use function MyShowcase\Plugin\Functions\getSetting;
use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\moderatorGet;
use function MyShowcase\Plugin\Functions\postParser;

use const MyShowcase\Plugin\Core\DATA_TABLE_STRUCTURE;
use const MyShowcase\Plugin\Core\FILTER_TYPE_USER_ID;
use const MyShowcase\Plugin\Core\HTTP_CODE_PERMANENT_REDIRECT;
use const MyShowcase\Plugin\Core\ORDER_DIRECTION_ASCENDING;
use const MyShowcase\Plugin\Core\ORDER_DIRECTION_DESCENDING;

class Entries extends Base
{
    #[NoReturn] public function redirect(string $entry_slug): void
    {
        switch ($this->verifyPermission($entry_slug)) {
            case self::NO_PERMISSION:
                error_no_permission();

                break;
            case self::INVALID_ENTRY:
                global $lang;

                error($lang->myShowcaseReportErrorInvalidEntry);
        }

        $entry_url = $this->showcase->config['url_showcase'] . '/' . $this->routing->generateUrl(
                Routes::EntryView,
                array_merge(
                    $this->showcase->url_params,
                    [
                        'entry_slug' => $entry_data->entry_slug,
                        'entry_slug_custom' => $entry_data->entry_slug_custom
                    ]
                ),
            );

        header("Location: {$entry_url}", response_code: HTTP_CODE_PERMANENT_REDIRECT);

        exit;
    }

    public function forcedFieldBreadcrumb(): void
    {
        switch ($this->showcase->config['filter_force_field']) {
            case FILTER_TYPE_USER_ID:
                $user_data = get_user($this->showcase->filter_force_field_value);

                if (empty($user_data['uid']) || (empty($mybb->usergroup['canviewprofiles']) && (int)$user_data['uid'] !== $this->showcase->current_user_id)) {
                    error_no_permission();
                }

                global $mybb, $lang;

                $lang->load('member');

                add_breadcrumb(
                    $lang->sprintf($lang->nav_profile, htmlspecialchars_uni($user_data['username'])),
                    $mybb->settings['bburl'] . '/' . get_profile_link($user_data['uid'])
                );

                break;
        }
    }

    /**
     * @throws RuntimeError
     * @throws LoaderError
     * @throws SyntaxError
     */
    public function main(): string
    {
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

        if ($this->showcase->config['entries_per_page'] < 1) {
            $this->showcase->config['entries_per_page'] = 1;
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

        $this->forcedFieldBreadcrumb();

        if ($this->showcase->user_permissions[UserPermissions::CanCreateEntries]) {
            $templatesContext['url_entry_create'] = $this->routing->generateUrl(
                Routes::EntryCreate,
            );
        }

        $templatesContext['fieldSetFieldsOrder'] = $this->showcase->fieldSetFieldsOrder;

        $templatesContext['fieldSetCache'] = array_map(
            function (array &$fieldDefinition) use (&$templatesContext): array {
                $templatesContext['url']['sort'][$fieldDefinition['field_key']] = $this->routing->generateUrl(
                    Routes::Main,
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

        foreach (
            $this->showcase->entries_manager->fetchEntries(
                url_params: $this->showcase->url_params,
                search_keywords: $this->showcase->search_keywords,
                search_field: $this->showcase->search_field,
                search_exact: $this->showcase->search_exact,
                sort_direction: $this->showcase->sort_direction,
                sort_field: $this->showcase->sort_field,
            ) as $entry_data
        ) {
            $this->showcase->addEntry($entry_data);
        }

        $templatesContext['entries_count'] = $this->showcase->entries_manager->getTotalEntries();

        $hookArguments = hooksRun('output_main_intermediate', $hookArguments);

        if ($templatesContext['entries_count']) {
            $templatesContext['pagination'] = multipage(
                $templatesContext['entries_count'],
                $this->showcase->config['entries_per_page'],
                $this->showcase->entries_manager->getCurrentPage(),
                $this->showcase->config['url_showcase'] . '/' . $this->routing->generateUrl(
                    Routes::Main,
                    $this->showcase->url_params
                ),
            );

            // get first attachment for each showcase on this page
            if ($this->showcase->config['attachments_main_render_first']) {
                foreach (
                    $this->showcase->attachments_repository->queryAttachments(
                        [
                            "entry_id IN ('" . implode("','", $this->showcase->getEntries()->pluck('entry_id')) . "')",
                            "status='1'" // todo, update to consider mod options
                        ],
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
                    $this->showcase->attachments_manager->setAttachment($attachment_data);
                }
            }

            foreach ($this->showcase->attachments_manager->getAttachments() as $attachment_data) {
                $entry_data = $this->showcase->getEntryByID($attachment_data->entry_id);

                $attachment_data
                    ->setExtraData('file_size', get_friendly_size($attachment_data->file_size))
                    ->setExtraData(
                        'is_image',
                        str_starts_with($attachment_data->mime_type, 'image/')
                    )
                    ->setExtraData('icon', get_attachment_icon($attachment_data->attachment_name));

                $attachment_data->has_thumbnail = !empty($attachment_data->thumbnail_name) && $this->showcase->attachments_display_thumbnails;

                $attachment_data->setExtraData(
                    'url',
                    $this->routing->generateUrl(
                        Routes::AttachmentView,
                        [
                            'entry_slug' => $entry_data->entry_slug,
                            'attachment_hash' => $attachment_data->attachment_hash,
                        ],
                    )
                );

                if ($attachment_data->has_thumbnail) {
                    $attachment_data->setExtraData(
                        'url_thumbnail',
                        $this->routing->generateUrl(
                            Routes::ThumbnailView,
                            [
                                'entry_slug' => $entry_data->entry_slug,
                                'attachment_hash' => $attachment_data->attachment_hash
                            ]
                        )
                    );
                } else {
                    $attachment_data->setExtraData('url_thumbnail', $attachment_data->getExtraData('url'));
                }
            }

            $inlineModerationCount = 0;

            $inlinecookie = 'inlinemod_forum' . $this->showcase->showcase_id;

            foreach ($this->showcase->getEntries() as $entry_id => $entry_data) {
                $entry_data->setExtraData('unread_comments', false)
                    ->setExtraData('modChecked', false);

                if ($this->showcase->user_permissions[ModeratorPermissions::CanManageEntries] &&
                    isset($mybb->cookies[$inlinecookie]) &&
                    my_strpos($mybb->cookies[$inlinecookie], "|{$entry_data->entry_slug}|") !== false) {
                    $entry_data->setExtraData('modChecked', true);

                    ++$templatesContext['inline_selected_count'];

                    if (!$entry_data->getExtraData('username')) {
                        $entry_data->setExtraData('username', $lang->guest);
                    }
                }

                $entry_data
                    ->setExtraData(
                        'url',
                        $this->routing->generateUrl(
                            Routes::EntryView,
                            [
                                'entry_slug' => $entry_data->entry_slug,
                                'entry_slug_custom' => $entry_data->entry_slug_custom
                            ],
                            $this->showcase->url_params
                        )
                    )
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
                        $this->showcase->attachments_manager->getAttachmentsByEntryID($entry_data->entry_id)
                    );

                //add bits for search_field highlighting
                /*if ($this->showcase->render_manager->searchKeyWords) {
                    $urlBackup = urlHandlerGet();

                    urlHandlerSet($entryUrl);

                    $entryUrl = urlHandlerBuild([
                        //'search_field' => $this->showcaseObject->searchField,
                        'highlight' => urlencode($this->showcase->render_manager->searchKeyWords)
                    ]);

                    urlHandlerSet($urlBackup);
                }*/

                $entrySubject = $fieldCache = [];

                foreach ($this->showcase->fields_cache as $fieldID => $fieldDefinition) {
                    $entryFieldText = $entry_data->getExtraData($fieldDefinition['field_key']) ?? '';

                    if (!$fieldDefinition['parse'] && $entryFieldText !== false) {
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
                    } elseif ($entryFieldText !== false) {
                        $entryFieldText = $this->showcase->parseMessage((string)$entryFieldText);
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

                if ($entry_data->visible == $this->showcase->entries_manager::STATUS_PENDING) {
                    $entry_data->setExtraData('background_class', 'trow_shaded');
                } elseif ($entry_data->visible == $this->showcase->entries_manager::STATUS_DELETED && $templatesContext['permissions']['can_manage_entries]']) {
                    $entry_data->setExtraData('background_class', 'trow_shaded trow_deleted');
                }

                $comment_data = $this->showcase->comments_repository->queryComment(
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
                        $comment_data['comment_slug'] ? $this->routing->generateUrl(
                            Routes::Comment,
                            ['comment_slug' => $comment_data['comment_slug']]
                        ) : ''
                    )
                    ->setExtraData('last_comment_date', $comment_data['dateline'])
                    ->setExtraData(
                        'attachments_count',
                        $this->showcase->attachments_repository->queryAttachmentCount(
                            ["entry_id='{$entry_data->entry_id}'",]
                        )
                    )
                    ->setExtraData(
                        'comments_by_you',
                        $this->showcase->comments_repository->queryCommentCount(
                            [
                                "entry_id='{$entry_data->entry_id}'",
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

        return $this->showcase->render_manager->templateGet(
            'pageMain',
            $templatesContext
        );
    }

    /**
     * @throws SyntaxError
     * @throws RuntimeError
     * @throws LoaderError
     */
    public function create(
        string $entry_slug = '',
        bool $isEditPage = false,
    ): string {
        global $lang, $mybb, $db;

        $hookArguments = [
            'this' => &$this,
        ];

        $extractVariables = [];

        $hookArguments['extractVariables'] = &$extractVariables;

        if ($isEditPage) {
            $entry_data = $this->showcase->entries_manager->fetchEntry(
                ["entry_slug='{$db->escape_string($entry_slug)}'"],
            );

            $this->showcase->addEntry($entry_data);

            $entry_data = $this->showcase->getEntryByID($entry_data['entry_id']);
        } else {
            $entry_data = new Entry();
        }

        $this->forcedFieldBreadcrumb();

        $mainUrl = $this->routing->generateUrl(
            Routes::Main,
            $this->showcase->url_params
        );

        add_breadcrumb(
            $this->showcase->config['name_friendly'],
            $this->showcase->config['url_showcase'] . '/' . $this->routing->generateUrl(
                Routes::Main,
            )
        );

        if ($isEditPage) {
            add_breadcrumb(
                $lang->myShowcaseButtonEntryUpdate,
                $this->showcase->config['url_showcase'] . '/' . $this->routing->generateUrl(
                    Routes::EntryUpdate,
                    [
                        'entry_slug' => $entry_data->entry_slug,
                        'entry_slug_custom' => $entry_data->entry_slug_custom,
                    ]
                )
            );
        } else {
            add_breadcrumb(
                $lang->myShowcaseButtonEntryCreate,
                $this->showcase->config['url_showcase'] . '/' . $this->routing->generateUrl(
                    Routes::EntryCreate,
                ),
            );
        }

        $this->showcase->urlParamsAppend([
            'entry_slug' => $entry_data->entry_slug
        ]);

        if (!$isEditPage && empty($entry_data->post_hash)) {
            if ($mybb->get_input('post_hash')) {
                $entry_data->setPostHash($mybb->get_input('post_hash'));
            } else {
                $entry_data->setPostHash(generateUUIDv4());
            }
        }

        $templatesContext = [
            'url' => [
                'crate_update' => $isEditPage ? $this->routing->generateUrl(
                    Routes::EntryUpdate,
                    array_merge($this->showcase->url_params, [
                        'entry_slug' => $entry_data->entry_slug,
                        'entry_slug_custom' => $entry_data->entry_slug_custom,
                    ])
                ) : $this->routing->generateUrl(
                    Routes::EntryCreate,
                    $this->showcase->url_params
                ),
                'upload' => $isEditPage ? $this->routing->generateUrl(
                    Routes::EntryUpdateUpload,
                    [
                        'entry_slug' => $entry_data->entry_slug,
                        'entry_slug_custom' => $entry_data->entry_slug_custom,
                        'my_post_key' => $mybb->post_code,
                    ]
                ) : $this->routing->generateUrl(
                    Routes::EntryCreateUpload,
                    [
                        'post_hash' => $entry_data->post_hash,
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
            'entry_data' => $entry_data,
            'attachment_objects' => $this->showcase->attachments_repository->queryAttachments(
                [
                    "entry_id='{$entry_data->entry_id}' OR post_hash='{$entry_data->post_hash}'"
                ],// todo, status clause
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
            ),
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

                if ($entry_data->post_hash) {
                    $insertData['post_hash'] = $entry_data->post_hash;
                }
            }

            if ($isEditPage && (
                    $this->showcase->config['moderate_entries_update'] ||
                    $this->showcase->user_permissions[UserPermissions::ModerateEntryUpdate]
                )) {
                $insertData['status'] = $this->showcase->entries_manager::STATUS_PENDING;
            } elseif (!$isEditPage && $this->showcase->config['moderate_entries_create'] && (
                    $this->showcase->config['moderate_entries_create'] ||
                    $this->showcase->user_permissions[UserPermissions::ModerateEntryCreate]
                )) {
                $insertData['status'] = $this->showcase->entries_manager::STATUS_PENDING;
            }

            // Set up a showcase handler.
            //Require_once MYBB_ROOT . 'inc/datahandlers/myshowcase_dh.php';

            if ($entry_data->entry_id) {
                $where_clauses = ["entry_id='{$entry_data->entry_id}'"];
            } else {
                $where_clauses = ["post_hash='{$entry_data->post_hash}'"];
            }

            foreach (
                $this->showcase->attachments_repository->queryAttachments(
                    $where_clauses,
                    ['attachment_id', 'field_id'],
                ) as $attachment_id => $attachment_data
            ) {
                $entry_data->addAttachment($attachment_data);
            }

            if ($isEditPage) {
                $dataHandler = $this->showcase->dataHandler($entry_data, $this->showcase::DATA_HANDLER_METHOD_UPDATE);
            } else {
                $insertData['dateline'] = TIME_NOW;

                $dataHandler = $this->showcase->dataHandler($entry_data);
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
                $this->showcase->error_messages = array_merge(
                    $this->showcase->error_messages,
                    $dataHandler->get_friendly_errors()
                );
            }

            if (!$isPreview && !$this->showcase->error_messages) {
                if ($isEditPage) {
                    $dataHandler->updateEntry();
                } else {
                    $dataHandler->entryInsert();
                }

                if (isset($dataHandler->return_data['status']) && $dataHandler->return_data['status'] === $this->showcase->entries_manager::STATUS_DELETED) {
                    $mainUrl = $this->routing->generateUrl(
                        Routes::Main,
                        $this->showcase->url_params
                    );

                    redirect(
                        $mainUrl,
                        $isEditPage ? $lang->myShowcaseEntryEntryUpdatedStatus : $lang->myShowcaseEntryEntryCreatedStatus
                    );
                } else {
                    $entryUrl = $this->routing->generateUrl(
                        Routes::EntryView,
                        [
                            'entry_slug' => $dataHandler->return_data['entry_slug'],
                            'entry_slug_custom' => $dataHandler->return_data['entry_slug_custom']
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

                $templatesContext['entry_preview'] = $this->showcase->render_manager->buildEntry(
                    $entry_data,
                    $this->showcase->parser_options
                );

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
            function (array &$fieldDefinition) use (&$entry_data, &$fieldTabIndex): array {
                $field_id = (int)$fieldDefinition['field_id'];

                $fieldObject = $this->showcase->field_factory->create(
                    $this->showcase,
                    $fieldDefinition,
                    $entry_data->entry_id,
                    $entry_data->post_hash
                );

                $fieldDefinition['description'] = $fieldObject->getFieldDescription();

                $fieldDefinition['code_buttons'] = $fieldDefinition['smiles_inserter'] = '';

                if (in_array($fieldDefinition['html_type'], [
                    FieldHtmlTypes::Text,
                    //FieldHtmlTypes::Search,
                    FieldHtmlTypes::TextArea,
                ])) {
                    if ($fieldDefinition['enable_editor']) {
                        $this->showcase->editorBuild(
                            $fieldDefinition['code_buttons'],
                            $fieldDefinition['smiles_inserter'],
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
                        $acceptElement = implode(
                            ',',
                            array_merge(
                                array_column($this->showcase->attachment_types, 'mime_type'),
                                array_column($this->showcase->attachment_types, 'file_extension')
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

                $fieldObject->attachmentsFetch($entry_data);

                $fieldDefinition['attachment_objects'] = $fieldObject->attachmentsGet();

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

        $templatesContext['attachmentsUpload'] = $this->showcase->render_manager->buildAttachmentsUpload($isEditPage);

        $hookArguments = hooksRun('entry_create_update_end', $hookArguments);

        extract($hookArguments['extractVariables']);

        return $this->showcase->render_manager->templateGet(
            'pageEntryCreateUpdate',
            $templatesContext,
        );
    }

    /**
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError
     */
    public function update(
        string $entry_slug,
    ): string {
        return $this->create($entry_slug, true);
    }

    /**
     * @throws RuntimeError
     * @throws LoaderError
     * @throws SyntaxError
     */
    public function view(
        string $entry_slug,
        string $entry_slug_custom,
    ): string {
        global $mybb, $lang, $db;

        $entry_data = $this->showcase->entries_manager->fetchEntry(
            ["entry_slug='{$db->escape_string($entry_slug)}'"],
        );

        $this->showcase->addEntry($entry_data);

        $entry_data = $this->showcase->getEntryByID($entry_data['entry_id']);

        if ($entry_data === false ||
            (
                $this->showcase->current_user_id !== $entry_data->user_id &&
                !$this->showcase->user_permissions[ModeratorPermissions::CanManageEntries] &&
                $entry_data->status !== $this->showcase->entries_manager::STATUS_VISIBLE
            )) {
            error($lang->myshowcase_invalid_id);
        }

        // we redirect invalid custom slugs to the correct canonical url
        if ($entry_data->entry_slug_custom !== $entry_slug_custom) {
            $this->redirect($entry_slug);
        }

        //$lang->load('showthread');

        $hookArguments = [
            'this' => &$this
        ];

        $extractVariables = [];

        $hookArguments['extractVariables'] = &$extractVariables;

        $this->forcedFieldBreadcrumb();

        add_breadcrumb(
            $this->showcase->config['name_friendly'],
            $this->showcase->config['url_showcase'] . '/' . $this->routing->generateUrl(
                Routes::Main,
            )
        );

        $mainUrl = $this->routing->generateUrl(
            Routes::Main,
            $this->showcase->url_params
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
                'entry' => $this->routing->generateUrl(
                    Routes::EntryView,
                    [
                        'entry_slug' => $entry_data->entry_slug,
                        'entry_slug_custom' => $entry_data->entry_slug_custom
                    ]
                ),
                'comment' => $this->routing->generateUrl(
                    Routes::CommentCreate,
                    [
                        'entry_slug' => $entry_data->entry_slug,
                        'entry_slug_custom' => $entry_data->entry_slug_custom,
                    ]
                ),
            ]
        ];

        $templatesContext['subject'] = $this->showcase->entries_manager->buildSubject($entry_data);

        $entryUrl = $this->routing->generateUrl(
            Routes::EntryView,
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
            $this->showcase->config['url_showcase'] . '/' . $this->routing->generateUrl(
                Routes::EntryView,
                [
                    'entry_slug' => $entry_data->entry_slug,
                    'entry_slug_custom' => $entry_data->entry_slug_custom
                ],
            )
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
            $this->showcase->render_manager->buildEntry($entry_data, $this->showcase->parser_options)
        );

        $entry = $this->showcase->getEntryByID($entry_data->entry_id);

        $templatesContext['commentsForm'] = '';

        if ($this->showcase->config['comments_allow'] && $this->showcase->user_permissions[UserPermissions::CanViewComments]) {
            $url_params['page'] = $this->showcase->current_page;

            foreach ($this->showcase->comments_manager->fetchComments($entry_data->entry_id) as $comment_data) {
                $entry_data->addComment($comment_data);
            }

            $templatesContext['commentObjects'] = [];

            $templatesContext['rendered_comments'] = [];

            $templatesContext['pagination'] = multipage(
                $this->showcase->comments_manager->getTotalComments(),
                $this->showcase->config['comments_per_page'],
                $this->showcase->comments_manager->getCurrentPage(),
                $this->showcase->config['url_showcase'] . '/' . $this->routing->generateUrl(
                    Routes::EntryView,
                    array_merge($this->showcase->url_params, [
                        'entry_slug' => $entry_data->entry_slug,
                        'entry_slug_custom' => $entry_data->entry_slug_custom,
                        'page_id' => '{page}'
                    ])
                )
            );

            foreach ($entry_data->getComments() as $comment_data) {
                $comment_data->setRenderComment(
                    $this->showcase->render_manager->buildComment(
                        $entry_data,
                        $comment_data,
                        $this->showcase->parser_options,
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

                $templatesContext['createUpdateUrl'] = $this->routing->generateUrl(
                    Routes::CommentCreate,
                    [
                        'entry_slug' => $entry_data->entry_slug,
                        'entry_slug_custom' => $entry_data->entry_slug_custom,
                    ]
                );

                $templatesContext['formatted_message'] = htmlspecialchars_uni($mybb->get_input('comment'));

                $templatesContext['code_buttons'] = $templatesContext['smiles_inserter'] = '';

                if ($this->showcase->config['comments_build_editor']) {
                    $this->showcase->editorBuild(
                        $templatesContext['code_buttons'],
                        $templatesContext['smiles_inserter']
                    );
                }
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
                    $this->showcase->current_user_id !== $entry_data->user_id)
            )
        ) {
            $db->shutdown_query(
                "UPDATE {$db->table_prefix}{$this->showcase->dataTableName} SET views=views+1 WHERE entry_id='{$entry_data->entry_id}'"
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

        return $this->showcase->render_manager->templateGet('pageView', $templatesContext);
    }

    /**
     * @throws RandomException
     */
    private function uploadAttachment(Entry $entry_data): void
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

        $fileObject = $this->showcase->attachments_manager->uploadAttachment(
            $_FILES['attachment'],
            $entry_data,
            do_watermark: $mybb->get_input('attachment_watermark_file', MyBB::INPUT_BOOL),
        );

        if (isset($fileObject['error'])) {
            $this->showcase->error_messages = array_merge(
                $this->showcase->error_messages,
                (array)$fileObject['error']
            );
        }
    }
}

//todo review hooks here