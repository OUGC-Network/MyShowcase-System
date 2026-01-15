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
use MyBB;
use MyShowcase\Entity\Entry;
use MyShowcase\Entity\User;
use MyShowcase\Enum\FieldHtmlTypes;
use MyShowcase\Enum\Routes;
use MyShowcase\Enum\SortDirections;
use MyShowcase\Enum\UserPermissions;
use MyShowcase\Fields\FieldFactory;
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
    public function __construct(
        private readonly Routing $routing,
        private readonly MyBB $mybb,
        private readonly User $current_user,
        private readonly EntriesRepository $entries_repository,
        private readonly AttachmentsRepository $attachments_repository,
        private readonly CommentsRepository $comments_repository,
        private readonly SubscriptionsRepository $subscriptions_repository,
        private readonly ModeratorsRepository $moderators_repository,
        private readonly EntriesManager $entries_manager,
        private readonly AttachmentsManager $attachments_manager,
        private readonly CommentsManager $comments_manager,
        private readonly Render $render_manager,
        private readonly FieldFactory $field_factory,
        private readonly array $config,
        private readonly array $fieldset_fields,
        private readonly array $filter,
        protected readonly array $attachment_types,
    ) {
        parent::__construct(
            $routing,
            $this->mybb,
            $this->current_user,
            $this->entries_repository,
            $this->comments_repository,
            $this->entries_manager,
            $this->comments_manager,
            $this->render_manager,
            $this->field_factory,
            $this->config,
            $this->fieldset_fields,
            $this->filter,
        );
    }

    /**
     * @throws RuntimeError
     * @throws LoaderError
     * @throws SyntaxError
     * @throws RandomException
     */
    #[Route('/', name: 'main', methods: ['GET'])]
    public function main(): string
    {
        global $lang, $mybb, $db;

        if ($mybb->get_input('action') === 'inline_moderation') {
            verify_post_check($mybb->get_input('my_post_key'));

            foreach (
                $this->entries_manager->fetchEntries(
                    sorting: $this->render_manager->sorting,
                    url_params: $this->render_manager->url_params,
                    search: $this->render_manager->search,
                    load_all: true,
                ) as $entry_data
            ) {
                $this->entries_manager->addEntry($entry_data);
            }

            $selected_entries = $this->entries_manager->getEntries()->filter(
                fn(Entry $entry_data) => (
                        $mybb->get_input('selected_all_entries', MyBB::INPUT_INT) &&
                        !in_array(
                            $entry_data->entry_slug,
                            (array)json_decode($mybb->get_input('unselected_entries'), true),
                            true
                        )
                    ) || (
                        !$mybb->get_input('selected_all_entries', MyBB::INPUT_INT) &&
                        in_array(
                            $entry_data->entry_slug,
                            (array)json_decode($mybb->get_input('selected_entries'), true),
                            true
                        )
                    )
            );

            switch ($mybb->get_input('moderate')) {
                case 'open':
                    /**
                     * @var Entry $entry_data
                     */
                    foreach ($selected_entries as $entry_data) {
                        $this->entries_repository->updateEntry(['is_closed' => 0], $entry_data->entry_id);
                    }

                    redirect(
                        $this->config['showcase_url'] . $this->routing->generateUrl(
                            Routes::Main,
                            ['clear_selected' => 1]
                        ),
                        $lang->myshowcase_main_inline_moderation_redirect_opened
                    );

                    break;
                case 'close':
                    /**
                     * @var Entry $entry_data
                     */
                    foreach ($selected_entries as $entry_data) {
                        $this->entries_repository->updateEntry(['is_closed' => 1], $entry_data->entry_id);
                    }

                    redirect(
                        $this->config['showcase_url'] . $this->routing->generateUrl(
                            Routes::Main,
                            $this->render_manager->url_params
                        ),
                        $lang->myshowcase_main_inline_moderation_redirect_closed
                    );

                    break;
                case 'stick':
                    /**
                     * @var Entry $entry_data
                     */
                    foreach ($selected_entries as $entry_data) {
                        $this->entries_repository->updateEntry(['is_sticky' => 1], $entry_data->entry_id);
                    }

                    redirect(
                        $this->config['showcase_url'] . $this->routing->generateUrl(
                            Routes::Main,
                            ['clear_selected' => 1]
                        ),
                        $lang->myshowcase_main_inline_moderation_redirect_stuck
                    );

                    break;
                case 'unstick':
                    /**
                     * @var Entry $entry_data
                     */
                    foreach ($selected_entries as $entry_data) {
                        $this->entries_repository->updateEntry(['is_sticky' => 0], $entry_data->entry_id);
                    }

                    redirect(
                        $this->config['showcase_url'] . $this->routing->generateUrl(
                            Routes::Main,
                            ['clear_selected' => 1]
                        ),
                        $lang->myshowcase_main_inline_moderation_redirect_unstuck
                    );

                    break;
                case 'approve':
                    /**
                     * @var Entry $entry_data
                     */
                    foreach ($selected_entries as $entry_data) {
                        $this->entries_repository->updateEntry(
                            ['status' => $this->entries_manager::STATUS_VISIBLE],
                            $entry_data->entry_id
                        );
                    }

                    redirect(
                        $this->config['showcase_url'] . $this->routing->generateUrl(
                            Routes::Main,
                            ['clear_selected' => 1]
                        ),
                        $lang->myshowcase_main_inline_moderation_redirect_approved
                    );

                    break;
                case 'unapprove':
                    /**
                     * @var Entry $entry_data
                     */
                    foreach ($selected_entries as $entry_data) {
                        $this->entries_repository->updateEntry(
                            ['status' => $this->entries_manager::STATUS_PENDING],
                            $entry_data->entry_id
                        );
                    }

                    redirect(
                        $this->config['showcase_url'] . $this->routing->generateUrl(
                            Routes::Main,
                            ['clear_selected' => 1]
                        ),
                        $lang->myshowcase_main_inline_moderation_redirect_unapproved
                    );

                    break;
                case 'copy':
                    break;
                case 'soft_delete':
                    /**
                     * @var Entry $entry_data
                     */
                    foreach ($selected_entries as $entry_data) {
                        $this->entries_repository->updateEntry(
                            ['status' => $this->entries_manager::STATUS_DELETED],
                            $entry_data->entry_id
                        );
                    }

                    redirect(
                        $this->config['showcase_url'] . $this->routing->generateUrl(
                            Routes::Main,
                            ['clear_selected' => 1]
                        ),
                        $lang->myshowcase_main_inline_moderation_redirect_soft_deleted
                    );

                    break;
                case 'restore':
                    /**
                     * @var Entry $entry_data
                     */
                    foreach ($selected_entries as $entry_data) {
                        $this->entries_repository->updateEntry(
                            ['status' => $this->entries_manager::STATUS_VISIBLE],
                            $entry_data->entry_id
                        );
                    }

                    redirect(
                        $this->config['showcase_url'] . $this->routing->generateUrl(
                            Routes::Main,
                            ['clear_selected' => 1]
                        ),
                        $lang->myshowcase_main_inline_moderation_redirect_restored
                    );

                    break;
                case 'delete':
                    /**
                     * @var Entry $entry_data
                     */
                    foreach ($selected_entries as $entry_data) {
                        $this->entries_repository->deleteEntry($entry_data->entry_id);
                    }

                    redirect(
                        $this->config['showcase_url'] . $this->routing->generateUrl(
                            Routes::Main,
                            ['clear_selected' => 1]
                        ),
                        $lang->myshowcase_main_inline_moderation_redirect_deleted
                    );

                    break;
            }

            error_no_permission();
        }

        if ($mybb->get_input('sort')) {
            $this->render_manager->urlParamsAppend(['sort' => $mybb->get_input('sort')]);
        }

        if ($mybb->get_input('direction')) {
            $this->render_manager->urlParamsAppend(['direction' => $mybb->get_input('direction')]);
        }

        if ($mybb->get_input('days', MyBB::INPUT_INT) > 0) {
            $this->render_manager->urlParamsAppend(['days' => $mybb->get_input('days', MyBB::INPUT_INT)]);
        }

        $templatesContext = [
            'url' => [
                'sort' => [
                    'subject' => $this->routing->generateUrl(
                        Routes::Main,
                        array_merge($this->render_manager->url_params, [
                            'sort' => 'subject',
                            'direction' => $this->render_manager->sorting['direction'] === SortDirections::Ascending ? SortDirections::Descending : SortDirections::Ascending
                        ])
                    ),
                    'username' => $this->routing->generateUrl(
                        Routes::Main,
                        array_merge($this->render_manager->url_params, [
                            'sort' => 'username',
                            'direction' => $this->render_manager->sorting['direction'] === SortDirections::Ascending ? SortDirections::Descending : SortDirections::Ascending
                        ])
                    ),
                    'comments' => $this->routing->generateUrl(
                        Routes::Main,
                        array_merge($this->render_manager->url_params, [
                            'sort' => 'comments',
                            'direction' => $this->render_manager->sorting['direction'] === SortDirections::Ascending ? SortDirections::Descending : SortDirections::Ascending
                        ])
                    ),
                    'last_comment' => $this->routing->generateUrl(
                        Routes::Main,
                        array_merge($this->render_manager->url_params, [
                            'sort' => 'last_comment',
                            'direction' => $this->render_manager->sorting['direction'] === SortDirections::Ascending ? SortDirections::Descending : SortDirections::Ascending
                        ])
                    ),
                ],
            ],
            'entry_normal_objects' => [],
            'entry_sticky_objects' => [],
            'users_browsing_counter' => [
                'members' => 0,
                'invisible' => 0,
            ],
        ];

        // Fetch each moderator from the cache and format it, appending it to the list
        foreach ($this->moderators_repository->queryModerators(query_fields: ['user_id', 'is_group']) as $moderator) {
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
            $templatesContext['users_browsing_counter']['guests'] = $this->entries_repository->querySessionsCount();

            foreach ($this->entries_repository->querySessions() as $user_data) {
                if (empty($templatesContext['users_browsing'][$user_data['uid']]) ||
                    $templatesContext['users_browsing'][$user_data['uid']]['time'] < $user_data['time']) {
                    ++$templatesContext['users_browsing_counter']['members'];

                    if (!empty($user_data['invisible'])) {
                        ++$templatesContext['users_browsing_counter']['invisible'];
                    }

                    if (empty($user_data['invisible']) ||
                        !empty($mybb->usergroup['canviewwolinvis']) ||
                        (int)$user_data['uid'] === $this->current_user->user_id) {
                        $templatesContext['users_browsing'][$user_data['uid']] = $user_data;
                    }
                }
            }

            $current_user_data = get_user($this->current_user->user_id);

            // The user was counted as invisible user --> correct the invisible_members_count
            if (!empty($current_user_data['invisible'])) {
                $templatesContext['users_browsing_counter']['invisible'] -= 1;
            }
        }

        hooksRun('controller_main_start', $this);

        $this->forcedFieldBreadcrumb();

        if ($this->current_user->hasPermission(UserPermissions::EntriesCanPost)) {
            $templatesContext['url_entry_create'] = $this->routing->generateUrl(
                Routes::EntryCreate,
            );
        }

        $templatesContext['fieldSetCache'] = array_map(
            function (array &$fieldDefinition) use (&$templatesContext): array {
                $templatesContext['url']['sort'][$fieldDefinition['field_key']] = $this->routing->generateUrl(
                    Routes::Main,
                    array_merge($this->render_manager->url_params, [
                        'sort' => $fieldDefinition['field_key'],
                        'direction' => $this->render_manager->sorting['direction'] === SortDirections::Ascending ? SortDirections::Descending : SortDirections::Ascending
                    ])
                );

                return $fieldDefinition;
            },
            array_filter($this->fieldset_fields, function ($fieldDefinition): bool {
                return $fieldDefinition['display_in_create_update_page'] &&
                    is_member($fieldDefinition['allowed_groups_fill']);
            })
        );

        foreach ($this->fieldset_fields as $field_id => $field_definition) {
            if (!isset($lang->{"myshowcase_fieldset_field_{$this->fieldset_fields[$field_id]['field_key']}"})) {
                $lang->{"myshowcase_fieldset_field_{$this->fieldset_fields[$field_id]['field_key']}"} = ucwords(
                    $this->fieldset_fields[$field_id]['field_key']
                );
            }
        }

        foreach (
            $this->entries_manager->fetchEntries(
                sorting: $this->render_manager->sorting,
                url_params: $this->render_manager->url_params,
                search: $this->render_manager->search,
            ) as $entry_data
        ) {
            $this->entries_manager->addEntry($entry_data);
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
                $entry_data = $this->entries_manager->getEntryByID($attachment_data->entry_id);

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

                $entry_data->addAttachment($attachment_data);
            }

            foreach ($this->entries_manager->getEntries() as $entry_id => $entry_data) {
                $this->loadEntryFields($entry_data);

                $entry_data->setExtraData('unread_comments', false)
                    ->setExtraData('modChecked', false);

                if ($this->current_user->hasPermission(UserPermissions::EntriesCanView)) {
                    $entry_data->setExtraData('modChecked', true);

                    if (!$entry_data->getExtraData('username')) {
                        $entry_data->setExtraData('username', $lang->guest);
                    }
                }

                if ($entry_data->last_comment_id) {
                    $last_comment_data = $this->comments_repository->queryComment(
                        ["comment_id='{$entry_data->last_comment_id}'"],
                        'comment_slug'
                    );
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
                                'entry_slug' => $entry_data->entry_slug,
                                'entry_slug_custom' => $entry_data->entry_slug_custom,
                                'action' => 'last_comment'
                            ],
                        )
                    );

                $fieldCache = [];

                foreach ($this->fieldset_fields as $fieldID => $fieldDefinition) {
                    $entryFieldText = $entry_data->getExtraData($fieldDefinition['field_key']) ?? '';

                    if (!$fieldDefinition['parse'] && $entryFieldText !== false) {
                        // todo, remove this legacy updating the database and updating the format field to TINYINT
                        formatField((int)$fieldDefinition['format'], $entryFieldText);
                    } elseif ($entryFieldText !== false) {
                        $entryFieldText = $this->render_manager->parseMessage((string)$entryFieldText);
                    }

                    $fieldCache[$fieldID] = $entryFieldText;
                }

                // todo, subject should be main, additional fields concatenated
                $entry_data
                    ->setExtraData('fields_cache', $fieldCache)
                    ->setExtraData('selected', false);

                if ($this->render_manager->search['keywords']) {
                    $entry_data->subject = postParser()->highlight_message(
                        postParser()->parse_badwords($entry_data->subject),
                        $this->render_manager->search['keywords']
                    );
                }

                if ($this->current_user->hasPermission(UserPermissions::EntriesCanView)) {
                    $entry_data->setExtraData('selected', true);
                }

                if ($entry_data->status == $this->entries_manager::STATUS_PENDING) {
                    $entry_data->setExtraData('background_class', 'trow_shaded');
                } elseif ($entry_data->status == $this->entries_manager::STATUS_DELETED && $templatesContext['permissions']['entries_can_view]']) {
                    $entry_data->setExtraData('background_class', 'trow_shaded trow_deleted');
                }

                $comment_data = $this->comments_repository->queryComment(
                    ["entry_id={$entry_data->entry_id}"],
                    ['comment_slug', 'showcase_id', 'entry_id', 'user_id', 'dateline'],
                    [
                        'order_by' => 'dateline',
                        'order_dir' => SortDirections::Descending
                    ]
                );

                $entry_data
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
                            ["entry_id='{$entry_data->entry_id}'",]
                        )
                    )
                    ->setExtraData(
                        'comments_by_you',
                        $this->comments_repository->queryCommentCount(
                            [
                                "entry_id='{$entry_data->entry_id}'",
                                "user_id='{$this->current_user->user_id}'",
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
        global $lang, $mybb, $db;

        if ($is_update) {
            $entry_data = $this->entries_manager->fetchEntry(
                ["entry_slug='{$db->escape_string($entry_slug)}'"],
            );

            $this->entries_manager->addEntry($entry_data);

            $entry_data = $this->entries_manager->getEntryByID($entry_data['entry_id']);
        } else {
            $entry_data = new Entry();
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
                $lang->myShowcaseButtonEntryUpdate,
                $this->config['showcase_url'] . $this->routing->generateUrl(
                    Routes::EntryUpdate,
                    [
                        'entry_slug' => $entry_data->entry_slug,
                    ]
                )
            );
        } else {
            add_breadcrumb(
                $lang->myShowcaseButtonEntryCreate,
                $this->config['showcase_url'] . $this->routing->generateUrl(
                    Routes::EntryCreate,
                ),
            );
        }

        if (!$is_update && empty($entry_data->post_hash)) {
            if ($mybb->get_input('post_hash')) {
                $entry_data->post_hash = $mybb->get_input('post_hash');
            } else {
                $entry_data->post_hash = (new UuidUtil())->generateUuidV4();
            }
        }

        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));

            foreach (DATA_TABLE_STRUCTURE['myshowcase_data'] as $field_key => $field_definition) {
                try {
                    $reflection_property = new ReflectionProperty(Entry::class, $field_key);

                    if (!$reflection_property->isReadOnly() && isset($mybb->input[$field_key])) {
                        $entry_data->$field_key = $mybb->input[$field_key];
                    }
                } catch (ReflectionException $exception) {
                }
            }

            foreach ($this->fieldset_fields as $field_id => $field_definition) {
                if (isset($mybb->input[$field_definition['field_key']])) {
                    $entry_data->setExtraData(
                        $field_definition['field_key'],
                        $mybb->input[$field_definition['field_key']]
                    );
                }
            }

            $isPreview = isset($mybb->input['preview']);

            $entry_data->ipaddress = $mybb->session->packedip;

            if (!$is_update) {
                $entry_data->user_id = $this->current_user->user_id;

                $entry_data->dateline = TIME_NOW;
            }

            if ($is_update &&
                $this->current_user->hasPermission(UserPermissions::EntriesModerateUpdates)) {
                $entry_data->status = $this->entries_manager::STATUS_PENDING;
            } elseif (!$is_update &&
                $this->current_user->hasPermission(UserPermissions::EntriesModerateNew)
            ) {
                $entry_data->status = $this->entries_manager::STATUS_PENDING;
            }

            // Set up a showcase handler.
            //Require_once MYBB_ROOT . 'inc/datahandlers/myshowcase_dh.php';

            if ($entry_data->entry_id) {
                $where_clauses = ["entry_id='{$entry_data->entry_id}'"];
            } else {
                $where_clauses = ["post_hash='{$entry_data->post_hash}'"];
            }

            foreach (
                $this->attachments_repository->queryAttachments(
                    $where_clauses,
                    ['attachment_id', 'field_id'],
                ) as $attachment_id => $attachment_data
            ) {
                $entry_data->addAttachment($attachment_data);
            }

            if (!$is_update) {
                $entry_data->dateline = TIME_NOW;
            }

            $this->entries_manager->validateSet(
                $entry_data,
                $is_update ? $this->entries_manager::VALIDATION_METHOD_UPDATE : $this->entries_manager::VALIDATION_METHOD_INSERT
            );

            foreach ($this->fieldset_fields as $field_id => $field_definition) {
                $entry_data->extra_data[$field_definition['field_key']] = match ($field_definition['html_type']) {
                    /*FieldHtmlTypes::CheckBox,
                    FieldHtmlTypes::Select => $mybb->get_input(
                        $field_definition['field_key'],
                        MyBB::INPUT_ARRAY
                    ), */
                    default => $mybb->get_input($field_definition['field_key']),
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
                    $entry_data = $this->entries_manager->validationEntryInsert();
                } else {
                    $entry_data = $this->entries_manager->validationEntryUpdate();
                }

                if (isset($entry_data->status) && $entry_data->status === $this->entries_manager::STATUS_DELETED) {
                    $mainUrl = $this->routing->generateUrl(
                        Routes::Main,
                        $this->render_manager->url_params
                    );

                    redirect(
                        $mainUrl,
                        $is_update ? $lang->myShowcaseEntryEntryUpdatedStatus : $lang->myShowcaseEntryEntryCreatedStatus
                    );
                } else {
                    redirect(
                        $this->config['showcase_url'] . $this->routing->generateUrl(
                            Routes::EntryView,
                            [
                                'entry_slug' => $entry_data->entry_slug,
                                'entry_slug_custom' => $entry_data->entry_slug_custom
                            ]
                        ),
                        $is_update ? $lang->myShowcaseEntryEntryUpdated : $lang->myShowcaseEntryEntryCreated
                    );
                }

                exit;
            }

            if ($isPreview) {
                $templatesContext['entry_preview'] = $this->render_manager->buildEntry($entry_data);

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
                        'entry_slug' => $entry_data->entry_slug,
                    ])
                ) : $this->routing->generateUrl(
                    Routes::EntryCreate,
                    $this->render_manager->url_params
                ),
                'upload' => $this->routing->generateUrl(
                    Routes::AttachmentUpload,
                    [
                        'post_hash' => $entry_data->post_hash,
                        'my_post_key' => $mybb->post_code,
                    ]
                ),
            ],
            'is_update_page' => $is_update,
            'is_preview_page' => false,
            'entry_preview' => '',
            'entry_data' => &$entry_data,
            'attachment_objects' => $this->attachments_repository->queryAttachments(
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
            'post_options' => [
                'allow_signature' => false,
                'disable_smilies' => false,
            ],
        ];

        $current_user_data = get_user($this->current_user->user_id);

        $post_options = $mybb->get_input('post_options', MyBB::INPUT_ARRAY);

        if (!isset($post_options['allow_signature']) && !empty($current_user_data['signature'])) {
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

        $this->loadEntryFields($entry_data, verify_fill_permission: true);

        if (false) {
            $templatesContext['fieldSetCache'] = array_map(
                function (array &$field_definition) use (&$entry_data): array {
                    $field_id = (int)$field_definition['field_id'];

                    $fieldObject = $this->field_factory->create(
                        $field_definition,
                        $entry_data
                    );

                    $field_definition['description'] = $fieldObject->getFieldDescription();

                    $field_definition['code_buttons'] = $field_definition['smiles_inserter'] = '';

                    if (in_array($field_definition['html_type'], [
                        FieldHtmlTypes::Text,
                        //FieldHtmlTypes::Search,
                        FieldHtmlTypes::TextArea,
                    ])) {
                        if ($field_definition['enable_editor']) {
                            $this->render_manager->editorBuild(
                                $field_definition['code_buttons'],
                                $field_definition['smiles_inserter'],
                                $field_definition['field_key']
                            );
                        }
                    }

                    switch ($field_definition['html_type']) {
                        case FieldHtmlTypes::CheckBox:
                        case FieldHtmlTypes::Radio:
                        case FieldHtmlTypes::Select:
                            $field_definition['fieldDataObjects'] = [];

                            foreach (
                                fieldDataGet(
                                    ["field_id='{$field_id}'"],
                                    ['value', 'allowed_groups_fill'],
                                    ['order_by' => 'display_order']
                                ) as $field_data_id => $field_data_data
                            ) {
                                if (is_member($field_definition['allowed_groups_fill'])) {
                                    $field_definition['fieldDataObjects'][$field_data_id] = $field_data_data;
                                }
                            }

                            break;
                        case FieldHtmlTypes::File:
                            $acceptElement = implode(
                                ',',
                                array_merge(
                                    array_column($this->attachment_types, 'mime_type'),
                                    array_column($this->attachment_types, 'file_extension')
                                )
                            );

                            $field_definition['acceptElement'] = $acceptElement;

                            switch ($field_definition['file_capture']) {
                                case 1:
                                    $field_definition['captureElement'] = 'user';

                                    break;
                                case 2:
                                    $field_definition['captureElement'] = 'environment';

                                    break;
                            }
                    }

                    $fieldObject->attachmentsFetch($entry_data);

                    $field_definition['attachment_objects'] = $fieldObject->getAttachments();

                    return $field_definition;
                },
                array_filter($this->fieldset_fields, function ($fieldDefinition): bool {
                    return $fieldDefinition['display_in_create_update_page'] &&
                        is_member($fieldDefinition['allowed_groups_fill']);
                })
            );
        }

        $templatesContext['attachmentsUpload'] = '';
        //$templatesContext['attachmentsUpload'] = $this->render_manager->buildAttachmentsUpload($isEditPage);

        $templatesContext['prefixes'] = build_prefix_select(
            'all',
            $mybb->get_input('prefix_id', MyBB::INPUT_INT),
            false,
            $entry_data->prefix_id
        );

        $templatesContext['login_box'] = template('misc/changeuserbox.twig');

        $templatesContext['post_icons'] = [];

        if ($mybb->settings['allowposticons'] && $this->config['entries_allow_post_icons']) {
            $templatesContext['post_icons'] = get_post_icons();
        }

        $templatesContext['code_buttons'] = $templatesContext['smiles_inserter'] = '';

        if ($this->config['comments_message_build_editor']) {
            $this->render_manager->editorBuild(
                $templatesContext['code_buttons'],
                $templatesContext['smiles_inserter']
            );
        }

        $templatesContext['multi_quote'] = true;

        $templatesContext['external_quotes'] = 1;

        $templatesContext['show_post_options'] = $templatesContext['show_signature'] = $templatesContext['show_disable_smilies'] = $templatesContext['show_attachments'] = false;

        if ($mybb->usergroup['canusesig'] && !$mybb->user['suspendsignature']) {
            $templatesContext['show_post_options'] = $templatesContext['show_signature'] = true;
        }

        if ($this->config['parser_allow_smiles']) {
            $templatesContext['show_post_options'] = $templatesContext['show_disable_smilies'] = true;
        }

        $templatesContext['moderator_options'] = $mybb->get_input('moderator_options', MyBB::INPUT_ARRAY);

        $templatesContext['moderator_options']['is_closed'] ??= $entry_data->is_closed;

        $templatesContext['moderator_options']['is_sticky'] ??= $entry_data->is_sticky;

        $templatesContext['show_moderator_options'] = $templatesContext['show_close_options'] = $templatesContext['show_sticky_options'] = false;

        if ($this->current_user->hasPermission(UserPermissions::EntriesCanClose)) {
            $templatesContext['show_moderator_options'] = $templatesContext['show_close_options'] = true;
        }

        if ($this->current_user->hasPermission(UserPermissions::EntriesCanSticky)) {
            $templatesContext['show_moderator_options'] = $templatesContext['show_sticky_options'] = true;
        }

        $templatesContext['captcha'] = '';

        if ($this->current_user->hasPermission(UserPermissions::CommentsCanPost) &&
            empty($current_user_data['suspendposting']) &&
            (!$entry_data->is_closed || $this->current_user->hasPermission(UserPermissions::EntriesCanView)) &&
            !empty($mybb->settings['quickreply']) &&
            !empty($current_user_data['showquickreply']) &&
            $entry_data->user_id === $this->current_user->user_id) {
            $templatesContext['showquickreply'] = true;

            // Show captcha image for guests if is_enabled
            if ($mybb->settings['captchaimage'] && !$this->current_user->user_id) {
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
     * @throws SyntaxError
     */
    #[Route('/view/{entry_slug}/{entry_slug_custom}', name: 'entries_view', methods: ['GET'])]
    public function view(string $entry_slug, string $entry_slug_custom): string
    {
        global $mybb, $lang, $db;

        $entry_data = $this->entries_manager->fetchEntry(
            ["entry_slug='{$db->escape_string($entry_slug)}'"],
        );

        $this->entries_manager->addEntry($entry_data);

        /**
         * @var Entry $entry_data
         */
        $entry_data = $this->entries_manager->getEntryByID($entry_data['entry_id']);

        if (empty($entry_data->entry_id) ||
            (
                $this->current_user->user_id !== $entry_data->user_id &&
                !$this->current_user->hasPermission(UserPermissions::EntriesCanView) &&
                $entry_data->status !== $this->entries_manager::STATUS_VISIBLE
            )) {
            error($lang->myshowcase_invalid_id);
        }

        // we redirect invalid custom slugs to the correct canonical url
        if ($entry_data->entry_slug_custom !== $entry_slug_custom) {
            $this->redirect($entry_slug);
        }

        //$lang->load('showthread');

        $this->forcedFieldBreadcrumb();

        add_breadcrumb(
            $this->config['name'],
            $this->config['showcase_url'] . $this->routing->generateUrl(
                Routes::Main,
            )
        );

        $mainUrl = $this->routing->generateUrl(
            Routes::Main,
            $this->render_manager->url_params
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
                'threaded' => $this->routing->generateUrl(
                    Routes::EntryView,
                    [
                        'entry_slug' => $entry_data->entry_slug,
                        'entry_slug_custom' => $entry_data->entry_slug_custom,
                        'threaded' => 1
                    ]
                ),
                'subscribe' => $this->routing->generateUrl(
                    Routes::EntryView,
                    [
                        'entry_slug' => $entry_data->entry_slug,
                        'entry_slug_custom' => $entry_data->entry_slug_custom,
                        'action' => 'subscribe',
                        'my_post_key' => $mybb->post_code,
                    ]
                ),
                'unsubscribe' => $this->routing->generateUrl(
                    Routes::EntryView,
                    [
                        'entry_slug' => $entry_data->entry_slug,
                        'entry_slug_custom' => $entry_data->entry_slug_custom,
                        'action' => 'unsubscribe',
                        'my_post_key' => $mybb->post_code,
                    ]
                ),
                'oldest' => $this->routing->generateUrl(
                    Routes::EntryView,
                    [
                        'entry_slug' => $entry_data->entry_slug,
                        'entry_slug_custom' => $entry_data->entry_slug_custom,
                        'action' => 'oldest',
                    ]
                ),
                'newest' => $this->routing->generateUrl(
                    Routes::EntryView,
                    [
                        'entry_slug' => $entry_data->entry_slug,
                        'entry_slug_custom' => $entry_data->entry_slug_custom,
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

        $this->loadEntryFields($entry_data);

        $current_user_data = get_user($this->current_user->user_id);

        if (!empty($current_user_data['signature'])) {
            $templatesContext['post_options']['allow_signature'] = true;
        }

        if ($this->current_user->user_id && $this->subscriptions_repository->queryEntryCount(
                ["entry_id='{$entry_data->entry_id}'", "user_id='{$this->current_user->user_id}'"]
            )) {
            $templatesContext['is_subscribed'] = true;
        }

        if (isset($this->render_manager->url_params['threaded'])) {
            $templatesContext['threaded_mode'] = true;
        }

        //$templatesContext['subject'] = $this->entries_manager->buildSubject($entry_data);

        $templatesContext['meta']['canonical'] = $this->config['showcase_url'] . $this->routing->generateUrl(
                Routes::EntryView,
                [
                    'entry_slug' => $entry_data->entry_slug,
                    'entry_slug_custom' => $entry_data->entry_slug_custom
                ],
            );

        $templatesContext['meta']['page'] = [
            [
                'property' => 'og:url',
                'content' => $this->config['showcase_url'] . $this->routing->generateUrl(
                        Routes::EntryView,
                        [
                            'entry_slug' => $entry_data->entry_slug,
                            'entry_slug_custom' => $entry_data->entry_slug_custom
                        ],
                    ),
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

        $subject = (function () use ($entry_data): string {
                $subject_extra = [];

                foreach ($entry_data->getFields() as $field_data) {
                    if ($field_data->fieldDefinition['enable_subject']) {
                        $subject_extra[] = $field_data->getUserValue();
                    }
                }

                return implode(' ', $subject_extra);
            })() . $entry_data->subject;

        $this->entries_repository->updateEntry(
            new Entry(
                entry_id: $entry_data->entry_id,
                entry_slug_custom: (new StringUtil())->createSlug($subject)->lower()->toString()
            )
        );

        add_breadcrumb(
            $subject,
            $this->config['showcase_url'] . $this->routing->generateUrl(
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

        //trick MyBB into thinking its in archive so it adds bburl to smile link inside parser
        //doing this now should not impact anyhting. no issues with gomobile beta4
        define('IN_ARCHIVE', 1);

        $entry_data->setRenderEntry(
            $this->render_manager->buildEntry($entry_data)
        );

        $entry = $this->entries_manager->getEntryByID($entry_data->entry_id);

        $templatesContext['commentsForm'] = '';

        if ($this->config['comments_enable'] && $this->current_user->hasPermission(UserPermissions::CommentsCanView)) {
            foreach ($this->comments_manager->fetchComments($entry_data->entry_id) as $comment_data) {
                $entry_data->addComment($comment_data);
            }

            $templatesContext['commentObjects'] = [];

            $templatesContext['rendered_comments'] = [];

            $pagination_params = $this->render_manager->url_params;

            unset($pagination_params['page']);

            $templatesContext['pagination'] = multipage(
                $this->comments_manager->getTotalComments(),
                $this->config['comments_per_page'],
                $this->comments_manager->getCurrentPage(),
                $this->config['showcase_url'] . $this->routing->generateUrl(
                    Routes::EntryView,
                    array_merge($pagination_params, [
                        'entry_slug' => $entry_data->entry_slug,
                        'entry_slug_custom' => $entry_data->entry_slug_custom,
                    ])
                )
            );

            foreach ($entry_data->getComments() as $comment_data) {
                $comment_data->setRenderComment(
                    $this->render_manager->buildComment($entry_data, $comment_data)
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
                        'entry_slug' => $entry_data->entry_slug,
                        'entry_slug_custom' => $entry_data->entry_slug_custom,
                    ]
                );

                $templatesContext['formatted_message'] = htmlspecialchars_uni($mybb->get_input('message'));

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
                !$this->current_user->user_id &&
                (
                    ($mybb->session->is_spider && getSetting('ViewsCountSpider')) ||
                    (!$mybb->session->is_spider && getSetting('ViewsCountGuests'))
                )
            ) ||
            (
                $this->current_user->user_id && (getSetting('ViewsCountAuthor') ||
                    $this->current_user->user_id !== $entry_data->user_id)
            )
        ) {
            $this->entries_repository->shutDownQueryIncrementViews($entry_data->entry_id);
        }

        if ($this->current_user->hasPermission(UserPermissions::CommentsCanPost) &&
            empty($current_user_data['suspendposting']) &&
            (!$entry_data->is_closed || $this->current_user->hasPermission(UserPermissions::EntriesCanView)) &&
            !empty($mybb->settings['quickreply']) &&
            !empty($current_user_data['showquickreply']) &&
            $entry_data->user_id === $this->current_user->user_id) {
            $templatesContext['showquickreply'] = true;

            // Show captcha image for guests if is_enabled
            if ($mybb->settings['captchaimage'] && !$this->current_user->user_id) {
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

                    if ($user_data['invisible'] != 1 || $mybb->usergroup['canviewwolinvis'] == 1 || (int)$user_data['uid'] === $this->current_user->user_id) {
                        $user_data['reading'] = my_date($mybb->settings['timeformat'], $user_data['time']);
                        ++$templatesContext['online_count']['users'];

                        $templatesContext['online_users'][] = $user_data;
                    }
                }
            }

            if (!empty($current_user_data['invisible'])) {
                // The user was counted as invisible user --> correct the online_count.invisible
                --$templatesContext['online_count']['invisible'];
            }
        }

        hooksRun('controller_view_end', $this);

        return $this->render_manager->templateGet('pageView', $templatesContext);
    }
}