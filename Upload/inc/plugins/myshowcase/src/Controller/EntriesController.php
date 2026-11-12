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
use DateTime;
use JetBrains\PhpStorm\NoReturn;
use MyBB;
use MyShowcase\Entity\AttachmentEntity;
use MyShowcase\Entity\CustomFieldEntity;
use MyShowcase\Entity\EntryEntity;
use MyShowcase\Enum\{AttachmentsDisplayType,
    ContentStatuses,
    ContentTypes,
    FieldDefaultTypes,
    FieldHtmlTypes,
    FilterFields,
    NotificationTypes,
    Routes,
    SortDirections,
    UserPermissions
};
use MyShowcase\Fields\FieldsInterface;
use MyShowcase\Repository\{AccessLogsRepository,
    AttachmentsRepository,
    CommentsRepository,
    ModeratorsRepository,
    SubscriptionsRepository
};
use MyShowcase\Service\{CommentsManager, EntriesManager};
use MyShowcase\Util\{SelectableLists, StringUtil, UuidUtil};
use Random\RandomException;
use Symfony\Component\Routing\Annotation\Route;
use Twig\Error\{LoaderError, RuntimeError, SyntaxError};

use function MyShowcase\Plugin\Functions\fieldTypeMatchChar;
use function MyShowcase\Plugin\Functions\fieldTypeMatchFloat;
use function MyShowcase\Plugin\Functions\fieldTypeMatchInt;
use function MyShowcase\Plugin\Functions\fieldTypeMatchText;
use function MyShowcase\Plugin\Functions\getSetting;
use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\postParser;

use const MyShowcase\Plugin\Core\DATA_TABLE_STRUCTURE;

class EntriesController extends BaseController
{
    /** The permission each inline moderation action of the main list needs. */
    private const INLINE_MODERATION_PERMISSIONS = [
        'open' => UserPermissions::EntriesCanOpen,
        'close' => UserPermissions::EntriesCanClose,
        'stick' => UserPermissions::EntriesCanSticky,
        'unstick' => UserPermissions::EntriesCanUnstick,
        'approve' => UserPermissions::EntriesCanApprove,
        'unapprove' => UserPermissions::EntriesCanUnapprove,
        'copy' => UserPermissions::EntriesCanCopy,
        'soft_delete' => UserPermissions::EntriesCanSoftDelete,
        'restore' => UserPermissions::EntriesCanRestore,
        'delete' => UserPermissions::EntriesCanDelete,
    ];

    /** The moderation links of the entry page: the status they set (null deletes) and their redirect message. */
    private const ENTRY_PAGE_ACTIONS = [
        'soft_delete' => [ContentStatuses::SoftDeleted, 'myshowcase_main_inline_moderation_redirect_soft_deleted'],
        'restore' => [ContentStatuses::VisibleApproved, 'myshowcase_main_inline_moderation_redirect_restored'],
        'approve' => [ContentStatuses::VisibleApproved, 'myshowcase_main_inline_moderation_redirect_approved'],
        'unapprove' => [ContentStatuses::AwaitingApproval, 'myshowcase_main_inline_moderation_redirect_unapproved'],
        'delete' => [null, 'myshowcase_main_inline_moderation_redirect_deleted'],
    ];

    private const ENTRY_FORM_COLUMNS = ['subject', 'message', 'icon_id', 'post_hash', 'update_note', 'allow_signature', 'disable_smilies'];

    private readonly SubscriptionsRepository $subscriptions_repository;
    private readonly CommentsRepository $comments_repository;
    private readonly AttachmentsRepository $attachments_repository;

    private function clearPassword(): void
    {
        $this->verifyPassword();

        verify_post_check($this->context->mybb->get_input('my_post_key'));

        $access_logs_repository = new AccessLogsRepository(
            $this->context->database,
            $this->context->showcase,
            $this->context->event_dispatcher
        );

        $access_logs_repository->updateAccessLogs(
            ['is_valid' => parent::PASSWORD_IS_INVALID],
            ['user_id' => $this->context->mybb->user['uid']]
        );

        redirect(
            $this->context->showcase->showcase_url . $this->context->routing->generateUrl(Routes::Main),
            $this->context->language->myshowcase_redirect_password_cleared
        );
    }

