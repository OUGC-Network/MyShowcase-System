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

namespace MyShowcase\Controller;

use captcha;
use DB_MySQLi;
use DB_PgSQL;
use DB_SQLite;
use JetBrains\PhpStorm\NoReturn;
use MyBB;
use MyLanguage;
use MyShowcase\Entity\Entry;
use MyShowcase\Entity\User;
use MyShowcase\Enum\FieldHtmlTypes;
use MyShowcase\Enum\FilterFields;
use MyShowcase\Enum\Routes;
use MyShowcase\Enum\SortDirections;
use MyShowcase\Enum\UserPermissions;
use MyShowcase\Event\EventDispatcher;
use MyShowcase\Fields\FieldFactory;
use MyShowcase\Repository\AccessLogsRepository;
use MyShowcase\Repository\AttachmentsRepository;
use MyShowcase\Repository\CommentsRepository;
use MyShowcase\Repository\EntriesRepository;
use MyShowcase\Repository\ModeratorsRepository;
use MyShowcase\Repository\SubscriptionsRepository;
use MyShowcase\Routing\Routing;
use MyShowcase\Service\AttachmentsManager;
use MyShowcase\Service\CommentsManager;
use MyShowcase\Service\EntriesManager;
use MyShowcase\Service\Render;
use MyShowcase\Util\StringUtil;
use MyShowcase\Util\UuidUtil;
use MysqlPdoDbDriver;
use PostgresPdoDbDriver;
use Random\RandomException;
use ReflectionException;
use ReflectionProperty;
use Symfony\Component\Routing\Annotation\Route;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

use function MyBB\View\template;
use function MyShowcase\Plugin\Functions\fieldDataGet;
use function MyShowcase\Plugin\Functions\formatField;
use function MyShowcase\Plugin\Functions\getSetting;
use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\postParser;

use const MyShowcase\Plugin\Core\DATA_TABLE_STRUCTURE;

class EntriesController extends BaseController
{
    /**
     * @throws SyntaxError
     * @throws RuntimeError
     * @throws LoaderError
     */
    public function __construct(
        private readonly Routing $routing,
        private readonly DB_SQLite|DB_PgSQL|PostgresPdoDbDriver|MysqlPdoDbDriver|DB_MySQLi $database,
        private readonly MyBB $mybb,
        private readonly MyLanguage $language,
        private readonly EventDispatcher $event_dispatcher,
        private readonly User $current_user,
        private readonly EntriesRepository $entries_repository,
        private readonly AttachmentsRepository $attachments_repository,
        private readonly CommentsRepository $comments_repository,
        private readonly SubscriptionsRepository $subscriptions_repository,
        private readonly ModeratorsRepository $moderators_repository,
        private readonly EntriesManager $entries_manager,
        protected readonly AttachmentsManager $attachments_manager,
        private readonly CommentsManager $comments_manager,
        private readonly Render $render_manager,
        private readonly array $config,
        private readonly array $fieldset_fields,
        protected readonly array $attachment_types,
    ) {
        parent::__construct(
            $routing,
            $this->database,
            $this->mybb,
            $this->language,
            $this->event_dispatcher,
            $this->current_user,
            $this->entries_repository,
            $this->comments_repository,
            $this->entries_manager,
            $this->comments_manager,
            $this->render_manager,
            $this->config,
            $this->fieldset_fields,
        );

        $this->verifyPassword();

        if ($this->mybb->get_input('clear_password', MyBB::INPUT_INT) === 1) {
            $this->clearPassword();
        }
    }

    private function clearPassword(): void
    {
        verify_post_check($this->mybb->get_input('my_post_key'));

        $access_logs_repository = new AccessLogsRepository($this->database, $this->config, $this->event_dispatcher);

        $access_logs_repository->updateAccessLogs(
            ['is_valid' => parent::PASSWORD_IS_INVALID],
            ['user_id' => $this->current_user->uid]
        );

        redirect(
            $this->config['showcase_url'] . $this->routing->generateUrl(Routes::Main),
            $this->language->myshowcase_redirect_password_cleared
        );
    }