    /**
     * @throws RuntimeError
     * @throws LoaderError
     * @throws SyntaxError
     * @throws RandomException
     */
    #[NoReturn]
    #[Route('/', name: 'main', methods: ['GET'])]
    public function main(): string
    {
        $this->verifyPassword();

        if ($this->context->mybb->get_input('clear_password', MyBB::INPUT_INT) === 1) {
            $this->clearPassword();
        }

        $query_options = $where_clauses = [];

        $this->buildQueryOptions($query_options, $where_clauses);

        if ($this->context->mybb->get_input('action') === 'inline_moderation') {
            verify_post_check($this->context->mybb->get_input('my_post_key'));

            $permission = self::INLINE_MODERATION_PERMISSIONS[$this->context->mybb->get_input('moderate')] ?? null;

            if ($permission === null || !$this->context->current_user->hasPermission($permission)) {
                $this->errorNoPermission();
            }

            foreach (
                $this->context->entries_repository->fetchEntries(
                    $this->context->custom_fields_collection,
                    $where_clauses,
                    $query_options
                ) as $entry
            ) {
                $this->context->entries_manager->addEntry($entry);
            }

            $selected_entries = $this->context->entries_manager->getEntries()->filter(
                fn(EntryEntity $entry) => (
                        $this->context->mybb->get_input('selected_all_entries', MyBB::INPUT_INT) &&
                        !in_array(
                            $entry->entry_slug,
                            (array)json_decode($this->context->mybb->get_input('unselected_entries'), true),
                            true
                        )
                    ) || (
                        !$this->context->mybb->get_input('selected_all_entries', MyBB::INPUT_INT) &&
                        in_array(
                            $entry->entry_slug,
                            (array)json_decode($this->context->mybb->get_input('selected_entries'), true),
                            true
                        )
                    )
            );

            // One entry the user may not moderate refuses the whole request, before anything changes.
            foreach ($selected_entries as $entry) {
                if (!$this->mayModerateEntry($entry, $permission)) {
                    $this->errorNoPermission();
                }
            }

            $lang = $this->context->language;

            switch ($this->context->mybb->get_input('moderate')) {
                case 'open':
                    $this->performBatchEntryUpdate($selected_entries, ['is_closed' => 0]);
                    $this->redirectAfterModeration($lang->myshowcase_main_inline_moderation_redirect_opened);
                    break;
                case 'close':
                    $this->performBatchEntryUpdate($selected_entries, ['is_closed' => 1]);
                    $this->redirectAfterModeration(
                        $lang->myshowcase_main_inline_moderation_redirect_closed,
                        $this->context->render_manager->url_params
                    );
                    break;
                case 'stick':
                    $this->performBatchEntryUpdate($selected_entries, ['is_sticky' => 1]);
                    $this->redirectAfterModeration($lang->myshowcase_main_inline_moderation_redirect_stuck);
                    break;
                case 'unstick':
                    $this->performBatchEntryUpdate($selected_entries, ['is_sticky' => 0]);
                    $this->redirectAfterModeration($lang->myshowcase_main_inline_moderation_redirect_unstuck);
                    break;
                case 'approve':
                    $this->performBatchEntryUpdate($selected_entries, ['status' => ContentStatuses::VisibleApproved]);
                    $this->redirectAfterModeration($lang->myshowcase_main_inline_moderation_redirect_approved);
                    break;
                case 'unapprove':
                    $this->performBatchEntryUpdate($selected_entries, ['status' => ContentStatuses::AwaitingApproval]);
                    $this->redirectAfterModeration($lang->myshowcase_main_inline_moderation_redirect_unapproved);
                    break;
                case 'copy':
                    break;
                case 'soft_delete':
                    $this->performBatchEntryUpdate($selected_entries, ['status' => ContentStatuses::SoftDeleted]);
                    $this->redirectAfterModeration($lang->myshowcase_main_inline_moderation_redirect_soft_deleted);
                    break;
                case 'restore':
                    $this->performBatchEntryUpdate($selected_entries, ['status' => ContentStatuses::VisibleApproved]);
                    $this->redirectAfterModeration($lang->myshowcase_main_inline_moderation_redirect_restored);
                    break;
                case 'delete':
                    $entry_ids = array_map(static fn(EntryEntity $entry): int => $entry->entry_id, iterator_to_array($selected_entries));

                    foreach ($entry_ids as $entry_id) {
                        $this->context->entries_manager->deleteEntry($entry_id);
                    }
                    $this->redirectAfterModeration($lang->myshowcase_main_inline_moderation_redirect_deleted);
                    break;
            }

            $this->errorNoPermission();
        }

        $order_dir_param = [
            'order_dir' => SortDirections::Descending
        ];

        if (empty($this->context->render_manager->url_params['order_dir']) || $this->context->render_manager->url_params['order_dir'] === SortDirections::Descending) {
            $order_dir_param = [
                'order_dir' => SortDirections::Ascending
            ];
        }

        $templatesContext = [
            'url' => [
                'clear_password' => $this->context->routing->generateUrl(
                    Routes::Main,
                    [
                        'clear_password' => 1,
                        'my_post_key' => $this->context->mybb->post_code
                    ]
                ),
                'sort' => [
                    'subject' => $this->context->routing->generateUrl(
                        Routes::Main,
                        ['order_by' => 'subject'] + $order_dir_param + $this->context->render_manager->url_params,
                    ),
                    'username' => $this->context->routing->generateUrl(
                        Routes::Main,
                        ['order_by' => 'username'] + $order_dir_param + $this->context->render_manager->url_params,
                    ),
                    'comments' => $this->context->routing->generateUrl(
                        Routes::Main,
                        ['order_by' => 'comments'] + $order_dir_param + $this->context->render_manager->url_params,
                    ),
                    'views' => $this->context->routing->generateUrl(
                        Routes::Main,
                        ['order_by' => 'views'] + $order_dir_param + $this->context->render_manager->url_params,
                    ),
                    'last_comment' => $this->context->routing->generateUrl(
                        Routes::Main,
                        ['order_by' => 'last_comment'] + $order_dir_param + $this->context->render_manager->url_params,
                    ),
                ],
            ],
            'normal_entries' => [],
            'stuck_entries' => [],
            'users_browsing_counter' => [
                'members' => 0,
                'invisible' => 0,
                'guests' => 0,
            ],
            'icons_cache' => $this->context->showcase->entries_allow_post_icons ?
                (array)$this->context->mybb->cache->read('posticons') : [],
        ];

        // Fetch each moderator from the cache and format it, appending it to the list
        if ($this->context->showcase->main_display_moderators) {
            $moderators_repository = new ModeratorsRepository(
                $this->context->database,
                $this->context->event_dispatcher,
                $this->context->showcase
            );

            foreach ($moderators_repository->queryModerators(query_fields: ['user_id', 'group_id']) as $moderator) {
                if ($moderator['group_id'] &&
                    $group_data = $this->context->mybb->cache->read('usergroups')[$moderator['group_id']]) {
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
        if ($this->context->showcase->main_display_users_browsing) {
            $templatesContext['users_browsing_counter']['guests'] = $this->context->entries_repository->querySessionsCount(
            );

            foreach ($this->context->entries_repository->querySessions() as $user_data) {
                if (empty($templatesContext['users_browsing'][$user_data['uid']]) ||
                    $templatesContext['users_browsing'][$user_data['uid']]['time'] < $user_data['time']) {
                    ++$templatesContext['users_browsing_counter']['members'];

                    if (!empty($user_data['invisible'])) {
                        ++$templatesContext['users_browsing_counter']['invisible'];
                    }

                    if (empty($user_data['invisible']) ||
                        !empty($this->context->mybb->usergroup['canviewwolinvis']) ||
                        (int)$user_data['uid'] === $this->context->mybb->user['uid']) {
                        $templatesContext['users_browsing'][$user_data['uid']] = $user_data;
                    }
                }
            }

            // The user was counted as invisible user --> correct the invisible_members_count
            if (!empty($this->context->mybb->user['invisible'])) {
                $templatesContext['users_browsing_counter']['invisible'] -= 1;
            }
        }

        hooksRun('controller_main_start', $this);

        $this->forcedFieldBreadcrumb($this->context->render_manager->url_params['user_id'] ?? null);

        add_breadcrumb(
            $this->context->showcase->name,
            $this->context->showcase->showcase_url . $this->context->routing->generateUrl(Routes::Main)
        );

        if ($this->context->current_user->hasPermission(UserPermissions::EntriesCanPost)) {
            $templatesContext['url']['create_entry'] = $this->context->routing->generateUrl(
                Routes::EntryCreate,
            );
        }

        $templatesContext['unapproved_entries'] = '';

        if ($this->context->current_user->hasPermission(UserPermissions::EntriesCanViewUnapproved) &&
            ($unapproved_count = $this->context->entries_repository->queryEntryCount(
                ["entry_data.status='" . ContentStatuses::AwaitingApproval . "'"]
            ))) {
            $templatesContext['unapproved_entries'] = [
                'url' => $this->context->routing->generateUrl(Routes::Main, ['unapproved' => 1]),
                'text' => $this->context->language->sprintf(
                    $this->context->language->myshowcase_unapproved_count,
                    htmlspecialchars_uni($this->context->showcase->name),
                    my_number_format($unapproved_count)
                ),
            ];
        }

        /**
         * @var CustomFieldEntity $field
         */
        foreach ($this->context->custom_fields_collection as $field_id => $field) {
            if ($field->isViewableInMain()) {
                $templatesContext['url']['sort'][$field->field_key] = $this->context->routing->generateUrl(
                    Routes::Main,
                    ['order_by' => $field->field_key] + $order_dir_param + $this->context->render_manager->url_params,
                );
            }
        }

        /**
         * @var CustomFieldEntity $field
         */
        foreach ($this->context->custom_fields_collection as $field_id => $field) {
            if (!isset($this->context->language->{"myshowcase_collection_field_{$field->field_key}"})) {
                $this->context->language->{"myshowcase_collection_field_{$field->field_key}"} = "myshowcase_collection_field_{$field->field_key}";
            }
        }

        $total_entries = $this->context->entries_repository->countEntries(
            $this->context->custom_fields_collection,
            $where_clauses
        );

        $this->buildQueryLimit($query_options, $total_entries);

        foreach (
            $this->context->entries_repository->fetchEntries(
                $this->context->custom_fields_collection,
                $where_clauses,
                $query_options
            ) as $entry_data
        ) {
            $this->context->entries_manager->addEntry($entry_data);
        }

        $templatesContext['total_entries'] = $total_entries;

        if ($templatesContext['total_entries']) {
            $pagination_params = $this->context->render_manager->url_params;

            unset($pagination_params['page']);

            $templatesContext['pagination'] = multipage(
                $templatesContext['total_entries'],
                $this->mainPerPage(),
                $this->context->mybb->input['page'],
                $this->context->showcase->showcase_url . $this->context->routing->generateUrl(
                    Routes::Main,
                    $pagination_params
                ),
            );

            $file_field_ids = [];

            foreach ($this->context->custom_fields_collection as $field) {
                if ($field->html_type === FieldHtmlTypes::File) {
                    $file_field_ids[] = (int)$field->field_id;
                }
            }

            // get first attachment for each showcase on this page; the files of file fields give the row its image
            if ($this->context->showcase->attachments_main_render || $file_field_ids) {
                foreach (
                    $this->context->attachments_repository->queryAttachments(
                        [
                            "entry_id IN ('" . implode(
                                "','",
                                $this->context->entries_manager->getEntries()->pluck('entry_id')
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
                            'update_dateline',
                        ],
                    ) as $attachment_data
                ) {
                    $this->context->attachments_manager->addAttachment($attachment_data);
                }
            }

            foreach ($this->context->attachments_manager->getAttachments() as $attachment_data) {
                $entry = $this->context->entries_manager->getEntryByID($attachment_data->entry_id);

                $attachment_data
                    ->setExtraData('file_size', get_friendly_size($attachment_data->file_size))
                    ->setExtraData(
                        'is_image',
                        str_starts_with($attachment_data->mime_type, 'image/')
                    )
                    ->setExtraData('icon', get_attachment_icon($attachment_data->attachment_name));

                $attachment_data->has_thumbnail = !empty($attachment_data->thumbnail_name) && $this->context->showcase->attachments_display_type === AttachmentsDisplayType::Thumbnails;

                $attachment_data->setExtraData(
                    'url',
                    $this->context->routing->generateUrl(
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
                        $this->context->routing->generateUrl(
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

            foreach ($this->context->entries_manager->getEntries() as $entry_id => $entry) {
                $this->loadEntryFields($entry);

                $entry
                    ->setExtraData('unread_comments', false) // todo, implement unread comments
                    ->setExtraData('modChecked', false)
                    ->setExtraData('dateline_timestamp', $entry->dateline->getTimestamp());

                if ($this->isEntryModerator()) {
                    $entry->setExtraData('modChecked', true);

                    if (!$entry->getExtraData('username')) {
                        $entry->setExtraData('username', $this->context->language->guest);
                    }
                }

                if ($entry->last_comment_id) {
                    $last_comment_data = $this->context->comments_repository->queryComment(
                        ["comment_id='{$entry->last_comment_id}'"],
                        'comment_slug'
                    );
                }

                $entry
                    ->setExtraData(
                        'url',
                        $this->context->routing->generateUrl(
                            Routes::EntryView,
                            [
                                'entry_slug' => $entry->entry_slug,
                                'entry_slug_custom' => $entry->entry_slug_custom
                            ],
                        )
                    )
                    ->setExtraData(
                        'url_last_comment',
                        $this->context->routing->generateUrl(
                            Routes::EntryView,
                            [
                                'entry_slug' => $entry->entry_slug,
                                'entry_slug_custom' => $entry->entry_slug_custom,
                                'action' => 'last_comment',
                            ],
                        )
                    )
                    ->setExtraData(
                        'entryUrlLastComment',
                        !empty($last_comment_data['comment_slug']) ? $this->context->routing->generateUrl(
                            Routes::CommentView,
                            [
                                'comment_slug' => $last_comment_data['comment_slug'],
                            ],
                        ) : $this->context->routing->generateUrl(
                            Routes::EntryView,
                            [
                                'entry_slug' => $entry->entry_slug,
                                'entry_slug_custom' => $entry->entry_slug_custom,
                                'action' => 'last_comment'
                            ],
                        )
                    );

                $entry->setExtraData('selected', false);

                // The subject of the row, as on the entry page: the subject fields (escaped or parsed by their
                // field classes), then the subject of the entry.
                $subject_parts = [];

                /** @var FieldsInterface $field */
                foreach ($entry->getFields() as $field) {
                    if ($field->field_definition['enable_subject'] && $field->getUserValue() !== '') {
                        $subject_parts[] = $field->getUserValue();
                    }
                }

                if ($this->context->showcase->entries_enable_subject && (string)$entry->subject !== '') {
                    $subject_parts[] = htmlspecialchars_uni(postParser()->parse_badwords((string)$entry->subject));
                }

                $subject_html = implode(' ', $subject_parts);

                if (isset($this->context->mybb->input['keywords']) && is_string($this->context->mybb->input['keywords'])) {
                    $subject_html = postParser()->highlight_message($subject_html, $this->context->mybb->input['keywords']);
                }

                $entry->setExtraData('subject_html', $subject_html);

                $entry->setExtraData('main_image', $this->mainImage($entry, $file_field_ids));

                if ($this->isEntryModerator()) {
                    $entry->setExtraData('selected', true);
                }

                if ($entry->status === ContentStatuses::AwaitingApproval) {
                    $entry->setExtraData('background_class', 'trow_shaded');
                } elseif ($entry->status === ContentStatuses::SoftDeleted &&
                    $this->context->current_user->hasPermission(UserPermissions::EntriesCanViewDeleted)) {
                    $entry->setExtraData('background_class', 'trow_shaded trow_deleted');
                } else {
                    $entry->setExtraData('background_class', '');
                }

                $entry->setExtraData('unread_comments', false); // todo, implement unread comments

                $comment_data = $this->context->comments_repository->queryComment(
                    ["entry_id={$entry->entry_id}"],
                    ['comment_slug', 'showcase_id', 'entry_id', 'user_id', 'dateline'],
                    [
                        'order_by' => 'dateline',
                        'order_dir' => SortDirections::Descending
                    ]
                );

                if (empty($comment_data)) {
                    $entry
                        ->setExtraData('last_commenter_user_data', false)
                        ->setExtraData('last_comment_date', new DateTime());
                } else {
                    $entry
                        ->setExtraData('last_commenter_user_data', get_user($comment_data['user_id']))
                        ->setExtraData(
                            'last_comment_url',
                            $this->context->routing->generateUrl(
                                Routes::CommentView,
                                [
                                    'comment_slug' => $comment_data['comment_slug'],
                                ]
                            )
                        )
                        ->setExtraData('last_comment_date', new DateTime($comment_data['dateline']));
                }

                $entry
                    ->setExtraData(
                        'attachments_count',
                        $this->context->attachments_repository->queryAttachmentCount(
                            ["entry_id='{$entry->entry_id}'",]
                        )
                    )
                    ->setExtraData(
                        'comments_by_you',
                        $this->context->comments_repository->queryCommentCount(
                            [
                                "entry_id='{$entry->entry_id}'",
                                "user_id='{$this->context->mybb->user['uid']}'",
                            ]
                        )
                    );

                if (empty($entry->is_sticky)) {
                    $templatesContext['normal_entries'][$entry_id] = $entry;
                } else {
                    $templatesContext['stuck_entries'][$entry_id] = $entry;
                }
            }
        }

        hooksRun('controller_main_intermediate', $this);

        return $this->context->render_manager->templateGet(
            'pageMain',
            $templatesContext
        );
    }

    /**
     * The moderation links of the entry page (delete=1 soft deletes, delete=2 deletes, or action=...): the same
     * permission as the inline action, plus moderator rights for the entries of other users.
     */
    private function entryPageModeration(EntryEntity $entry): void
    {
        $action = match ($this->context->mybb->get_input('delete', MyBB::INPUT_INT)) {
            1 => 'soft_delete',
            2 => 'delete',
            default => $this->context->mybb->get_input('action'),
        };

        if (!isset(self::ENTRY_PAGE_ACTIONS[$action])) {
            return;
        }

        verify_post_check($this->context->mybb->get_input('my_post_key'));

        [$status, $message] = self::ENTRY_PAGE_ACTIONS[$action];

        if (!$this->mayModerateEntry($entry, self::INLINE_MODERATION_PERMISSIONS[$action])) {
            $this->errorNoPermission();
        }

        $lang = $this->context->language;

        if ($status === null) {
            $this->context->entries_manager->deleteEntry($entry->entry_id);

            redirect($this->context->showcase->showcase_url . $this->context->routing->generateUrl(Routes::Main), $lang->$message);
        }

        $this->performBatchEntryUpdate([$entry], ['status' => $status]);

        redirect(
            $this->context->showcase->showcase_url . $this->context->routing->generateUrl(
                Routes::EntryView,
                ['entry_slug' => $entry->entry_slug, 'entry_slug_custom' => $entry->entry_slug_custom]
            ),
            $lang->$message
        );
    }

    /**
     * The files of the entry's file fields the form asks to remove (remove_attachments[]): of the stored entry when
     * it is edited, of the form's post hash when it is created. Any other id is ignored.
     *
     * @return int[]
     */
    private function submittedAttachmentRemovals(EntryEntity $entry, bool $is_update): array
    {
        $requested = array_filter(array_map('intval', $this->context->mybb->get_input('remove_attachments', MyBB::INPUT_ARRAY)));

        $file_field_ids = [];

        foreach ($this->context->custom_fields_collection as $field) {
            if ($field->html_type === FieldHtmlTypes::File) {
                $file_field_ids[] = (int)$field->field_id;
            }
        }

        if (!$requested || !$file_field_ids) {
            return [];
        }

        $owner = $is_update ? "entry_id='{$entry->entry_id}'" :
            "entry_id='0' AND post_hash='{$this->context->database->escape_string((string)$entry->post_hash)}'";

        return array_map('intval', array_column(iterator_to_array($this->context->attachments_repository->queryAttachments(
            [
                $owner,
                "attachment_id IN ('" . implode("','", $requested) . "')",
                "field_id IN ('" . implode("','", $file_field_ids) . "')",
            ],
            'attachment_id'
        ), false), 'attachment_id'));
    }

    /**
     * A new entry waits for approval when the user's new entries are moderated, an edited one when the user's edits
     * are; otherwise a new entry is visible and an edited one keeps its status. Bypassing moderation skips both.
     */
    private function submittedEntryStatus(EntryEntity $entry, bool $is_update): int
    {
        $moderated = !$this->context->current_user->hasPermission(UserPermissions::CanBypassModeration) &&
            $this->context->current_user->hasPermission(
                $is_update ? UserPermissions::EntriesModerateUpdates : UserPermissions::EntriesModerateNew
            );

        if ($moderated) {
            return ContentStatuses::AwaitingApproval;
        }

        return $is_update ? (int)$entry->status : ContentStatuses::VisibleApproved;
    }

    /**
     * The entry is built from the submitted form merged over the stored entry: only the columns a user fills in may
     * come from the form, the code sets the others (author, status, counters, slugs, dates). The post hash of an
     * existing entry is the stored one, as it decides which uploaded files belong to the entry.
     */
    private function dropEntryColumnsFromInput(bool $is_update): void
    {
        $form_columns = $is_update ? array_diff(self::ENTRY_FORM_COLUMNS, ['post_hash']) : self::ENTRY_FORM_COLUMNS;

        foreach (array_keys(DATA_TABLE_STRUCTURE['myshowcase_data']) as $column) {
            if (!in_array($column, $form_columns, true)) {
                unset($this->context->mybb->input[$column]);
            }
        }
    }

    /**
     * The image of a main list row: the first image uploaded to one of the entry's file fields, through the
     * thumbnail route (which shows the image itself when it has no thumbnail), for users who may load it.
     *
     * @param int[] $file_field_ids
     * @return array{url: string, name: string}|false
     */
    private function mainImage(EntryEntity $entry, array $file_field_ids): array|false
    {
        $images = $entry->getAttachments()->filter(
            static fn(AttachmentEntity $attachment): bool => in_array((int)$attachment->field_id, $file_field_ids, true) &&
                str_starts_with((string)$attachment->mime_type, 'image/')
        );

        if (!$images->count()) {
            return false;
        }

        $attachments = iterator_to_array($images);

        ksort($attachments);

        /** @var AttachmentEntity $image */
        $image = reset($attachments);

        $user_id = (int)$this->context->current_user->uid;

        if (!($user_id > 0 && $user_id === (int)$image->user_id) && (
                !$this->context->current_user->hasPermission(UserPermissions::AttachmentsCanView) ||
                !$this->context->current_user->hasPermission(UserPermissions::AttachmentsCanDownload))) {
            return false;
        }

        return [
            'url' => $this->context->routing->generateUrl(
                Routes::AttachmentThumbnail,
                ['attachment_hash' => $image->attachment_hash, 'attachment_name' => $image->attachment_name]
            ),
            'name' => $image->attachment_name,
        ];
    }

    /**
     * The search page: the main list with its keywords, for users who may search entries. Searches are not
     * stored, so a search hash selects nothing more.
     *
     * @throws RuntimeError
     * @throws LoaderError
     * @throws SyntaxError
     * @throws RandomException
     */
    public function search(?string $search_hash = null): string
    {
        if (!$this->context->current_user->hasPermission(UserPermissions::CanSearchEntries)) {
            $this->errorNoPermission();
        }

        return $this->main();
    }

    /**
     * Checkboxes post an array. A field holds one value until multiple values are supported: one checked box is
     * that value and none is empty, while several stay an array, which the validation rejects instead of keeping
     * only the last one.
     */
    private function normalizeSubmittedFieldValues(): void
    {
        foreach ($this->context->custom_fields_collection as $field) {
            $submitted = $this->context->mybb->input[$field->field_key] ?? null;

            if (!is_array($submitted)) {
                continue;
            }

            $values = array_values(array_filter(
                array_map(static fn(mixed $value): string => is_scalar($value) ? trim((string)$value) : '', $submitted),
                static fn(string $value): bool => $value !== ''
            ));

            $this->context->mybb->input[$field->field_key] = count($values) > 1 ? $values : ($values[0] ?? '');
        }
    }

    /**
     * @throws SyntaxError
     * @throws RuntimeError
     * @throws LoaderError|RandomException
     */
    #[Route('/create', name: 'entry_create', methods: ['GET', 'POST'])]
    public function create(string $entry_slug = '', bool $is_update = false): string
    {
        $this->verifyPassword();

        if (!$is_update && !$this->context->current_user->hasPermission(UserPermissions::EntriesCanPost)) {
            $this->errorNoPermission();
        }

        if ($is_update) {
            $where_clauses = ["entry_data.entry_slug='{$this->context->database->escape_string($entry_slug)}'"];

            if ($visibility_clause = $this->context->entries_manager->visibilityWhereClause()) {
                $where_clauses[] = $visibility_clause;
            }

            $entry = $this->context->entries_repository->fetchEntry(
                $this->context->custom_fields_collection,
                $where_clauses
            );

            if (empty($entry)) {
                $this->error($this->context->language->myshowcase_invalid_id, 404);
            }

            $this->context->entries_manager->addEntry($entry);

            $entry = $this->context->entries_manager->getEntryByID($entry['entry_id']);

            $this->entryPageModeration($entry);

            // Only the author, or a moderator, may edit an entry.
            if (!$this->context->current_user->hasPermission(UserPermissions::EntriesCanUpdate) ||
                (!$this->isEntryOwner($entry) && !$this->isEntryModerator())) {
                $this->errorNoPermission();
            }
        } else {
            $entry = new EntryEntity();

            if ($this->context->current_user->hasPermission(UserPermissions::EntriesCanPost) &&
                !$this->context->entries_manager->canPostEntries()) {
                $this->error(
                    $this->context->language->sprintf(
                        $this->context->language->myshowcase_entry_limit_reached,
                        my_number_format($this->context->entries_manager->entryPostLimit())
                    ),
                    403
                );
            }

            $this->context->entries_manager->attachFields($entry);
        }

        if ($this->context->mybb->request_method === 'post') {
            verify_post_check($this->context->mybb->get_input('my_post_key'));

            $this->dropEntryColumnsFromInput($is_update);

            $this->normalizeSubmittedFieldValues();
        }

        $this->forcedFieldBreadcrumb();

        add_breadcrumb(
            $this->context->showcase->name,
            $this->context->showcase->showcase_url . $this->context->routing->generateUrl(
                Routes::Main,
            )
        );

        if ($is_update) {
            add_breadcrumb(
                $this->context->language->myshowcase_button_entry_update,
                $this->context->showcase->showcase_url . $this->context->routing->generateUrl(
                    Routes::EntryUpdate,
                    [
                        'entry_slug' => $entry->entry_slug,
                    ]
                )
            );
        } else {
            add_breadcrumb(
                $this->context->language->myshowcase_main_button_entry_create,
                $this->context->showcase->showcase_url . $this->context->routing->generateUrl(
                    Routes::EntryCreate,
                ),
            );
        }

        $this->loadEntryFields($entry, true);

        foreach (DATA_TABLE_STRUCTURE['myshowcase_data'] as $field_key => $field_definition) {
            if (isset($this->context->mybb->input[$field_key])) {
                continue;
            }
            //$this->context->mybb->input[$field_key] ??= (string)$entry->$field_key;
        }

        /**
         * @var FieldsInterface $field
         */
        foreach ($entry->getFields() as $field_id => $field) {
            if (isset($this->context->mybb->input[$field->field_definition['field_key']])) {
                continue;
            }

            switch ($field->field_definition['default_type']) {
                case FieldDefaultTypes::AsDefined:
                    // A new entry's form starts with the field's default value.
                    if (!$is_update && $field->getValue() === '') {
                        $field->valueSet((string)$field->field_definition['default_value']);
                    }

                    break;
                case FieldDefaultTypes::IsNull:
                    //$this->context->mybb->input[$field->field_definition['field_key']] = '';

                    break;
                case FieldDefaultTypes::CurrentTimestamp:
                    //$this->context->mybb->input[$field->field_definition['field_key']] = date('Y-m-d H:i:s', TIME_NOW);

                    break;
                case FieldDefaultTypes::UUID:
                    //$this->context->mybb->input[$field->field_definition['field_key']] = (new UuidUtil())->generateUuidV4();

                    break;
            }
        }

        if (!$is_update && empty($entry->post_hash)) {
            // The form sends back the hash it was given, which is always a generated UUID v4 (MyShowcase's own format:
            // MyBB core's posthash is an md5, this plugin's is not). Anything else can only be a tampered post, and is
            // replaced; the hash goes into queries and into a VARCHAR(36) column.
            $posted_post_hash = $this->context->mybb->get_input('post_hash');

            if ((new UuidUtil())->isValidUuidV4($posted_post_hash)) {
                $entry->post_hash = $posted_post_hash;
            } else {
                $entry->post_hash = (new UuidUtil())->generateUuidV4();
            }
        }

        foreach ($this->context->custom_fields_collection as $field_id => $field) {
            if (!isset($this->context->mybb->input[$field->field_key])) {
                //$this->context->mybb->input[$field->field_key] = '';
            }

            // A submitted form replaces the values in the form; loading the page keeps the stored ones.
            if ($this->context->mybb->request_method === 'post') {
                $entry->setExtraData($field->field_key, $this->context->mybb->input[$field->field_key] ?? '');
            }

            $field->setExtraData(
                'upload_url',
                $is_update ? $this->context->routing->generateUrl(
                    Routes::EntryUpdateUploadApi,
                    [
                        'entry_slug' => $entry->entry_slug,
                        'my_post_key' => $this->context->mybb->post_code,
                    ]
                ) : $this->context->routing->generateUrl(
                    Routes::EntryCreateUploadApi,
                    [
                        'post_hash' => $entry->post_hash,
                        'my_post_key' => $this->context->mybb->post_code,
                        'field_id' => $field->field_id,
                    ]
                )
            );
        }

        $templatesContext = [
            'url' => [
                'crate_update' => $is_update ? $this->context->routing->generateUrl(
                    Routes::EntryUpdate,
                    [
                        'entry_slug' => $entry->entry_slug,
                    ]
                ) : $this->context->routing->generateUrl(
                    Routes::EntryCreate,
                ),
                'upload' => $is_update ? $this->context->routing->generateUrl(
                    Routes::EntryUpdateUploadApi,
                    [
                        'entry_slug' => $entry->entry_slug,
                        'my_post_key' => $this->context->mybb->post_code,
                    ]
                ) : $this->context->routing->generateUrl(
                    Routes::EntryCreateUploadApi,
                    [
                        'post_hash' => $entry->post_hash,
                        'my_post_key' => $this->context->mybb->post_code,
                        'field_id' => $field->field_id,
                    ]
                ),
            ],
            'can_use_signature' => $this->context->entries_manager->canUseSignature(),
            'is_update_page' => $is_update,
            'is_preview_page' => false,
            'entry_preview' => '',
            'entry' => &$entry,
            'attachment_objects' => $this->context->attachments_repository->queryAttachments(
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
                    'update_dateline',
                ],
            ),
            'post_options' => [
                'allow_signature' => false,
                'disable_smilies' => false,
            ],
            'attachments' => false,
            'attachment_count' => 0,
            'user_attach_quota' => 0,
        ];

        //$this->context->mybb->input = array_merge((array)$entry, $this->context->mybb->input);

        if ($entry->entry_id) {
            $where_clauses = ["entry_id='{$entry->entry_id}'"];
        } else {
            $where_clauses = ["post_hash='{$entry->post_hash}'"];
        }

        foreach (
            $this->context->attachments_repository->queryAttachments(
                $where_clauses,
                ['attachment_id', 'field_id'],
            ) as $attachment_id => $attachment_data
        ) {
            //$entry->addAttachment($attachment_data);
        }

        // What the user posted, before it is mixed with the entry. Only EntriesManager::allowedEntryInput() reads it
        // to build the entry; the merged input below is for showing the form again.
        $posted_input = $this->context->mybb->input;

        $this->context->mybb->input = array_merge((array)$entry, $this->context->mybb->input);

        if (!$is_update) {
            // The form carries the validated hash, never a posted one that was replaced.
            $this->context->mybb->input['post_hash'] = $entry->post_hash;
        }

        if ($this->context->mybb->request_method === 'post') {
            foreach ($this->context->custom_fields_collection as $field) {
                if (!$field->isViewableInForm() || $field->read_only) {
                    // The form does not have the field: a value posted for it is ignored and the stored one stays.
                    unset($this->context->mybb->input[$field->field_key]);

                    continue;
                }

                // An unchecked checkbox or radio is not posted at all: the user emptied the field.
                $this->context->mybb->input[$field->field_key] ??= '';
            }
        }

        foreach ($this->context->entries_manager->buildFieldValues($this->context->mybb->input) as $field_key => $field_value) {
            $this->context->mybb->input[$field_key] = $field_value;
        }

        foreach ($this->context->custom_fields_collection as $field_id => $field) {
            $entry->getCustomFields()->filter(
                fn(EntryCustomField $custom_field) => $custom_field->field_key === $field->field_key
            );
        }

        if ($this->context->mybb->request_method === 'post') {
            foreach ($this->context->custom_fields_collection as $field_id => $field) {
                if (isset($this->context->mybb->input[$field->field_key])) {
                    //$entry->setExtraData($field->field_key, $this->context->mybb->input[$field->field_key]);
                }
            }

            $isPreview = isset($this->context->mybb->input['preview']);

            //$entry->ipaddress = $this->context->mybb->session->packedip;

            if (!$is_update) {
                //$entry->user_id = $this->context->mybb->user['uid'];

                //$entry->dateline = TIME_NOW;
            }

            if ($is_update) {
                $entry->status = $this->context->entries_manager->updatedEntryStatus((int)$entry->status);
            }

            // Set up a showcase handler.
            //Require_once MYBB_ROOT . 'inc/datahandlers/myshowcase_dh.php';

            if (!$is_update) {
                //$entry->dateline = TIME_NOW;
            }

            // The stored (or new) entry, then the posted values that are allowed, then the values only the server sets.
            $preview_entry = $this->context->entries_manager->generateEntry(array_merge(
                (array)$entry,
                $this->context->entries_manager->allowedEntryInput($posted_input, $is_update),
                [
                    'entry_id' => $entry->entry_id,
                    'user_id' => $entry->user_id,
                'status' => $this->submittedEntryStatus($entry, $is_update),
            ],
                $is_update ? [] : ['status' => $this->context->entries_manager->newEntryStatus()]
            ));

            $this->context->entries_manager->validateSet(
                $preview_entry,
                $is_update ? EntriesManager::VALIDATION_METHOD_UPDATE : EntriesManager::VALIDATION_METHOD_INSERT
            );

            $removed_attachment_ids = $this->submittedAttachmentRemovals($entry, $is_update);

            $this->context->entries_manager->setRemovedAttachments($removed_attachment_ids);

            // todo, slug should load all field data after update/insert to build

            if (!$this->context->entries_manager->validateEntry()) {
                $this->context->render_manager->error_messages = array_merge(
                    $this->context->render_manager->error_messages,
                    $this->context->entries_manager->validateGetError()
                );
            }

            if (!$isPreview && !$this->context->render_manager->error_messages) {
                if ($is_update) {
                    $entry = $this->context->entries_manager->validationEntryUpdate();
                } else {
                    $entry = $this->context->entries_manager->validationEntryInsert();
                }

                foreach ($removed_attachment_ids as $attachment_id) {
                    $this->context->attachments_repository->deleteAttachment($attachment_id);
                }

                if ($this->is_api_request) {
                    if ($this->context->current_user->uid) {
                        $user_permissions = user_permissions($this->context->current_user->uid);
                    } else {
                        $user_permissions = usergroup_permissions(1);
                    }

                    $parser_options = [
                        'allow_html' => !empty($this->context->showcase->parser_allow_html_in_entries) &&
                            ($entry_author = $this->context->users_manager->getUserByID((int)$entry->user_id)) &&
                            $entry_author->hasPermission(UserPermissions::ParserAllowHtmlInEntries),
                        'allow_mycode' => !empty($this->context->mybb->settings['sigmycode']),
                        'allow_smilies' => !empty($this->context->mybb->settings['sigsmilies']),
                        'allow_imgcode' => !empty($this->context->mybb->settings['sigimgcode']),
                        'me_username' => $entry->username
                    ];

                    if ($user_permissions['signofollow']) {
                        $parser_options['nofollow_on'] = true;
                    }

                    $data = [
                        'message' => postParser()->parse_message(
                            $entry->message,
                            ['me_username' => $entry->username] + $parser_options
                        ),
                    ];

                    if ($this->context->showcase->entries_enable_update_notes) {
                        $data['update_note'] = $entry->update_note;
                    }

                    $this->success($data);
                }

                if (isset($entry->status) && $entry->status === ContentStatuses::SoftDeleted) {
                    $mainUrl = $this->context->routing->generateUrl(
                        Routes::Main,
                        $this->context->render_manager->url_params
                    );

                    redirect(
                        $mainUrl,
                        $is_update ? $this->context->language->myshowcase_entry_entry_updated_status : $this->context->language->myshowcase_entry_entry_created_status
                    );
                } else {
                    redirect(
                        $this->context->showcase->showcase_url . $this->context->routing->generateUrl(
                            Routes::EntryView,
                            [
                                'entry_slug' => $entry->entry_slug,
                                'entry_slug_custom' => $entry->entry_slug_custom
                            ]
                        ),
                        match (true) {
                            $is_update && $entry->status === ContentStatuses::AwaitingApproval &&
                            $this->context->current_user->hasPermission(UserPermissions::EntriesModerateUpdates) => $this->context->language->myshowcase_entry_entry_updated_awaiting_approval,
                            $is_update => $this->context->language->myshowcase_entry_entry_updated,
                            $entry->status === ContentStatuses::AwaitingApproval => $this->context->language->myshowcase_entry_entry_created_awaiting_approval,
                            default => $this->context->language->myshowcase_entry_entry_created,
                        }
                    );
                }

                exit;
            }

            if ($isPreview) {
                $templatesContext['entry'] = $preview_entry;

                $templatesContext['entry_preview'] = $this->context->render_manager->buildEntry($entry, true);

                $templatesContext['is_preview_page'] = true;
            }
        }

        $post_options = $this->context->mybb->get_input('post_options', MyBB::INPUT_ARRAY);

        if (!isset($post_options['allow_signature']) && !empty($this->context->mybb->user['signature'])) {
            $templatesContext['post_options']['allow_signature'] = true;
        }

        if (isset($post_options['allow_signature']) && empty($post_options['allow_signature'])) {
            $templatesContext['post_options']['allow_signature'] = false;
        }

        if (!empty($post_options['disable_smilies'])) {
            $templatesContext['post_options']['disable_smilies'] = true;
        }

        hooksRun('controller_create_start', $this);

        $this->loadEntryFields($entry, is_create_update_page: true);

        $templatesContext['attachmentsUpload'] = '';
        //$templatesContext['attachmentsUpload'] = $this->context->render_manager->buildAttachmentsUpload($isEditPage);

        $templatesContext['post_icons'] = [];

        if ($this->context->mybb->settings['allowposticons'] && $this->context->showcase->entries_allow_post_icons) {
            $templatesContext['post_icons'] = (new SelectableLists(
                $this->context->language, mybb: $this->context->mybb
            ))
                ->postIconsList();
        }

        $templatesContext['code_buttons'] = $templatesContext['smiles_inserter'] = '';

        if ($this->context->showcase->entries_message_build_editor) {
            $this->context->render_manager->editorBuild(
                $templatesContext['code_buttons'],
                $templatesContext['smiles_inserter'],
                'message'
            );
        }

        $templatesContext['multi_quote'] = true;

        $templatesContext['external_quotes'] = 1;

        //$templatesContext['show_post_options'] = $templatesContext['show_signature'] = $templatesContext['show_disable_smilies'] = $templatesContext['show_attachments'] = false;

        if ($this->context->mybb->usergroup['canusesig'] && !$this->context->mybb->user['suspendsignature']) {
            //$templatesContext['show_post_options'] = $templatesContext['show_signature'] = true;
        }

        if ($this->context->showcase->parser_allow_smiles_in_entries) {
            //$templatesContext['show_post_options'] = $templatesContext['show_disable_smilies'] = true;
        }

        $templatesContext['moderator_options'] = $this->context->mybb->get_input(
            'moderator_options',
            MyBB::INPUT_ARRAY
        );

        $templatesContext['moderator_options']['is_closed'] ??= $entry->is_closed;

        $templatesContext['moderator_options']['is_sticky'] ??= $entry->is_sticky;

        $templatesContext['show_moderator_options'] = $templatesContext['show_close_options'] = $templatesContext['show_sticky_options'] = false;

        if ($this->context->current_user->hasPermission(UserPermissions::EntriesCanClose)) {
            $templatesContext['show_moderator_options'] = $templatesContext['show_close_options'] = true;
        }

        if ($this->context->current_user->hasPermission(UserPermissions::EntriesCanSticky)) {
            $templatesContext['show_moderator_options'] = $templatesContext['show_sticky_options'] = true;
        }

        $templatesContext['captcha'] = '';

        if ($this->context->current_user->hasPermission(UserPermissions::CommentsCanPost) &&
            empty($this->context->mybb->user['suspendposting']) &&
            (!$entry->is_closed || $this->context->current_user->hasPermission(UserPermissions::EntriesCanView)) &&
            !empty($this->context->mybb->settings['quickreply']) &&
            !empty($this->context->mybb->user['showquickreply']) &&
            $entry->user_id === $this->context->mybb->user['uid']) {
            $templatesContext['showquickreply'] = true;

            if ($this->context->current_user->hasPermission(UserPermissions::MainRequireCaptcha)) {
                require_once MYBB_ROOT . 'inc/class_captcha.php';

                $post_captcha = new captcha(true, 'post');

                if ($post_captcha->html) {
                    $templatesContext['captcha'] = $post_captcha->html;
                }
            }
        }

        hooksRun('controller_create_end', $this);

        return $this->context->render_manager->templateGet(
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
    #[NoReturn]
    #[Route('/view/{entry_slug}/{entry_slug_custom}', name: 'entries_view', methods: ['GET'])]
    #[Route('/api/view/{entry_slug}/{entry_slug_custom}', name: 'entries_view', methods: ['GET'])]
    public function view(string $entry_slug, string $entry_slug_custom = '', ?string $comment_slug = null): string
    {
        $this->verifyPassword();

        $where_clauses = ["entry_data.entry_slug='{$this->context->database->escape_string($entry_slug)}'"];

        if ($visibility_clause = $this->context->entries_manager->visibilityWhereClause()) {
            $where_clauses[] = $visibility_clause;
        }

        $entry = $this->context->entries_repository->fetchEntry(
            $this->context->custom_fields_collection,
            [
                "entry_data.entry_slug='{$this->context->database->escape_string($entry_slug)}'",
                $this->entryVisibilityClause(),
            ]
        );

        if (empty($entry)) {
            $this->error($this->context->language->myshowcase_invalid_id, 404);
        }

        $this->context->entries_manager->addEntry($entry);

        /**
         * @var EntryEntity $entry
         */
        $entry = $this->context->entries_manager->getEntryByID((int)$entry['entry_id']);

        switch ($this->context->mybb->get_input('action')) {
            case 'subscribe':
                verify_post_check($this->context->mybb->get_input('my_post_key'));

                if (!$this->context->showcase->subscriptions_enable || !$this->context->current_user->uid) {
                    $this->errorNoPermission();
                }

                $existing_subscription = $this->context->subscriptions_repository->querySubscription(
                    [
                        "content_type='" . ContentTypes::Entry . "'",
                        "user_id='{$this->context->current_user->uid}'",
                        "entry_id='{$entry->entry_id}'"
                    ],
                    [
                        'subscription_id',
                    ],
                );

                if (!$existing_subscription) {
                    $this->context->subscriptions_repository->insertSubscription(
                        [
                            'content_type' => ContentTypes::Entry,
                            'user_id' => $this->context->current_user->uid,
                            'entry_id' => $entry->entry_id,
                            'notification_type' => NotificationTypes::NoNotification,
                            'dateline' => date('Y-m-d H:i:s', TIME_NOW),
                        ],
                    );
                }

                redirect(
                    $this->context->showcase->showcase_url . $this->context->routing->generateUrl(
                        Routes::EntryView,
                        [
                            'entry_slug' => $entry->entry_slug,
                            'entry_slug_custom' => $entry->entry_slug_custom
                        ]
                    ),
                    $this->context->language->myshowcase_redirect_subscribed_to_entry,
                );

                break;
            case 'unsubscribe':
                verify_post_check($this->context->mybb->get_input('my_post_key'));

                if (!$this->context->showcase->subscriptions_enable || !$this->context->current_user->uid) {
                    $this->errorNoPermission();
                }

                $existing_subscription = $this->context->subscriptions_repository->querySubscription(
                    [
                        "content_type='" . ContentTypes::Entry . "'",
                        "user_id='{$this->context->current_user->uid}'",
                        "entry_id='{$entry->entry_id}'"
                    ],
                    [
                        'subscription_id',
                    ],
                );

                if ($existing_subscription) {
                    $this->context->subscriptions_repository->deleteSubscription(
                        (int)$existing_subscription['subscription_id']
                    );
                }

                redirect(
                    $this->context->showcase->showcase_url . $this->context->routing->generateUrl(
                        Routes::EntryView,
                        [
                            'entry_slug' => $entry->entry_slug,
                            'entry_slug_custom' => $entry->entry_slug_custom
                        ]
                    ),
                    $this->context->language->myshowcase_redirect_unsubscribed_to_entry,
                );

                break;
        }

        // we redirect invalid custom slugs to the correct canonical url
        if ($entry->entry_slug_custom !== $entry_slug_custom) {
            //$this->redirect($entry_slug);
            // todo, check if slug is empty and fix if so
        }

        if ($this->is_api_request) {
            $data = [
                'entry_slug' => $entry->entry_slug,
                'message' => $entry->message,
            ];

            if ($this->context->showcase->entries_enable_update_notes) {
                $data['update_note'] = $entry->update_note;
            }

            $this->success($data);
        }

        //$this->context->language->load('showthread');

        $this->forcedFieldBreadcrumb($entry->user_id);

        add_breadcrumb(
            $this->context->showcase->name,
            $this->context->showcase->showcase_url . $this->context->routing->generateUrl(
                Routes::Main,
                $this->context->showcase->main_filter_force_field === FilterFields::UserID ? ['user_id' => $entry->user_id] : []
            )
        );

        $mainUrl = $this->context->routing->generateUrl(
            Routes::Main,
            $this->context->render_manager->url_params
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
                'entry' => $this->context->routing->generateUrl(
                    Routes::EntryView,
                    [
                        'entry_slug' => $entry->entry_slug,
                        'entry_slug_custom' => $entry->entry_slug_custom
                    ]
                ),
                'comment' => $this->context->routing->generateUrl(
                    Routes::CommentCreate,
                    [
                        'entry_slug' => $entry->entry_slug,
                    ]
                ),
                'threaded' => $this->context->routing->generateUrl(
                    Routes::EntryView,
                    [
                        'entry_slug' => $entry->entry_slug,
                        'entry_slug_custom' => $entry->entry_slug_custom,
                        'threaded' => 1
                    ]
                ),
                'subscribe' => $this->context->routing->generateUrl(
                    Routes::EntryView,
                    [
                        'entry_slug' => $entry->entry_slug,
                        'entry_slug_custom' => $entry->entry_slug_custom,
                        'action' => 'subscribe',
                        'my_post_key' => $this->context->mybb->post_code,
                    ]
                ),
                'unsubscribe' => $this->context->routing->generateUrl(
                    Routes::EntryView,
                    [
                        'entry_slug' => $entry->entry_slug,
                        'entry_slug_custom' => $entry->entry_slug_custom,
                        'action' => 'unsubscribe',
                        'my_post_key' => $this->context->mybb->post_code,
                    ]
                ),
                'oldest' => $this->context->routing->generateUrl(
                    Routes::EntryView,
                    [
                        'entry_slug' => $entry->entry_slug,
                        'entry_slug_custom' => $entry->entry_slug_custom,
                        'action' => 'oldest',
                    ]
                ),
                'newest' => $this->context->routing->generateUrl(
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
        ];

        hooksRun('controller_view_start', $this);

        $this->loadEntryFields($entry);

        if (!empty($this->context->mybb->user['signature'])) {
            $templatesContext['post_options']['allow_signature'] = true;
        }

        if ($this->context->mybb->user['uid'] && $this->context->subscriptions_repository->queryEntryCount(
                ["entry_id='{$entry->entry_id}'", "user_id='{$this->context->mybb->user['uid']}'"]
            )) {
            $templatesContext['is_subscribed'] = true;
        }

        if (isset($this->context->render_manager->url_params['threaded'])) {
            $templatesContext['threaded_mode'] = true;
        }

        //$templatesContext['subject'] = $this->context->entries_manager->buildSubject($entry);

        $templatesContext['meta']['canonical'] = $this->context->showcase->showcase_url . $this->context->routing->generateUrl(
                Routes::EntryView,
                [
                    'entry_slug' => $entry->entry_slug,
                    'entry_slug_custom' => $entry->entry_slug_custom
                ],
            );

        $templatesContext['meta']['page'] = [
            [
                'property' => 'og:url',
                'content' => $this->context->showcase->showcase_url . $this->context->routing->generateUrl(
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

                /**
                 * @var FieldsInterface $field
                 */
                foreach ($entry->getFields() as $field) {
                    if ($field->field_definition['enable_subject']) {
                        $subject_extra[] = $field->getUserValue();
                    }
                }

                return implode(' ', $subject_extra);
            })() . $entry->subject;

        $entry_slug_custom = (new StringUtil())->generateSlug($subject)->lower()->toString();

        // The subject is built from what this visitor may see; an empty slug would make the entry's links fail.
        if ($entry_slug_custom !== '' && $entry_slug_custom !== $entry->entry_slug_custom) {
            $this->context->entries_repository->updateEntry(
                $this->context->entries_manager->generateEntry(
                    [
                        'entry_id' => $entry->entry_id,
                        'entry_slug_custom' => $entry_slug_custom
                    ]
                ),
            );
        }

        add_breadcrumb(
            $subject,
            $this->context->showcase->showcase_url . $this->context->routing->generateUrl(
                Routes::EntryView,
                [
                    'entry_slug' => $entry->entry_slug,
                    'entry_slug_custom' => $entry->entry_slug_custom
                ],
            )
        );

        if (!$entry->getExtraData('username')) {
            $entry->setExtraData('username', $this->context->language->guest);
        }

        //trick MyBB into thinking its in archive so it adds bburl to smile link inside parser
        //doing this now should not impact anyhting. no issues with gomobile beta4
        define('IN_ARCHIVE', 1);

        $entry->setRenderEntry(
            $this->context->render_manager->buildEntry($entry)
        );

        $entry = $this->context->entries_manager->getEntryByID($entry->entry_id);

        $templatesContext['commentsForm'] = '';

        $query_options = [];

        $where_clauses = ["comment_data.entry_id='{$entry->entry_id}'"];

        $this->buildQueryOptions($query_options, $where_clauses, ContentTypes::Comment);

        $total_comments = $this->context->comments_repository->queryCommentCount($where_clauses);

        $this->buildQueryLimit($query_options, $total_comments, ContentTypes::Comment);

        $templatesContext['commentObjects'] = [];

        if ($this->context->showcase->comments_enable &&
            $this->context->current_user->hasPermission(UserPermissions::CommentsCanView)) {
            foreach (
                $this->context->comments_repository->fetchComments(
                    $where_clauses,
                    $query_options,
                ) as $comment_data
            ) {
                $this->context->comments_manager->addComment($comment_data);
            }

            $templatesContext['rendered_comments'] = [];

            $pagination_params = $this->context->render_manager->url_params;

            unset($pagination_params['page']);

            $templatesContext['pagination'] = multipage(
                $total_comments,
                $this->context->showcase->comments_per_page,
                $this->context->render_manager->url_params['page'] ?? CommentsManager::FIRST_PAGE,
                $this->context->showcase->showcase_url . $this->context->routing->generateUrl(
                    Routes::EntryView,
                    array_merge($pagination_params, [
                        'entry_slug' => $entry->entry_slug,
                        'entry_slug_custom' => $entry->entry_slug_custom,
                    ])
                )
            );

            foreach ($this->context->comments_manager->getComments() as $comment_data) {
                $comment_data->setRenderComment(
                    $this->context->render_manager->buildComment($entry, $comment_data)
                );

                $templatesContext['commentObjects'][$comment_data->comment_id] = $comment_data;
            }

            $templatesContext['comments_can_post'] = $this->context->current_user->hasPermission(
                UserPermissions::CommentsCanPost
            );

            if ($this->context->current_user->hasPermission(UserPermissions::CommentsCanPost)) {
                global $collapsedthead, $collapsedimg, $expaltext, $collapsed;

                isset($collapsedthead) || $collapsedthead = [];

                isset($collapsedimg) || $collapsedimg = [];

                isset($collapsed) || $collapsed = [];

                $collapsedthead['quickreply'] ??= '';

                $collapsedimg['quickreply'] ??= '';

                $collapsed['quickreply_e'] ??= '';

                $templatesContext['url']['create_comment'] = $this->context->routing->generateUrl(
                    Routes::CommentCreate,
                    [
                        'entry_slug' => $entry->entry_slug,
                    ]
                );

                $templatesContext['code_buttons'] = $templatesContext['smiles_inserter'] = '';

                if ($this->context->showcase->comments_message_build_editor) {
                    $this->context->render_manager->editorBuild(
                        $templatesContext['code_buttons'],
                        $templatesContext['smiles_inserter']
                    );
                }
            }
        }

        // Update view count
        if (
            (
                !$this->context->mybb->user['uid'] &&
                (
                    ($this->context->mybb->session->is_spider && getSetting('ViewsCountSpider')) ||
                    (!$this->context->mybb->session->is_spider && getSetting('ViewsCountGuests'))
                )
            ) ||
            (
                $this->context->mybb->user['uid'] && (getSetting('ViewsCountAuthor') ||
                    $this->context->mybb->user['uid'] !== $entry->user_id)
            )
        ) {
            $this->context->entries_repository->shutDownQueryIncrementViews($entry->entry_id);
        }

        if ($this->context->current_user->hasPermission(UserPermissions::CommentsCanPost) &&
            empty($this->context->mybb->user['suspendposting']) &&
            (!$entry->is_closed || $this->context->current_user->hasPermission(UserPermissions::EntriesCanView)) &&
            !empty($this->context->mybb->settings['quickreply']) &&
            !empty($this->context->mybb->user['showquickreply']) &&
            $entry->user_id === $this->context->mybb->user['uid']) {
            $templatesContext['showquickreply'] = true;

            if ($this->context->current_user->hasPermission(UserPermissions::MainRequireCaptcha)) {
                require_once MYBB_ROOT . 'inc/class_captcha.php';

                $post_captcha = new captcha(true, 'post');

                if ($post_captcha->html) {
                    $templatesContext['captcha'] = $post_captcha->html;
                }
            }
        }

        // Get users viewing this thread
        if ($this->context->showcase->entries_display_users_browsing) {
            $timecut = TIME_NOW - $this->context->mybb->settings['wolcutoff'];

            $doneusers = array();

            $query = $this->context->database->simple_select(
                "sessions s LEFT JOIN {$this->context->database->table_prefix}users u ON (s.uid=u.uid)",
                's.ip, s.uid, s.time, u.username, u.invisible, u.usergroup, u.displaygroup',
                "s.uid!='0' AND s.time>'{$timecut}' AND nopermission!='1'",
                [
                    'order_by' => 'u.username ASC, s.time',
                    'order_dir' => SortDirections::Descending,
                ]
            );

            while ($user_data = $this->context->database->fetch_array($query)) {
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

                    if ($user_data['invisible'] !== 1 || $this->context->mybb->usergroup['canviewwolinvis'] === 1 || (int)$user_data['uid'] === $this->context->mybb->user['uid']) {
                        $user_data['reading'] = my_date(
                            $this->context->mybb->settings['timeformat'],
                            $user_data['time']
                        );
                        ++$templatesContext['online_count']['users'];

                        $templatesContext['online_users'][] = $user_data;
                    }
                }
            }

            if (!empty($this->context->mybb->user['invisible'])) {
                // The user was counted as invisible user --> correct the online_count.invisible
                --$templatesContext['online_count']['invisible'];
            }
        }

        hooksRun('controller_view_end', $this);

        return $this->context->render_manager->templateGet('pageView', $templatesContext);
    }

    /**
     * Updates every selected entry with the given field overrides and redirects.
     *
     * @param iterable<EntryEntity> $entries
     */
    private function performBatchEntryUpdate(iterable $entries, array $update_data): void
    {
        /** @var EntryEntity $entry */
        foreach ($entries as $entry) {
            $this->context->entries_repository->updateEntry(
                $this->context->entries_manager->generateEntry(
                    array_merge(['entry_id' => $entry->entry_id], $update_data)
                )
            );
        }
    }

    #[NoReturn]
    private function redirectAfterModeration(
        string $message,
        array $url_params = ['clear_selected' => 1]
    ): void {
        redirect(
            $this->context->showcase->showcase_url . $this->context->routing->generateUrl(Routes::Main, $url_params),
            $message
        );
    }

    private function buildQueryOptions(
        array &$query_options,
        array &$where_clauses,
        int $content_type = ContentTypes::Entry
    ): void {
        if ($content_type === ContentTypes::Entry) {
            $alias = 'entry_data';

            $query_options['order_by'] = $this->context->render_manager->url_params['order_by'] ?? $this->context->showcase->main_sort_default_field;
        } else {
            $alias = 'comment_data';

            $comments_sort_fields = array_keys(
                (new SelectableLists($this->context->language))->sortFieldsCommentList()
            );

            if (isset($this->context->render_manager->url_params['order_by']) &&
                in_array($this->context->render_manager->url_params['order_by'], $comments_sort_fields, true)) {
                $query_options['order_by'] = $this->context->render_manager->url_params['order_by'];
            } else {
                $query_options['order_by'] = array_shift($comments_sort_fields);
            }
        }

        if ($content_type === ContentTypes::Comment) {
            // Comments are filtered by their own moderation permissions. CommentsCanView only means the user may
            // read comments at all, so it must not reveal unapproved or soft-deleted ones.
            if ($visibility_clause = $this->statusVisibilityClause(
                $alias,
                UserPermissions::CommentsCanViewUnapproved,
                UserPermissions::CommentsCanViewDeleted
            )) {
                $where_clauses[] = $visibility_clause;
            }
        } else {
            if ($visibility_clause = $this->entryVisibilityClause($alias)) {
                $where_clauses[] = $visibility_clause;
            }

            // The approval queue: the waiting entries only.
            if (!empty($this->context->render_manager->url_params['unapproved']) &&
                $this->context->current_user->hasPermission(UserPermissions::EntriesCanViewUnapproved)) {
                $where_clauses[] = "{$alias}.status='" . ContentStatuses::AwaitingApproval . "'";
            }
        }

        if (empty($this->context->render_manager->url_params['order_dir'])) {
            $sort_ascending = $content_type === ContentTypes::Comment
                ? $this->context->showcase->comments_sort_direction
                : $this->context->showcase->main_sort_direction;

            if ($sort_ascending) {
                $query_options['order_dir'] = SortDirections::Ascending;
            } else {
                $query_options['order_dir'] = SortDirections::Descending;
            }
        } else {
            $query_options['order_dir'] = $this->context->render_manager->url_params['order_dir'];
        }

        if ($content_type === ContentTypes::Comment) {
            // Comments written in the same second keep their order: the id breaks the tie in the same direction.
            $query_options['order_by'] = "{$query_options['order_by']} {$query_options['order_dir']}, comment_id";
        }

        if (isset($this->context->render_manager->url_params['user_id'])) {
            $where_clauses[] = "{$alias}.user_id='" . $this->context->render_manager->url_params['user_id'] . "'";
        } elseif ($this->context->showcase->main_filter_force_field === FilterFields::UserID) {
            $where_clauses[] = "{$alias}.user_id='" . $this->context->mybb->user['uid'] . "'";
        }

        if (empty($this->context->render_manager->url_params['threshold']['days']) && !empty($this->context->mybb->user['daysprune'])) {
            $where_clauses[] = "{$alias}.dateline>='" . (TIME_NOW - ($this->context->mybb->user['daysprune'] * 86400)) . "'";
        } elseif (!empty($this->context->render_manager->url_params['threshold']['days'])) {
            $where_clauses[] = "{$alias}.dateline>='" . (TIME_NOW - ($this->context->render_manager->url_params['threshold']['days'] * 86400)) . "'";
        }

        $keywords = array_filter(
            array_map('trim', explode(' ', $this->context->render_manager->url_params['keywords'] ?? ''))
        );

        // Keywords filter the entries for users who may search them only.
        if ($keywords && $content_type === ContentTypes::Entry &&
            $this->context->current_user->hasPermission(UserPermissions::CanSearchEntries)) {
            $search_where_clauses = [];

            $exact = !empty($this->context->render_manager->url_params['exact_match']);

            $match = function (string $column, string $keyword) use ($exact): string {
                $keyword = $this->context->database->escape_string($keyword);

                return $exact ? "{$column}='{$keyword}'" : "{$column} LIKE '%{$keyword}%'";
            };

            foreach ($keywords as $keyword) {
                if ($this->context->showcase->entries_enable_subject) {
                    $search_where_clauses[] = $match("{$alias}.subject", $keyword);
                }

                foreach ($this->context->custom_fields_collection as $field) {
                    if (!$field->enable_search) {
                        continue;
                    }

                    if (in_array($field->html_type, [FieldHtmlTypes::CheckBox, FieldHtmlTypes::Radio, FieldHtmlTypes::Select], true)) {
                        // The option label, from the option table fetchEntries() joins.
                        $search_where_clauses[] = '(' . $match("table_{$field->field_key}.value", $keyword) . ' OR ' .
                            $match("table_{$field->field_key}.display_style", $keyword) . ')';
                    } elseif (fieldTypeMatchChar($field->field_type) || fieldTypeMatchText($field->field_type)) {
                        $search_where_clauses[] = $match("{$alias}.{$field->field_key}", $keyword);
                    } elseif ((fieldTypeMatchInt($field->field_type) || fieldTypeMatchFloat($field->field_type)) &&
                        is_numeric($keyword)) {
                        // LIKE on a number column fails on PostgreSQL: numbers are matched exactly.
                        $search_where_clauses[] = "{$alias}.{$field->field_key}='" . (float)$keyword . "'";
                    }
                }
            }

            if ($search_where_clauses) {
                if (empty($this->context->render_manager->url_params['exact_match'])) {
                    $search_where_clauses = implode(' OR ', $search_where_clauses);
                } else {
                    $search_where_clauses = implode(' AND ', $search_where_clauses);
                }

                $where_clauses[] = "({$search_where_clauses})";
            }
        }
    }

    /**
     * Entries per page of the main list: 0 (or less) falls back to 10.
     */
    private function mainPerPage(): int
    {
        return $this->context->showcase->main_per_page > 0 ? $this->context->showcase->main_per_page : 10;
    }

    private function buildQueryLimit(
        array &$query_options,
        int $total_items,
        int $content_type = ContentTypes::Entry
    ): void {
        if ($content_type === ContentTypes::Entry) {
            $query_options['limit'] = $this->mainPerPage();
        } else {
            $query_options['limit'] = $this->context->showcase->comments_per_page;
        }

        if ($query_options['limit'] > 0) {
            if ($this->context->mybb->input['page'] > ceil($total_items / $query_options['limit'])) {
                $this->context->mybb->input['page'] = EntriesManager::FIRST_PAGE;
            }

            if ($this->context->mybb->input['page']) {
                $query_options['limit_start'] = ($this->context->mybb->input['page'] - 1) * $query_options['limit'];
            } else {
                $this->context->mybb->input['page'] = EntriesManager::FIRST_PAGE;
            }
        }
    }
}