    /**
     * @throws RuntimeError
     * @throws LoaderError
     * @throws SyntaxError
     * @throws RandomException
     */
    #[NoReturn] #[Route('/', name: 'main', methods: ['GET'])]
    public function main(): string
    {
        $query_options = $where_clauses = [];

        $query_options['order_by'] = $this->render_manager->url_params['order_by'] ?? $this->config['main_sort_default_field'];

        if (empty($this->render_manager->url_params['order_dir'])) {
            if ($this->config['main_sort_direction']) {
                $query_options['order_dir'] = SortDirections::Ascending;
            } else {
                $query_options['order_dir'] = SortDirections::Descending;
            }
        } else {
            $query_options['order_dir'] = $this->render_manager->url_params['order_dir'];
        }

        if (isset($this->render_manager->url_params['user_id'])) {
            $where_clauses[] = "entry_data.user_id='" . $this->render_manager->url_params['user_id'] . "'";
        } elseif ($this->config['main_filter_force_field'] === FilterFields::UserID) {
            $where_clauses[] = "entry_data.user_id='" . $this->current_user->uid . "'";
        }

        if (empty($this->render_manager->url_params['threshold']['days']) && !empty($this->current_user->daysprune)) {
            $where_clauses[] = "entry_data.dateline>='" . (TIME_NOW - ($this->current_user->daysprune * 86400)) . "'";
        } elseif (!empty($this->render_manager->url_params['threshold']['days'])) {
            $where_clauses[] = "entry_data.dateline>='" . (TIME_NOW - ($this->render_manager->url_params['threshold']['days'] * 86400)) . "'";
        }

        $keywords = array_filter(array_map('trim', explode(' ', $this->render_manager->url_params['keywords'] ?? '')));

        if ($keywords) {
            $search_where_clauses = [];

            foreach ($keywords as $keyword) {
                if (empty($this->render_manager->url_params['exact_match'])) {
                    $search_where_clauses[] = "user_data.username LIKE '%{$this->database->escape_string($keyword)}%'";
                } else {
                    $search_where_clauses[] = "user_data.username='{$this->database->escape_string($keyword)}'";
                }
            }

            foreach ($this->fieldset_fields as $field_id => $field_definition) {
                foreach ($keywords as $keyword) {
                    if (!$field_definition['enable_search']) {
                        continue;
                    }

                    if ($field_definition['html_type'] === FieldHtmlTypes::CheckBox ||
                        $field_definition['html_type'] === FieldHtmlTypes::Radio ||
                        $field_definition['html_type'] === FieldHtmlTypes::Select) {
                        if (empty($this->render_manager->url_params['exact_match'])) {
                            $search_where_clauses[] = "(table_{$field_definition['field_key']}.value LIKE '%{$this->database->escape_string($keyword)}%' OR table_{$field_definition['field_key']}.display_style LIKE '%{$this->database->escape_string($keyword)}%')";
                        } else {
                            $search_where_clauses[] = "(table_{$field_definition['field_key']}.value='{$this->database->escape_string($keyword)}' OR table_{$field_definition['field_key']}.display_style='{$this->database->escape_string($keyword)}')";
                        }
                        //$search_where_clauses[] = "table_{$field_definition['field_key']}.set_id='{$this->config['field_set_id']}'";
                    } elseif (empty($this->render_manager->url_params['exact_match'])) {
                        $search_where_clauses[] = "entry_data.{$field_definition['field_key']} LIKE '%{$this->database->escape_string($keyword)}%'";
                    } else {
                        $search_where_clauses[] = "entry_data.{$field_definition['field_key']}='{$this->database->escape_string($keyword)}'";
                    }
                }
            }
            if (empty($this->render_manager->url_params['exact_match'])) {
                $search_where_clauses = implode(' OR ', $search_where_clauses);
            } else {
                $search_where_clauses = implode(' AND ', $search_where_clauses);
            }

            $where_clauses[] = "({$search_where_clauses})";
        }

        if ($this->mybb->get_input('action') === 'inline_moderation') {
            verify_post_check($this->mybb->get_input('my_post_key'));

            foreach (
                $this->entries_manager->fetchEntries(
                    $where_clauses,
                    $query_options,
                    $this->render_manager->url_params,
                ) as $entry
            ) {
                $this->entries_manager->addEntry($entry);
            }

            $selected_entries = $this->entries_manager->getEntries()->filter(
                fn(Entry $entry) => (
                        $this->mybb->get_input('selected_all_entries', MyBB::INPUT_INT) &&
                        !in_array(
                            $entry->entry_slug,
                            (array)json_decode($this->mybb->get_input('unselected_entries'), true),
                            true
                        )
                    ) || (
                        !$this->mybb->get_input('selected_all_entries', MyBB::INPUT_INT) &&
                        in_array(
                            $entry->entry_slug,
                            (array)json_decode($this->mybb->get_input('selected_entries'), true),
                            true
                        )
                    )
            );

            switch ($this->mybb->get_input('moderate')) {
                case 'open':
                    /**
                     * @var Entry $entry
                     */
                    foreach ($selected_entries as $entry) {
                        $this->entries_repository->updateEntry(['is_closed' => 0], $entry->entry_id);
                    }

                    redirect(
                        $this->config['showcase_url'] . $this->routing->generateUrl(
                            Routes::Main,
                            ['clear_selected' => 1]
                        ),
                        $this->language->myshowcase_main_inline_moderation_redirect_opened
                    );

                    break;
                case 'close':
                    /**
                     * @var Entry $entry
                     */
                    foreach ($selected_entries as $entry) {
                        $this->entries_repository->updateEntry(['is_closed' => 1], $entry->entry_id);
                    }

                    redirect(
                        $this->config['showcase_url'] . $this->routing->generateUrl(
                            Routes::Main,
                            $this->render_manager->url_params
                        ),
                        $this->language->myshowcase_main_inline_moderation_redirect_closed
                    );

                    break;
                case 'stick':
                    /**
                     * @var Entry $entry
                     */
                    foreach ($selected_entries as $entry) {
                        $this->entries_repository->updateEntry(['is_sticky' => 1], $entry->entry_id);
                    }

                    redirect(
                        $this->config['showcase_url'] . $this->routing->generateUrl(
                            Routes::Main,
                            ['clear_selected' => 1]
                        ),
                        $this->language->myshowcase_main_inline_moderation_redirect_stuck
                    );

                    break;
                case 'unstick':
                    /**
                     * @var Entry $entry
                     */
                    foreach ($selected_entries as $entry) {
                        $this->entries_repository->updateEntry(['is_sticky' => 0], $entry->entry_id);
                    }

                    redirect(
                        $this->config['showcase_url'] . $this->routing->generateUrl(
                            Routes::Main,
                            ['clear_selected' => 1]
                        ),
                        $this->language->myshowcase_main_inline_moderation_redirect_unstuck
                    );

                    break;
                case 'approve':
                    /**
                     * @var Entry $entry
                     */
                    foreach ($selected_entries as $entry) {
                        $this->entries_repository->updateEntry(
                            ['status' => $this->entries_manager::STATUS_VISIBLE],
                            $entry->entry_id
                        );
                    }

                    redirect(
                        $this->config['showcase_url'] . $this->routing->generateUrl(
                            Routes::Main,
                            ['clear_selected' => 1]
                        ),
                        $this->language->myshowcase_main_inline_moderation_redirect_approved
                    );

                    break;
                case 'unapprove':
                    /**
                     * @var Entry $entry
                     */
                    foreach ($selected_entries as $entry) {
                        $this->entries_repository->updateEntry(
                            ['status' => $this->entries_manager::STATUS_PENDING],
                            $entry->entry_id
                        );
                    }

                    redirect(
                        $this->config['showcase_url'] . $this->routing->generateUrl(
                            Routes::Main,
                            ['clear_selected' => 1]
                        ),
                        $this->language->myshowcase_main_inline_moderation_redirect_unapproved
                    );

                    break;
                case 'copy':
                    break;
                case 'soft_delete':
                    /**
                     * @var Entry $entry
                     */
                    foreach ($selected_entries as $entry) {
                        $this->entries_repository->updateEntry(
                            ['status' => $this->entries_manager::STATUS_DELETED],
                            $entry->entry_id
                        );
                    }

                    redirect(
                        $this->config['showcase_url'] . $this->routing->generateUrl(
                            Routes::Main,
                            ['clear_selected' => 1]
                        ),
                        $this->language->myshowcase_main_inline_moderation_redirect_soft_deleted
                    );

                    break;
                case 'restore':
                    /**
                     * @var Entry $entry
                     */
                    foreach ($selected_entries as $entry) {
                        $this->entries_repository->updateEntry(
                            ['status' => $this->entries_manager::STATUS_VISIBLE],
                            $entry->entry_id
                        );
                    }

                    redirect(
                        $this->config['showcase_url'] . $this->routing->generateUrl(
                            Routes::Main,
                            ['clear_selected' => 1]
                        ),
                        $this->language->myshowcase_main_inline_moderation_redirect_restored
                    );

                    break;
                case 'delete':
                    /**
                     * @var Entry $entry
                     */
                    foreach ($selected_entries as $entry) {
                        $this->entries_repository->deleteEntry($entry->entry_id);
                    }

                    redirect(
                        $this->config['showcase_url'] . $this->routing->generateUrl(
                            Routes::Main,
                            ['clear_selected' => 1]
                        ),
                        $this->language->myshowcase_main_inline_moderation_redirect_deleted
                    );

                    break;
            }

            error_no_permission();
        }

        $order_dir_param = [
            'order_dir' => SortDirections::Descending
        ];

        if (empty($this->render_manager->url_params['order_dir']) || $this->render_manager->url_params['order_dir'] === SortDirections::Descending) {
            $order_dir_param = [
                'order_dir' => SortDirections::Ascending
            ];
        }

        $templatesContext = [
            'url' => [
                'clear_password' => $this->routing->generateUrl(
                    Routes::Main,
                    [
                        'clear_password' => 1,
                        'my_post_key' => $this->mybb->post_code
                    ]
                ),
                'sort' => [
                    'subject' => $this->routing->generateUrl(
                        Routes::Main,
                        ['order_by' => 'subject'] + $order_dir_param + $this->render_manager->url_params,
                    ),
                    'username' => $this->routing->generateUrl(
                        Routes::Main,
                        ['order_by' => 'username'] + $order_dir_param + $this->render_manager->url_params,
                    ),
                    'comments' => $this->routing->generateUrl(
                        Routes::Main,
                        ['order_by' => 'comments'] + $order_dir_param + $this->render_manager->url_params,
                    ),
                    'last_comment' => $this->routing->generateUrl(
                        Routes::Main,
                        ['order_by' => 'last_comment'] + $order_dir_param + $this->render_manager->url_params,
                    ),
                ],
            ],
            'entry_normal_objects' => [],
            'entry_sticky_objects' => [],
            'users_browsing_counter' => [
                'members' => 0,
                'invisible' => 0,
                'guests' => 0,
            ],
        ];

        // Fetch each moderator from the cache and format it, appending it to the list
        if ($this->config['main_display_moderators']) {
            foreach (
                $this->moderators_repository->queryModerators(query_fields: ['user_id', 'group_id']) as $moderator
            ) {
                if ($moderator['group_id'] &&
                    $group_data = $this->mybb->cache->read('usergroups')[$moderator['group_id']]) {
                    $templatesContext['moderators']['groups'][$moderator['group_id']] = [
                        'title' => $group_data['title'],
                        'group_id' => $moderator['group_id'],
                    ];
                } elseif ($user_data = get_user($moderator['user_id'])) {
                    $templatesContext['moderators']['users'][$moderator['user_id']] = [
                        'user_id' => $moderator['user_id'],
                        'username' => $user_data['username'],
                        'group_id' => $user_data['usergroup'],
                        'display_group_id' => $user_data['displaygroup'],
                    ];
                }
            }
        }

        // Get the users browsing this showcase
        if ($this->config['main_display_users_browsing']) {
            $templatesContext['users_browsing_counter']['guests'] = $this->entries_repository->querySessionsCount();

            foreach ($this->entries_repository->querySessions() as $user_data) {
                if (empty($templatesContext['users_browsing'][$user_data['uid']]) ||
                    $templatesContext['users_browsing'][$user_data['uid']]['time'] < $user_data['time']) {
                    ++$templatesContext['users_browsing_counter']['members'];

                    if (!empty($user_data['invisible'])) {
                        ++$templatesContext['users_browsing_counter']['invisible'];
                    }

                    if (empty($user_data['invisible']) ||
                        !empty($this->mybb->usergroup['canviewwolinvis']) ||
                        (int)$user_data['uid'] === $this->current_user->uid) {
                        $templatesContext['users_browsing'][$user_data['uid']] = $user_data;
                    }
                }
            }

            // The user was counted as invisible user --> correct the invisible_members_count
            if (!empty($this->mybb->user['invisible'])) {
                $templatesContext['users_browsing_counter']['invisible'] -= 1;
            }
        }

        hooksRun('controller_main_start', $this);

        $this->forcedFieldBreadcrumb($this->render_manager->url_params['user_id'] ?? null);

        add_breadcrumb(
            $this->config['name'],
            $this->config['showcase_url'] . $this->routing->generateUrl(Routes::Main)
        );

        if ($this->current_user->hasPermission(UserPermissions::EntriesCanPost)) {
            $templatesContext['url_entry_create'] = $this->routing->generateUrl(
                Routes::EntryCreate,
            );
        }

        foreach ($this->fieldset_fields as $field_id => $field_definition) {
            if ($field_definition['display_in_create_update_page'] &&
                is_member($field_definition['allowed_groups_view'])) {
                $templatesContext['url']['sort'][$field_definition['field_key']] = $this->routing->generateUrl(
                    Routes::Main,
                    ['order_by' => $field_definition['field_key']] + $order_dir_param + $this->render_manager->url_params,
                );
            }
        }

        foreach ($this->fieldset_fields as $field_id => $field_definition) {
            if (!isset($this->language->{"myshowcase_fieldset_field_{$this->fieldset_fields[$field_id]['field_key']}"})) {
                $this->language->{"myshowcase_fieldset_field_{$this->fieldset_fields[$field_id]['field_key']}"} = ucwords(
                    $this->fieldset_fields[$field_id]['field_key']
                );
            }
        }

        $query_options['limit'] = $this->config['main_per_page'];

        foreach (
            $this->entries_manager->fetchEntries(
                $where_clauses,
                $query_options,
                $this->render_manager->url_params,
            ) as $entry
        ) {
            $this->entries_manager->addEntry($entry);
        }

        $templatesContext['entries_count'] = $this->entries_manager->total_entries;

        if ($templatesContext['entries_count']) {
            $pagination_params = $this->render_manager->url_params;

            unset($pagination_params['page']);

            $templatesContext['pagination'] = multipage(
                $templatesContext['entries_count'],
                $this->config['main_per_page'],
                $this->mybb->input['page'],
                $this->config['showcase_url'] . $this->routing->generateUrl(
                    Routes::Main,
                    $pagination_params
                ),
            );

            // get first attachment for each showcase on this page
            if ($this->config['attachments_main_render']) {
                foreach (
                    $this->attachments_repository->queryAttachments(
                        [
                            "entry_id IN ('" . implode(
                                "','",
                                $this->entries_manager->getEntries()->pluck('entry_id')
                            ) . "')",
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
                    $this->attachments_manager->setAttachment($attachment_data);
                }
            }

            foreach ($this->attachments_manager->getAttachments() as $attachment_data) {
                $entry = $this->entries_manager->getEntryByID($attachment_data->entry_id);

                $attachment_data
                    ->setExtraData('file_size', get_friendly_size($attachment_data->file_size))
                    ->setExtraData(
                        'is_image',
                        str_starts_with($attachment_data->mime_type, 'image/')
                    )
                    ->setExtraData('icon', get_attachment_icon($attachment_data->attachment_name));

                $attachment_data->has_thumbnail = !empty($attachment_data->thumbnail_name) && $this->config['attachments_display_thumbnails'];

                $attachment_data->setExtraData(
                    'url',
                    $this->routing->generateUrl(
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
                        $this->routing->generateUrl(
                            Routes::AttachmentThumbnail,
                            [
                                'attachment_hash' => $attachment_data->attachment_hash,
                                'attachment_name' => $attachment_data->attachment_name,
                            ]
                        )
                    );
                } else {
                    $attachment_data->setExtraData('url_thumbnail', $attachment_data->getExtraData('url'));
                }

                $entry->addAttachment($attachment_data);
            }

            foreach ($this->entries_manager->getEntries() as $entry_id => $entry) {
                $this->loadEntryFields($entry);

                $entry->setExtraData('unread_comments', false)
                    ->setExtraData('modChecked', false);

                if ($this->current_user->hasPermission(UserPermissions::EntriesCanView)) {
                    $entry->setExtraData('modChecked', true);

                    if (!$entry->getExtraData('username')) {
                        $entry->setExtraData('username', $this->language->guest);
                    }
                }

                if ($entry->last_comment_id) {
                    $last_comment_data = $this->comments_repository->queryComment(
                        ["comment_id='{$entry->last_comment_id}'"],
                        'comment_slug'
                    );
                }

                $entry
                    ->setExtraData(
                        'url',
                        $this->routing->generateUrl(
                            Routes::EntryView,
                            [
                                'entry_slug' => $entry->entry_slug,
                                'entry_slug_custom' => $entry->entry_slug_custom
                            ],
                            $this->render_manager->url_params
                        )
                    )
                    ->setExtraData(
                        'entryUrlLastComment',
                        !empty($last_comment_data['comment_slug']) ? $this->routing->generateUrl(
                            Routes::CommentView,
                            [
                                'comment_slug' => $last_comment_data['comment_slug'],
                            ],
                        ) : $this->routing->generateUrl(
                            Routes::EntryView,
                            [
                                'entry_slug' => $entry->entry_slug,
                                'entry_slug_custom' => $entry->entry_slug_custom,
                                'action' => 'last_comment'
                            ],
                        )
                    );

                $fieldCache = [];

                foreach ($this->fieldset_fields as $field_id => $field_definition) {
                    $parser_options = [
                        'allow_html' => !empty($field_definition['parser_allow_html_in_entries']),
                        'allow_mycode' => !empty($field_definition['parser_allow_mycode_in_entries']),
                        'allow_smilies' => !empty($field_definition['parser_allow_smiles_in_entries']),
                        'allow_imgcode' => !empty($field_definition['parser_allow_image_code_in_entries']),
                        'allow_videocode' => !empty($field_definition['parser_allow_video_code_in_entries']),
                        'me_username' => $entry->username,
                        'filter_badwords' => true,
                        'shorten_urls' => false,
                        'highlight' => false,
                    ];

                    if ($field_definition['enable_search'] && isset($this->mybb->input['highlight'])) {
                        $parser_options['highlight'] = $this->mybb->input['highlight'];
                    }

                    $entryFieldText = $entry->getExtraData($field_definition['field_key']) ?? '';

                    if (!$field_definition['parse'] && $entryFieldText !== false) {
                        // todo, remove this legacy updating the database and updating the format field to TINYINT
                        formatField((int)$field_definition['format'], $entryFieldText);
                    } elseif ($entryFieldText !== false) {
                        $entryFieldText = postParser()->parse_message((string)$entryFieldText, $parser_options);
                    }

                    $fieldCache[$field_id] = $entryFieldText;
                }

                // todo, subject should be main, additional fields concatenated
                $entry
                    ->setExtraData('fields_cache', $fieldCache)
                    ->setExtraData('selected', false);

                $entry->subject = postParser()->parse_badwords($entry->subject);

                if (isset($this->mybb->input['keywords'])) {
                    $entry->subject = postParser()->highlight_message($entry->subject, $this->mybb->input['keywords']);
                }

                if ($this->current_user->hasPermission(UserPermissions::EntriesCanView)) {
                    $entry->setExtraData('selected', true);
                }

                if ($entry->status === $this->entries_manager::STATUS_PENDING) {
                    $entry->setExtraData('background_class', 'trow_shaded');
                } elseif ($entry->status === $this->entries_manager::STATUS_DELETED && $templatesContext['permissions']['entries_can_view]']) {
                    $entry->setExtraData('background_class', 'trow_shaded trow_deleted');
                }

                $comment_data = $this->comments_repository->queryComment(
                    ["entry_id={$entry->entry_id}"],
                    ['comment_slug', 'showcase_id', 'entry_id', 'user_id', 'dateline'],
                    [
                        'order_by' => 'dateline',
                        'order_dir' => SortDirections::Descending
                    ]
                );

                $entry
                    ->setExtraData('last_commenter_user_data', get_user($comment_data['user_id'] ?? 0))
                    ->setExtraData(
                        'last_comment_url',
                        isset($comment_data['comment_slug']) ? $this->routing->generateUrl(
                            Routes::CommentView,
                            [
                                'comment_slug' => $comment_data['comment_slug'],
                            ]
                        ) : ''
                    )
                    ->setExtraData('last_comment_date', $comment_data['dateline'] ?? 0)
                    ->setExtraData(
                        'attachments_count',
                        $this->attachments_repository->queryAttachmentCount(
                            ["entry_id='{$entry->entry_id}'",]
                        )
                    )
                    ->setExtraData(
                        'comments_by_you',
                        $this->comments_repository->queryCommentCount(
                            [
                                "entry_id='{$entry->entry_id}'",
                                "user_id='{$this->current_user->uid}'",
                            ]
                        )
                    );

                if (empty($entry->is_sticky)) {
                    $templatesContext['entry_normal_objects'][$entry_id] = $entry;
                } else {
                    $templatesContext['entry_sticky_objects'][$entry_id] = $entry;
                }
            }
        }

        hooksRun('controller_main_intermediate', $this);

        return $this->render_manager->templateGet(
            'pageMain',
            $templatesContext
        );
    }

    /**
     * @throws SyntaxError
     * @throws RuntimeError
     * @throws LoaderError|RandomException
     */
    #[Route('/create', name: 'entry_create', methods: ['GET', 'POST'])]
    public function create(string $entry_slug = '', bool $is_update = false): string
    {
        if ($is_update) {
            $entry = $this->entries_manager->fetchEntry(
                ["entry_data.entry_slug='{$this->database->escape_string($entry_slug)}'"],
            );

            $this->entries_manager->addEntry($entry);

            $entry = $this->entries_manager->getEntryByID($entry['entry_id']);
        } else {
            $entry = new Entry();
        }

        $this->forcedFieldBreadcrumb();

        add_breadcrumb(
            $this->config['name'],
            $this->config['showcase_url'] . $this->routing->generateUrl(
                Routes::Main,
            )
        );

        if ($is_update) {
            add_breadcrumb(
                $this->language->myShowcaseButtonEntryUpdate,
                $this->config['showcase_url'] . $this->routing->generateUrl(
                    Routes::EntryUpdate,
                    [
                        'entry_slug' => $entry->entry_slug,
                    ]
                )
            );
        } else {
            add_breadcrumb(
                $this->language->myShowcaseButtonEntryCreate,
                $this->config['showcase_url'] . $this->routing->generateUrl(
                    Routes::EntryCreate,
                ),
            );
        }

        if (!$is_update && empty($entry->post_hash)) {
            if ($this->mybb->get_input('post_hash')) {
                $entry->post_hash = $this->mybb->get_input('post_hash');
            } else {
                $entry->post_hash = (new UuidUtil())->generateUuidV4();
            }
        }

        if ($this->mybb->request_method === 'post') {
            verify_post_check($this->mybb->get_input('my_post_key'));

            foreach (DATA_TABLE_STRUCTURE['myshowcase_data'] as $field_key => $field_definition) {
                try {
                    $reflection_property = new ReflectionProperty(Entry::class, $field_key);

                    if (!$reflection_property->isReadOnly() && isset($this->mybb->input[$field_key])) {
                        $entry->$field_key = $this->mybb->input[$field_key];
                    }
                } catch (ReflectionException $exception) {
                }
            }

            foreach ($this->fieldset_fields as $field_id => $field_definition) {
                if (isset($this->mybb->input[$field_definition['field_key']])) {
                    $entry->setExtraData(
                        $field_definition['field_key'],
                        $this->mybb->input[$field_definition['field_key']]
                    );
                }
            }

            $isPreview = isset($this->mybb->input['preview']);

            $entry->ipaddress = $this->mybb->session->packedip;

            if (!$is_update) {
                $entry->user_id = $this->current_user->uid;

                $entry->dateline = TIME_NOW;
            }

            if ($is_update &&
                $this->current_user->hasPermission(UserPermissions::EntriesModerateUpdates)) {
                $entry->status = $this->entries_manager::STATUS_PENDING;
            } elseif (!$is_update &&
                $this->current_user->hasPermission(UserPermissions::EntriesModerateNew)
            ) {
                $entry->status = $this->entries_manager::STATUS_PENDING;
            }

            // Set up a showcase handler.
            //Require_once MYBB_ROOT . 'inc/datahandlers/myshowcase_dh.php';

            if ($entry->entry_id) {
                $where_clauses = ["entry_id='{$entry->entry_id}'"];
            } else {
                $where_clauses = ["post_hash='{$entry->post_hash}'"];
            }

            foreach (
                $this->attachments_repository->queryAttachments(
                    $where_clauses,
                    ['attachment_id', 'field_id'],
                ) as $attachment_id => $attachment_data
            ) {
                $entry->addAttachment($attachment_data);
            }

            if (!$is_update) {
                $entry->dateline = TIME_NOW;
            }

            $this->entries_manager->validateSet(
                $entry,
                $is_update ? $this->entries_manager::VALIDATION_METHOD_UPDATE : $this->entries_manager::VALIDATION_METHOD_INSERT
            );

            foreach ($this->fieldset_fields as $field_id => $field_definition) {
                $entry->extra_data[$field_definition['field_key']] = match ($field_definition['html_type']) {
                    /*FieldHtmlTypes::CheckBox,
                    FieldHtmlTypes::Select => $this->mybb->get_input(
                        $field_definition['field_key'],
                        MyBB::INPUT_ARRAY
                    ), */
                    default => $this->mybb->get_input($field_definition['field_key']),
                };
            }

            // todo, slug should load all field data after update/insert to build

            if (!$this->entries_manager->validateEntry()) {
                $this->render_manager->error_messages = array_merge(
                    $this->render_manager->error_messages,
                    $this->entries_manager->validateGetError()
                );
            }

            if (!$isPreview && !$this->render_manager->error_messages) {
                if ($is_update) {
                    $entry = $this->entries_manager->validationEntryInsert();
                } else {
                    $entry = $this->entries_manager->validationEntryUpdate();
                }

                if (isset($entry->status) && $entry->status === $this->entries_manager::STATUS_DELETED) {
                    $mainUrl = $this->routing->generateUrl(
                        Routes::Main,
                        $this->render_manager->url_params
                    );

                    redirect(
                        $mainUrl,
                        $is_update ? $this->language->myShowcaseEntryEntryUpdatedStatus : $this->language->myShowcaseEntryEntryCreatedStatus
                    );
                } else {
                    redirect(
                        $this->config['showcase_url'] . $this->routing->generateUrl(
                            Routes::EntryView,
                            [
                                'entry_slug' => $entry->entry_slug,
                                'entry_slug_custom' => $entry->entry_slug_custom
                            ]
                        ),
                        $is_update ? $this->language->myShowcaseEntryEntryUpdated : $this->language->myShowcaseEntryEntryCreated
                    );
                }

                exit;
            }

            if ($isPreview) {
                $templatesContext['entry_preview'] = $this->render_manager->buildEntry($entry);

                $templatesContext['is_preview_page'] = true;
            }
        }

        if ($is_update && !$this->current_user->hasPermission(UserPermissions::EntriesCanUpdate)) {
            error_no_permission();
        } elseif (!$is_update && !$this->current_user->hasPermission(UserPermissions::EntriesCanPost)) {
            error_no_permission();
        }

        $templatesContext = [
            'url' => [
                'crate_update' => $is_update ? $this->routing->generateUrl(
                    Routes::EntryUpdate,
                    array_merge($this->render_manager->url_params, [
                        'entry_slug' => $entry->entry_slug,
                    ])
                ) : $this->routing->generateUrl(
                    Routes::EntryCreate,
                    $this->render_manager->url_params
                ),
                'upload' => $this->routing->generateUrl(
                    Routes::AttachmentUpload,
                    [
                        'post_hash' => $entry->post_hash,
                        'my_post_key' => $this->mybb->post_code,
                    ]
                ),
            ],
            'is_update_page' => $is_update,
            'is_preview_page' => false,
            'entry_preview' => '',
            'entry_data' => &$entry,
            'attachment_objects' => $this->attachments_repository->queryAttachments(
                [
                    "entry_id='{$entry->entry_id}' OR post_hash='{$entry->post_hash}'"
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
            'post_options' => [
                'allow_signature' => false,
                'disable_smilies' => false,
            ],
        ];

        $post_options = $this->mybb->get_input('post_options', MyBB::INPUT_ARRAY);

        if (!isset($post_options['allow_signature']) && !empty($this->mybb->user['signature'])) {
            $templatesContext['post_options']['allow_signature'] = true;
        }

        if (isset($post_options['allow_signature']) && empty($post_options['allow_signature'])) {
            $templatesContext['post_options']['allow_signature'] = false;
        }

        if (!empty($post_options['disable_smilies'])) {
            $templatesContext['post_options']['disable_smilies'] = true;
        }

        if (!isset($post_options['subscription_method'])) {
            $templatesContext['post_options']['subscription_method'] = 0;
        }

        hooksRun('controller_create_start', $this);

        $this->loadEntryFields($entry, is_create_update_page: true);

        $templatesContext['attachmentsUpload'] = '';
        //$templatesContext['attachmentsUpload'] = $this->render_manager->buildAttachmentsUpload($isEditPage);

        $templatesContext['prefixes'] = build_prefix_select(
            'all',
            $this->mybb->get_input('prefix_id', MyBB::INPUT_INT),
            false,
            $entry->prefix_id
        );

        $templatesContext['login_box'] = template('misc/changeuserbox.twig');

        $templatesContext['post_icons'] = [];

        if ($this->mybb->settings['allowposticons'] && $this->config['entries_allow_post_icons']) {
            $templatesContext['post_icons'] = get_post_icons();
        }

        $templatesContext['code_buttons'] = $templatesContext['smiles_inserter'] = '';

        if ($this->config['entries_message_build_editor']) {
            $this->render_manager->editorBuild(
                $templatesContext['code_buttons'],
                $templatesContext['smiles_inserter']
            );
        }

        $templatesContext['multi_quote'] = true;

        $templatesContext['external_quotes'] = 1;

        $templatesContext['show_post_options'] = $templatesContext['show_signature'] = $templatesContext['show_disable_smilies'] = $templatesContext['show_attachments'] = false;

        if ($this->mybb->usergroup['canusesig'] && !$this->mybb->user['suspendsignature']) {
            $templatesContext['show_post_options'] = $templatesContext['show_signature'] = true;
        }

        if ($this->config['parser_allow_smiles_in_entries']) {
            $templatesContext['show_post_options'] = $templatesContext['show_disable_smilies'] = true;
        }

        $templatesContext['moderator_options'] = $this->mybb->get_input('moderator_options', MyBB::INPUT_ARRAY);

        $templatesContext['moderator_options']['is_closed'] ??= $entry->is_closed;

        $templatesContext['moderator_options']['is_sticky'] ??= $entry->is_sticky;

        $templatesContext['show_moderator_options'] = $templatesContext['show_close_options'] = $templatesContext['show_sticky_options'] = false;

        if ($this->current_user->hasPermission(UserPermissions::EntriesCanClose)) {
            $templatesContext['show_moderator_options'] = $templatesContext['show_close_options'] = true;
        }

        if ($this->current_user->hasPermission(UserPermissions::EntriesCanSticky)) {
            $templatesContext['show_moderator_options'] = $templatesContext['show_sticky_options'] = true;
        }

        $templatesContext['captcha'] = '';

        if ($this->current_user->hasPermission(UserPermissions::CommentsCanPost) &&
            empty($this->mybb->user['suspendposting']) &&
            (!$entry->is_closed || $this->current_user->hasPermission(UserPermissions::EntriesCanView)) &&
            !empty($this->mybb->settings['quickreply']) &&
            !empty($this->mybb->user['showquickreply']) &&
            $entry->user_id === $this->current_user->uid) {
            $templatesContext['showquickreply'] = true;

            // Show captcha image for guests if is_enabled
            if ($this->mybb->settings['captchaimage'] && !$this->current_user->uid) {
                require_once MYBB_ROOT . 'inc/class_captcha.php';

                $post_captcha = new captcha(true, 'post');

                if ($post_captcha->html) {
                    $templatesContext['captcha'] = $post_captcha->html;
                }
            }
        }

        hooksRun('controller_create_end', $this);

        return $this->render_manager->templateGet(
            'pageEntryCreateUpdate',
            $templatesContext,
        );
    }

    /**
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError|RandomException
     */
    #[Route('/update/{entry_slug}', name: 'entry_update', methods: ['GET', 'POST'])]
    public function update(string $entry_slug): string
    {
        return $this->create($entry_slug, true);
    }

    /**
     * @throws RuntimeError
     * @throws LoaderError
     * @throws SyntaxError|RandomException
     */
    #[NoReturn] #[Route('/view/{entry_slug}/{entry_slug_custom}', name: 'entries_view', methods: ['GET'])]
    public function view(string $entry_slug, string $entry_slug_custom): string
    {
        $entry = $this->entries_manager->fetchEntry(
            ["entry_data.entry_slug='{$this->database->escape_string($entry_slug)}'"],
        );

        $this->entries_manager->addEntry($entry);

        /**
         * @var Entry $entry
         */
        $entry = $this->entries_manager->getEntryByID($entry['entry_id']);

        if (empty($entry->entry_id) ||
            (
                $this->current_user->uid !== $entry->user_id &&
                !$this->current_user->hasPermission(UserPermissions::EntriesCanView) &&
                $entry->status !== $this->entries_manager::STATUS_VISIBLE
            )) {
            error($this->language->myshowcase_invalid_id);
        }

        // we redirect invalid custom slugs to the correct canonical url
        if ($entry->entry_slug_custom !== $entry_slug_custom) {
            $this->redirect($entry_slug);
        }

        //$this->language->load('showthread');

        $this->forcedFieldBreadcrumb($entry->user_id);

        add_breadcrumb(
            $this->config['name'],
            $this->config['showcase_url'] . $this->routing->generateUrl(
                Routes::Main,
                $this->config['main_filter_force_field'] === FilterFields::UserID ? ['user_id' => $entry->user_id] : []
            )
        );

        $mainUrl = $this->routing->generateUrl(
            Routes::Main,
            $this->render_manager->url_params
        );

        $templatesContext = [
            'entry_data' => &$entry,
            'captcha' => '',
            'online_count' => [
                'users' => 0,
                'invisible' => 0,
                'guests' => 0,
                'members' => 0,
            ],
            'online_users' => [],
            'comments_count' => 0,
            'url' => [
                'entry' => $this->routing->generateUrl(
                    Routes::EntryView,
                    [
                        'entry_slug' => $entry->entry_slug,
                        'entry_slug_custom' => $entry->entry_slug_custom
                    ]
                ),
                'comment' => $this->routing->generateUrl(
                    Routes::CommentCreate,
                    [
                        'entry_slug' => $entry->entry_slug,
                        'entry_slug_custom' => $entry->entry_slug_custom,
                    ]
                ),
                'threaded' => $this->routing->generateUrl(
                    Routes::EntryView,
                    [
                        'entry_slug' => $entry->entry_slug,
                        'entry_slug_custom' => $entry->entry_slug_custom,
                        'threaded' => 1
                    ]
                ),
                'subscribe' => $this->routing->generateUrl(
                    Routes::EntryView,
                    [
                        'entry_slug' => $entry->entry_slug,
                        'entry_slug_custom' => $entry->entry_slug_custom,
                        'action' => 'subscribe',
                        'my_post_key' => $this->mybb->post_code,
                    ]
                ),
                'unsubscribe' => $this->routing->generateUrl(
                    Routes::EntryView,
                    [
                        'entry_slug' => $entry->entry_slug,
                        'entry_slug_custom' => $entry->entry_slug_custom,
                        'action' => 'unsubscribe',
                        'my_post_key' => $this->mybb->post_code,
                    ]
                ),
                'oldest' => $this->routing->generateUrl(
                    Routes::EntryView,
                    [
                        'entry_slug' => $entry->entry_slug,
                        'entry_slug_custom' => $entry->entry_slug_custom,
                        'action' => 'oldest',
                    ]
                ),
                'newest' => $this->routing->generateUrl(
                    Routes::EntryView,
                    [
                        'entry_slug' => $entry->entry_slug,
                        'entry_slug_custom' => $entry->entry_slug_custom,
                        'action' => 'newest',
                    ]
                ),
            ],
            'is_subscribed' => false,
            'threaded_mode' => false,
            'post_options' => [
                'allow_signature' => false,
                'disable_smilies' => false,
            ],
            'fieldset_fields' => $this->fieldset_fields,
        ];

        hooksRun('controller_view_start', $this);

        $this->loadEntryFields($entry);

        if (!empty($this->mybb->user['signature'])) {
            $templatesContext['post_options']['allow_signature'] = true;
        }

        if ($this->current_user->uid && $this->subscriptions_repository->queryEntryCount(
                ["entry_id='{$entry->entry_id}'", "user_id='{$this->current_user->uid}'"]
            )) {
            $templatesContext['is_subscribed'] = true;
        }

        if (isset($this->render_manager->url_params['threaded'])) {
            $templatesContext['threaded_mode'] = true;
        }

        //$templatesContext['subject'] = $this->entries_manager->buildSubject($entry);

        $templatesContext['meta']['canonical'] = $this->config['showcase_url'] . $this->routing->generateUrl(
                Routes::EntryView,
                [
                    'entry_slug' => $entry->entry_slug,
                    'entry_slug_custom' => $entry->entry_slug_custom
                ],
            );

        $templatesContext['meta']['page'] = [
            [
                'property' => 'og:url',
                'content' => $this->config['showcase_url'] . $this->routing->generateUrl(
                        Routes::EntryView,
                        [
                            'entry_slug' => $entry->entry_slug,
                            'entry_slug_custom' => $entry->entry_slug_custom
                        ],
                    ),
            ],
            [
                'property' => 'og:type',
                'content' => 'article',
            ],
            [
                'property' => 'og:description',
                'content' => $entry->subject,
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

        $subject = (function () use ($entry): string {
                $subject_extra = [];

                foreach ($entry->getFields() as $field_data) {
                    if ($field_data->field_definition['enable_subject']) {
                        $subject_extra[] = $field_data->getUserValue();
                    }
                }

                return implode(' ', $subject_extra);
            })() . $entry->subject;

        $this->entries_repository->updateEntry(
            new Entry(
                entry_id: $entry->entry_id,
                entry_slug_custom: (new StringUtil())->createSlug($subject)->lower()->toString()
            )
        );

        add_breadcrumb(
            $subject,
            $this->config['showcase_url'] . $this->routing->generateUrl(
                Routes::EntryView,
                [
                    'entry_slug' => $entry->entry_slug,
                    'entry_slug_custom' => $entry->entry_slug_custom
                ],
            )
        );

        if (!$entry->getExtraData('username')) {
            $entry->setExtraData('username', $this->language->guest);
        }

        //trick MyBB into thinking its in archive so it adds bburl to smile link inside parser
        //doing this now should not impact anyhting. no issues with gomobile beta4
        define('IN_ARCHIVE', 1);

        $entry->setRenderEntry(
            $this->render_manager->buildEntry($entry)
        );

        $entry = $this->entries_manager->getEntryByID($entry->entry_id);

        $templatesContext['commentsForm'] = '';

        if ($this->config['comments_enable'] && $this->current_user->hasPermission(UserPermissions::CommentsCanView)) {
            foreach ($this->comments_manager->fetchComments($entry->entry_id) as $comment_data) {
                $entry->addComment($comment_data);
            }

            $templatesContext['commentObjects'] = [];

            $templatesContext['rendered_comments'] = [];

            $pagination_params = $this->render_manager->url_params;

            unset($pagination_params['page']);

            $templatesContext['pagination'] = multipage(
                $this->comments_manager->getTotalComments(),
                $this->config['comments_per_page'],
                $this->render_manager->url_params['page'] ?? CommentsManager::FIRST_PAGE,
                $this->config['showcase_url'] . $this->routing->generateUrl(
                    Routes::EntryView,
                    array_merge($pagination_params, [
                        'entry_slug' => $entry->entry_slug,
                        'entry_slug_custom' => $entry->entry_slug_custom,
                    ])
                )
            );

            foreach ($entry->getComments() as $comment_data) {
                $comment_data->setRenderComment(
                    $this->render_manager->buildComment($entry, $comment_data)
                );

                $templatesContext['commentObjects'][$comment_data->comment_id] = $comment_data;
            }

            $templatesContext['comments_can_post'] = $this->current_user->hasPermission(
                UserPermissions::CommentsCanPost
            );

            if ($this->current_user->hasPermission(UserPermissions::CommentsCanPost)) {
                global $collapsedthead, $collapsedimg, $expaltext, $collapsed;

                isset($collapsedthead) || $collapsedthead = [];

                isset($collapsedimg) || $collapsedimg = [];

                isset($collapsed) || $collapsed = [];

                $collapsedthead['quickreply'] ??= '';

                $collapsedimg['quickreply'] ??= '';

                $collapsed['quickreply_e'] ??= '';

                $templatesContext['createUpdateUrl'] = $this->routing->generateUrl(
                    Routes::CommentCreate,
                    [
                        'entry_slug' => $entry->entry_slug,
                        'entry_slug_custom' => $entry->entry_slug_custom,
                    ]
                );

                $templatesContext['code_buttons'] = $templatesContext['smiles_inserter'] = '';

                if ($this->config['comments_message_build_editor']) {
                    $this->render_manager->editorBuild(
                        $templatesContext['code_buttons'],
                        $templatesContext['smiles_inserter']
                    );
                }
            }
        }

        // Update view count
        if (
            (
                !$this->current_user->uid &&
                (
                    ($this->mybb->session->is_spider && getSetting('ViewsCountSpider')) ||
                    (!$this->mybb->session->is_spider && getSetting('ViewsCountGuests'))
                )
            ) ||
            (
                $this->current_user->uid && (getSetting('ViewsCountAuthor') ||
                    $this->current_user->uid !== $entry->user_id)
            )
        ) {
            $this->entries_repository->shutDownQueryIncrementViews($entry->entry_id);
        }

        if ($this->current_user->hasPermission(UserPermissions::CommentsCanPost) &&
            empty($this->mybb->user['suspendposting']) &&
            (!$entry->is_closed || $this->current_user->hasPermission(UserPermissions::EntriesCanView)) &&
            !empty($this->mybb->settings['quickreply']) &&
            !empty($this->mybb->user['showquickreply']) &&
            $entry->user_id === $this->current_user->uid) {
            $templatesContext['showquickreply'] = true;

            // Show captcha image for guests if is_enabled
            if ($this->mybb->settings['captchaimage'] && !$this->current_user->uid) {
                require_once MYBB_ROOT . 'inc/class_captcha.php';

                $post_captcha = new captcha(true, 'post');

                if ($post_captcha->html) {
                    $templatesContext['captcha'] = $post_captcha->html;
                }
            }
        }

        // Get users viewing this thread
        if (!empty($this->mybb->settings['browsingthisthread'])) {
            $timecut = TIME_NOW - $this->mybb->settings['wolcutoff'];

            $doneusers = array();

            $query = $this->database->simple_select(
                "sessions s LEFT JOIN {$this->database->table_prefix}users u ON (s.uid=u.uid)",
                's.ip, s.uid, s.time, u.username, u.invisible, u.usergroup, u.displaygroup',
                "s.uid!='0' AND s.time>'{$timecut}' AND nopermission!='1'",
                [
                    'order_by' => 'u.username ASC, s.time',
                    'order_dir' => SortDirections::Descending,
                ]
            );

            while ($user_data = $this->database->fetch_array($query)) {
                if ($user_data['uid'] === 0) {
                    ++$templatesContext['online_count']['guests'];
                } elseif (empty($doneusers[$user_data['uid']]) || $doneusers[$user_data['uid']] < $user_data['time']) {
                    ++$templatesContext['online_count']['members'];
                    $doneusers[$user_data['uid']] = $user_data['time'];

                    $user_data['invisible_mark'] = '';
                    if ($user_data['invisible'] === 1) {
                        $user_data['invisible_mark'] = '*';

                        ++$templatesContext['online_count']['invisible'];
                    }

                    if ($user_data['invisible'] !== 1 || $this->mybb->usergroup['canviewwolinvis'] === 1 || (int)$user_data['uid'] === $this->current_user->uid) {
                        $user_data['reading'] = my_date($this->mybb->settings['timeformat'], $user_data['time']);
                        ++$templatesContext['online_count']['users'];

                        $templatesContext['online_users'][] = $user_data;
                    }
                }
            }

            if (!empty($this->mybb->user['invisible'])) {
                // The user was counted as invisible user --> correct the online_count.invisible
                --$templatesContext['online_count']['invisible'];
            }
        }

        hooksRun('controller_view_end', $this);

        return $this->render_manager->templateGet('pageView', $templatesContext);
    }
}