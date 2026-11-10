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

namespace MyShowcase\Service;

use Exception;
use MyBB;
use MyShowcase\Collection\Collection;
use MyShowcase\Entity\AttachmentEntity;
use MyShowcase\Entity\CommentEntity;
use MyShowcase\Entity\CustomFieldEntity;
use MyShowcase\Entity\EntryEntity;
use MyShowcase\Entity\PermissionEntity;
use MyShowcase\Entity\UserEntity;
use MyShowcase\Enum\AttachmentsDisplayType;
use MyShowcase\Enum\CacheDataTypes;
use MyShowcase\Enum\ContentStatuses;
use MyShowcase\Enum\ContentTypes;
use MyShowcase\Enum\SortDirections;
use MyShowcase\Enum\UserPermissions;
use MyShowcase\Repository\AttachmentsRepository;
use MyShowcase\Repository\EntriesRepository;
use MyShowcase\Enum\Routes;
use MyShowcase\ShowcaseContext;
use MyShowcase\Util\SelectableLists;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

use function MyBB\app;
use function MyBB\View\template;
use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\postParser;

use const MyShowcase\VERSION_CODE;
use const MyShowcase\DEBUG;

class RenderManager
{
    private readonly EntriesRepository $entries_repository;
    private readonly AttachmentsRepository $attachments_repository;
    private int $post_type = ContentTypes::Entry;
    private bool $is_preview = false;
    private int $user_id = 0;
    private UserEntity $user_data;
    public array $error_messages = [];
    public array $url_params = [];
    public int $current_page = 1;
    public array $collection_display_fields = [];
    public array $template_context = [];

    public function __construct(
        public readonly ShowcaseContext $context,
    ) {
        $this->url_params['page'] = 1;

        if ($this->context->mybb->get_input('page', MyBB::INPUT_INT) > 0) {
            $this->url_params['page'] = $this->context->mybb->get_input('page', MyBB::INPUT_INT);
        }

        if ($this->context->mybb->get_input('user_id', MyBB::INPUT_INT) > 0) {
            $this->url_params['user_id'] = $this->context->mybb->get_input('user_id', MyBB::INPUT_INT);
        }

        if ($this->context->mybb->get_input('unapproved', MyBB::INPUT_INT)) {
            $this->url_params['unapproved'] = $this->context->mybb->get_input('unapproved', MyBB::INPUT_INT);
        }

        $this->collection_display_fields = (new SelectableLists($this->context->language))->sortFieldsEntryList();

        /**
         * @var CustomFieldEntity $field
         */
        foreach ($this->context->custom_fields_collection as $field_id => $field) {
            if ($field->isViewableInMain()) {
                $this->collection_display_fields[$field->field_key] = '';
            }
        }

        $this->url_params['order_dir'] = $this->context->mybb->get_input('order_dir');

        if (!in_array($this->url_params['order_dir'], [SortDirections::Ascending, SortDirections::Descending])) {
            unset($this->url_params['order_dir']);
        }

        $this->url_params['order_by'] = $this->context->mybb->get_input('order_by');

        if (!array_key_exists($this->url_params['order_by'], $this->collection_display_fields)) {
            unset($this->url_params['order_by']);
        }

        if ($this->context->mybb->get_input('exact_match', MyBB::INPUT_INT) === 1) {
            $this->url_params['exact_match'] = 1;
        }

        if ($this->context->mybb->get_input('keywords') !== '') {
            $this->url_params['keywords'] = $this->context->mybb->get_input('keywords');
        }

        if ($this->context->mybb->get_input('threaded', MyBB::INPUT_INT) === 1) {
            $this->url_params['threaded'] = 1;
        }

        $this->url_params['threshold'] = array_filter(
            array_map('intval', $this->context->mybb->get_input('threshold', MyBB::INPUT_ARRAY))
        );

        if (!empty($this->context->mybb->input['highlight'])) {
            $this->url_params['threshold'] = trim($this->context->mybb->get_input('highlight'));
        }

        if (empty($this->url_params['threshold'])) {
            unset($this->url_params['threshold']);
        }

        if (empty($this->url_params['threshold'])) {
            unset($this->url_params['threshold']);
        }
    }

    /**
     * @throws SyntaxError
     * @throws RuntimeError
     * @throws LoaderError
     */
    public function templateGet(string $name = '', array $context = []): string
    {
        $this->template_context = array_merge_recursive([
            'showcase' => [
                'collection_display_fields' => $this->collection_display_fields,
                'custom_fields_collection' => $this->context->custom_fields_collection,
                'version' => DEBUG ? TIME_NOW : VERSION_CODE,
            ],
            'meta' => [
                'page' => [],
            ],
            'url' => [
                'showcase' => $this->context->showcase->showcase_url,
                'showcase_assets' => $this->context->mybb->get_asset_url($this->context->showcase->showcase_url),
                'main' => $this->context->routing->generateUrl(Routes::Main),
            ],
        ],
            array_merge([
                'config' => $this->context->showcase,
                'current_page' => $this->url_params['page'],
                'current_user' => $this->context->current_user,
                'url_params' => $this->url_params,
                'permissions' => $this->context->current_user->getPermissions(),
                'error_messages' => empty($this->error_messages) ? '' : inline_error(
                    $this->error_messages
                ),
                'version' => DEBUG ? TIME_NOW : VERSION_CODE,
                'pagination' => '',
            ], $context));

        hooksRun('render_template_get', $this);


        if ($this->context->showcase->custom_template_prefix !== '' &&
            $this->templateExists($this->context->showcase->custom_template_prefix . '/' . $name)) {
            return template(
                '@ext.myshowcase/' . $this->context->showcase->custom_template_prefix . '/' . $name . '.twig',
                $this->template_context
            );
        } else {
            return template(
                '@ext.myshowcase/' . $name . '.twig',
                $this->template_context
            );
        }
    }

    public function templateExists(string $template_name): bool
    {
        /** @var Environment $twig */
        $twig = app(Environment::class);

        $loader = $twig->getLoader();

        return $loader->exists("@ext.myshowcase/{$template_name}.twig");
    }

    public function groupUserTitlesGet(): array
    {
        static $titles_cache = null;

        if ($titles_cache === null) {
            $titles_cache = [];

            foreach ((array)$this->context->mybb->cache->read('usertitles') as $title) {
                if (!empty($title)) {
                    $titles_cache[(int)$title['posts']] = $title;
                }
            }
        }

        return $titles_cache;
    }

    /**
     * @throws SyntaxError
     * @throws RuntimeError
     * @throws LoaderError
     */
    private function buildPost(
        ?EntryEntity $entry = null,
        ?CommentEntity $comment = null,
        int $post_type = ContentTypes::Entry,
    ): string {
        static $user_ignored_objects = null;

        if ($user_ignored_objects === null) {
            $user_ignored_objects = [];

            if ($this->context->current_user->uid > 0 && !empty($this->context->mybb->user['ignorelist'])) {
                $user_ignored_objects = array_filter(
                    array_flip(explode(',', $this->context->mybb->user['ignorelist']))
                );
            }
        }

        if ($post_type === ContentTypes::Entry && $entry->user_id) {
            $this->user_id = $entry->user_id;
        } elseif ($post_type === ContentTypes::Comment && $comment->user_id) {
            $this->user_id = $comment->user_id;
        } else {
            $this->user_id = $this->context->current_user->uid;
        }

        if (!$this->context->users_manager->getUserByID($this->user_id)) {
            $this->context->users_manager->addUser((array)get_user($this->user_id));
        }

        $this->user_data = $this->context->users_manager->getUserByID($this->user_id);

        hooksRun('render_build_entry_comment_start', $this);

        $templatesContext = [
            'entry_data' => $entry,
            'comment_data' => $comment,
            'url' => [
                'entry' => '',
                'update' => '',
            ],
            'unapproved_class' => '',
            'is_deleted' => false,
            'is_ignored' => false,
            'ignored_message' => '',
            'style_visibility' => '',
            'entry_attachments' => '',
            'commentAttachments' => '',
            'class' => '',
            'show_report_button' => false,
            'show_warn_button' => false,
            'user_data' => $this->user_data,
            'user_signature' => '',
            'icons_cache' => [],
            'is_preview' => $this->is_preview,
        ];

        // todo, add showcase setting to enable/disable post icons
        if ($post_type === ContentTypes::Entry && $this->context->showcase->entries_allow_post_icons ||
            $post_type === ContentTypes::Comment && $this->context->showcase->comments_allow_post_icons) {
            $templatesContext['icons_cache'] = (array)$this->context->mybb->cache->read('posticons');
        }

        if ($this->context->showcase->entries_enable_subject) {
            $entry->subject = postParser()->parse_badwords($entry->subject);

            if (isset($this->url_params['keywords'])) {
                $entry->subject = postParser()->highlight_message($entry->subject, $this->url_params['keywords']);
            }
        }

        if ($post_type === ContentTypes::Entry) {
            $entry
                ->setExtraData('is_deleted', false)
                ->setExtraData('moderator_check', false);
        }

        if ($post_type === ContentTypes::Entry) {
            $templatePrefix = 'pageViewEntry';
        } else {
            $templatePrefix = 'pageViewComment';
        }

        // todo, will be empty when preview a new entry/comment
        if (!empty($entry->entry_slug)) {
            $templatesContext['url']['entry'] = $this->context->routing->generateUrl(
                Routes::EntryView,
                [
                    'entry_slug' => $entry->entry_slug,
                    'entry_slug_custom' => $entry->entry_slug_custom,
                ]
            );

            $templatesContext['url']['entry_api'] = $this->context->routing->generateUrl(
                Routes::EntryViewApi,
                [
                    'entry_slug' => $entry->entry_slug,
                    'entry_slug_custom' => $entry->entry_slug_custom,
                ]
            );
        }

        if ($entry->entry_id) {
            $where_clauses = ["entry_id='{$entry->entry_id}'"];
        } else {
            $where_clauses = ["post_hash='{$entry->post_hash}'"];
        }

        foreach (
            $this->context->attachments_repository->queryAttachments(
                $where_clauses,// todo, status clause
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
            ) as $attachment_id => $attachment_data
        ) {
            $this->context->attachments_manager->addAttachment($attachment_data);
        }

        if ($entry === false) {
            $entry = $this->context->entries_manager->generateEntry($this->context->mybb->input);
        }

        foreach (
            $this->context->attachments_manager->getAttachments()->filter(
                function (AttachmentEntity $attachment) use ($entry, $comment): bool {
                    return $attachment->entry_id === $entry->entry_id && (
                            $comment === null || $attachment->comment_id === $comment->comment_id);
                }
            ) as $attachment_data
        ) {
            //$entry = $this->context->entries_manager->getEntryByID($attachment_data->entry_id);

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

        if ($post_type === ContentTypes::Entry) {
            if ($this->context->showcase->attachments_allow_in_entries &&
                $this->context->current_user->hasPermission(UserPermissions::AttachmentsCanView)) {
                $templatesContext['entry_attachments'] = $this->buildAttachments(
                    $entry,
                    $entry->getAttachments()->filter(
                        fn(AttachmentEntity $attachment) => empty($attachment->field_id)
                    ),
                    post_type: $post_type,
                );
            }
        } elseif (!$this->is_preview) {
            $templatesContext['url']['comment'] = $this->context->routing->generateUrl(
                Routes::CommentView,
                [
                    'comment_slug' => $comment->comment_slug
                ]
            );
        }

        // todo, this should probably account for additional groups, but I just took it from post bit logic for now
        if ($this->user_data->uid) {
            $user_permissions = user_permissions($this->user_data->uid);
        } else {
            $user_permissions = usergroup_permissions(1);
        }

        $templatesContext['user_permissions'] = &$user_permissions;

        $userName = htmlspecialchars_uni($this->user_data->username ?? $this->context->language->guest);

        $userNameFormatted = format_name(
            $userName,
            $this->user_data->usergroup ?? 0,
            $this->user_data->displaygroup ?? 0
        );

        $templatesContext['user_avatar'] = '';

        if ((
                $post_type === ContentTypes::Entry && $this->context->showcase->entries_display_avatars ||
                $post_type === ContentTypes::Comment && $this->context->showcase->comments_display_avatars
            ) &&
            (
                isset($this->user_data->avatar) &&
                isset($this->context->mybb->user['showavatars']) &&
                !empty($this->context->mybb->user['showavatars']) ||
                !$this->context->current_user->uid
            )) {
            $templatesContext['user_avatar'] = $userAvatar = format_avatar(
                $this->user_data->avatar,
                $this->user_data->avatardimensions,
            );
        }

        if (!empty($user_permissions['usertitle']) && empty($this->user_data->usertitle)) {
            $this->user_data->usertitle = $user_permissions['usertitle'] ?? '';
        } elseif (empty($user_permissions['usertitle']) &&
            ($userTitlesCache = $this->groupUserTitlesGet())) {
            foreach ($userTitlesCache as $postNumber => $titleinfo) {
                if ($this->user_data->postnum >= $postNumber) {
                    if (empty($this->user_data->usertitle)) {
                        $this->user_data->usertitle = $titleinfo['title'];
                    }

                    $user_permissions['stars'] = $titleinfo['stars'];

                    $user_permissions['starimage'] = $titleinfo['starimage'];

                    break;
                }
            }
        }

        $templatesContext['userOnlineStatus'] = 'offline';

        if (isset($userData['lastactive'])) {
            if ($userData['lastactive'] > TIME_NOW - $this->context->mybb->settings['wolcutoff'] &&
                (empty($userData['invisible']) || !empty($this->context->mybb->usergroup['canviewwolinvis'])) &&
                (int)$userData['lastvisit'] !== (int)$userData['lastactive']) {
                $templatesContext['userOnlineStatus'] = 'online';
            } elseif (!empty($userData['away']) && !empty($this->context->mybb->settings['allowaway'])) {
                $templatesContext['userOnlineStatus'] = 'away';
            }
        }

        $entries_can_view = $this->context->current_user->hasPermission(UserPermissions::EntriesCanView);

        $comments_can_view = $this->context->current_user->hasPermission(UserPermissions::CommentsCanView);

        $userCanUpdateEntries = $this->user_id === $this->context->current_user->uid && $this->context->current_user->hasPermission(
                UserPermissions::EntriesCanUpdate
            );

        $userCanSoftDeleteEntries = $this->user_id === $this->context->current_user->uid && $this->context->current_user->hasPermission(
                UserPermissions::EntriesCanDelete
            );

        $userCanUpdateComments = $this->user_id === $this->context->current_user->uid && $this->context->current_user->hasPermission(
                UserPermissions::CommentsCanUpdate
            );

        $userCanSoftDeleteComments = $this->user_id === $this->context->current_user->uid && $this->context->current_user->hasPermission(
                UserPermissions::CommentsCanDelete
            );

        if (!$this->is_preview && $post_type === ContentTypes::Entry && $entry->entry_id && ($entries_can_view || $userCanUpdateEntries)) {
            $templatesContext['url']['update'] = $this->context->routing->generateUrl(
                Routes::EntryUpdate,
                [
                    'entry_slug' => $entry->entry_slug,
                ]
            );

            $templatesContext['url']['update_api'] = $this->context->routing->generateUrl(
                Routes::EntryUpdateApi,
                [
                    'entry_slug' => $entry->entry_slug,
                    'my_post_key' => $this->context->mybb->post_code,
                ]
            );

            $templatesContext['url']['soft_delete'] = $this->context->routing->generateUrl(
                Routes::EntryUpdate,
                [
                    'entry_slug' => $entry->entry_slug,
                    'delete' => 1,
                ]
            );

            $templatesContext['url']['delete'] = $this->context->routing->generateUrl(
                Routes::EntryUpdate,
                [
                    'entry_slug' => $entry->entry_slug,
                    'delete' => 2,
                ]
            );
        } elseif (!$this->is_preview && $post_type === ContentTypes::Comment && $comment->comment_id && ($comments_can_view || $userCanUpdateComments)) {
            $templatesContext['url']['update'] = $this->context->routing->generateUrl(
                Routes::CommentUpdate,
                [
                    'entry_slug' => $entry->entry_slug,
                    'comment_slug' => $comment->comment_slug,
                ]
            );

            $templatesContext['url']['soft_delete'] = $this->context->routing->generateUrl(
                Routes::CommentUpdate,
                [
                    'entry_slug' => $entry->entry_slug,
                    'comment_slug' => $comment->comment_slug,
                    'delete' => 1,
                ]
            );

            $templatesContext['url']['delete'] = $this->context->routing->generateUrl(
                Routes::CommentUpdate,
                [
                    'entry_slug' => $entry->entry_slug,
                    'comment_slug' => $comment->comment_slug,
                    'delete' => 2,
                ]
            );
        }

        if (!empty($this->context->mybb->settings['enablewarningsystem']) &&
            !empty($user_permissions['canreceivewarnings']) &&
            (!empty($this->context->mybb->usergroup['canwarnusers']) || ($this->context->current_user->uid === $this->user_id && !empty($this->context->mybb->settings['canviewownwarning'])))) {
            if ($this->context->mybb->settings['maxwarningpoints'] < 1) {
                $this->context->mybb->settings['maxwarningpoints'] = 10;
            }

            $warningLevel = round(
                $this->user_data->warningpoints / $this->context->mybb->settings['maxwarningpoints'] * 100
            );

            if ($warningLevel > 100) {
                $warningLevel = 100;
            }

            $warningLevel = get_colored_warning_level($warningLevel);

            // If we can warn them, it's not the same person, and we're in a PM or a post.
            if (!empty($this->context->mybb->usergroup['canwarnusers']) && $this->user_id !== $this->context->current_user->uid) {
                $templatesContext['show_warn_button'] = true;

                $warningUrl = "warnings.php?uid={$this->user_id}";
            } else {
                $warningUrl = 'usercp.php';
            }

            if ($post_type === ContentTypes::Entry && $this->context->showcase->entries_display_group_image ||
                $post_type === ContentTypes::Comment && $this->context->showcase->comments_display_group_image) {
            }
        }

        if (($post_type === ContentTypes::Entry && $this->context->showcase->entries_display_signatures ||
                $post_type === ContentTypes::Comment && $this->context->showcase->comments_display_signatures) &&
            $this->user_data->username &&
            $this->user_data->signature &&
            (!$this->context->current_user->uid || !empty($this->context->mybb->user['showsigs'])) &&
            (!$this->user_data->suspendsignature || $this->user_data->suspendsignature && $this->user_data->suspendsigtime && $this->user_data->suspendsigtime < TIME_NOW) &&
            !empty($user_permissions['canusesig']) &&
            (empty($user_permissions['canusesigxposts']) || $user_permissions['canusesigxposts'] > 0 && $this->user_data->postnum > $user_permissions['canusesigxposts']) &&
            !is_member($this->context->mybb->settings['hidesignatures'])) {
            $parser_options = [
                'allow_html' => !empty($this->context->mybb->settings['sightml']),
                'allow_mycode' => !empty($this->context->mybb->settings['sigmycode']),
                'allow_smilies' => !empty($this->context->mybb->settings['sigsmilies']),
                'allow_imgcode' => !empty($this->context->mybb->settings['sigimgcode']),
                'me_username' => $this->user_data->username
            ];

            if ($user_permissions['signofollow']) {
                $parser_options['nofollow_on'] = true;
            }

            if ($this->context->current_user->uid && empty($this->context->mybb->user['showimages']) || empty($this->context->mybb->settings['guestimages']) && !$this->context->current_user->uid) {
                $parser_options['allow_imgcode'] = false;
            }

            $templatesContext['user_signature'] = postParser()->parse_message(
                $this->user_data->signature,
                $parser_options
            );
        }

        if ($entries_can_view && (
                $post_type === ContentTypes::Entry && !empty($entry->edit_user_id) ||
                $post_type === ContentTypes::Comment && !empty($comment->edit_user_id)
            )) {
            $editUserData = get_user(
                $post_type === ContentTypes::Entry ? $entry->edit_user_id : $comment->edit_user_id
            );
        }

        if ($this->user_data->website &&
            !is_member($this->context->mybb->settings['hidewebsite']) &&
            !empty($user_permissions['canchangewebsite'])) {
            $userWebsite = htmlspecialchars_uni($this->user_data->website);
        }

        require_once MYBB_ROOT . 'inc/functions_user.php';

        if (!$this->is_preview && $this->user_id &&
            purgespammer_show($this->user_data->postnum ?? 0, $this->user_data->usergroup, $this->user_id)) {
            $templatesContext['show_purge_spammer_button'] = true;
        }

        $query = $this->context->database->simple_select(
            'reportedcontent',
            'uid'
        );

        $reportUserIDs = [];

        while ($reportData = $this->context->database->fetch_array($query)) {
            $reportUserIDs[] = (int)$reportData['uid'];
        }

        if (!in_array(
                $this->context->current_user->uid,
                $reportUserIDs
            ) && !empty($user_permissions['canbereported'])) {
            $templatesContext['show_report_button'] = true;
        }

        if ($post_type === ContentTypes::Entry) {
            $postStatus = (int)$entry->status;
        } else {
            $postStatus = $comment->status;
        }

        if (!$this->is_preview && $post_type === ContentTypes::Entry && $entry->entry_id && ($entries_can_view || $userCanSoftDeleteEntries)) {
            if ($entries_can_view && $postStatus === ContentStatuses::AwaitingApproval) {
                $approveUrl = $this->context->routing->generateUrl(
                    Routes::EntryUpdate,
                    [
                        'entry_slug' => $entry->entry_slug,
                        'action' => 'approve',
                    ]
                );
            } elseif ($entries_can_view && $postStatus === ContentStatuses::VisibleApproved) {
                $unapproveUrl = $this->context->routing->generateUrl(
                    Routes::EntryUpdate,
                    [
                        'entry_slug' => $entry->entry_slug,
                        'action' => 'unapprove',
                    ]
                );
            }

            if ($entries_can_view && $postStatus === ContentStatuses::SoftDeleted) {
                $restoreUrl = $this->context->routing->generateUrl(
                    Routes::EntryUpdate,
                    [
                        'entry_slug' => $entry->entry_slug,
                        'action' => 'restore',
                    ]
                );
            } elseif ($postStatus === ContentStatuses::VisibleApproved && $userCanSoftDeleteEntries) {
                $softDeleteUrl = $this->context->routing->generateUrl(
                    Routes::EntryUpdate,
                    [
                        'entry_slug' => $entry->entry_slug,
                        'action' => 'soft_delete',
                    ]
                );
            }

            //only mods, original author (if allowed) or owner (if allowed) can delete comments
            if ($entries_can_view) {
                $deleteUrl = $this->context->routing->generateUrl(
                    Routes::EntryUpdate,
                    [
                        'entry_slug' => $entry->entry_slug,
                        'action' => 'delete',
                    ]
                );
            }
        } elseif (!$this->is_preview && $post_type === ContentTypes::Comment && $comment->comment_id && ($comments_can_view || $userCanSoftDeleteComments)) {
            if ($comments_can_view && $postStatus === ContentStatuses::AwaitingApproval) {
                $templatesContext['url_comment_approve'] = $this->context->showcase->showcase_url . $this->context->routing->generateUrl(
                        Routes::CommentView,
                        array_merge($this->url_params, [
                            'entry_slug' => $entry->entry_slug,
                            'entry_slug_custom' => $entry->entry_slug_custom,
                            'comment_slug' => $comment->comment_slug,
                            'action' => 'approve',
                        ])
                    );
            } elseif ($comments_can_view && $postStatus === ContentStatuses::VisibleApproved) {
                $templatesContext['url_comment_unapprove'] = $this->context->routing->generateUrl(
                    Routes::CommentUpdate,
                    [
                        'entry_slug' => $entry->entry_slug,
                        'comment_slug' => $comment->comment_slug,
                        'action' => 'unapprove',
                    ]
                );
            }

            if ($comments_can_view && $postStatus === ContentStatuses::SoftDeleted) {
                $templatesContext['url_comment_restore'] = $this->context->routing->generateUrl(
                    Routes::CommentUpdate,
                    [
                        'entry_slug' => $entry->entry_slug,
                        'comment_slug' => $comment->comment_slug,
                        'action' => 'restore',
                    ]
                );
            } elseif ($postStatus === ContentStatuses::VisibleApproved) {
                $templatesContext['url_comment_soft_delete'] = $this->context->routing->generateUrl(
                    Routes::CommentUpdate,
                    [
                        'entry_slug' => $entry->entry_slug,
                        'comment_slug' => $comment->comment_slug,
                        'action' => 'soft_delete',
                    ]
                );
            }

            //only mods, original author (if allowed) or owner (if allowed) can delete comments
            if ($comments_can_view) {
                $templatesContext['url_comment_delete'] = $this->context->routing->generateUrl(
                    Routes::CommentUpdate,
                    [
                        'entry_slug' => $entry->entry_slug,
                        'comment_slug' => $comment->comment_slug,
                        'action' => 'delete',
                    ]
                );
            }
        }

        if ($this->context->mybb->settings['enablereputation'] && (
                $post_type === ContentTypes::Entry && $this->context->showcase->entries_display_details ||
                $post_type === ContentTypes::Comment && $this->context->showcase->comments_display_details
            )) {
            $userRegistrationDate = my_date(
                $this->context->mybb->settings['regdateformat'],
                $this->user_data->regdate ?? 0
            );

            if (!empty($groupPermissions['usereputationsystem']) && !empty($this->context->mybb->settings->entries_display_reputations)) {
                $userReputation = get_reputation($this->user_data->reputation, $this->user_id);
            }
        }

        if (!$this->is_preview) {
            switch ($postStatus) {
                case ContentStatuses::AwaitingApproval:
                    $templatesContext['unapproved_class'] = 'unapproved_post';
                    break;
                case ContentStatuses::SoftDeleted:
                    $templatesContext['unapproved_class'] = 'unapproved_post deleted_post';
                    break;
            }
        }

        if (!$this->is_preview && (
                ContentTypes::Entry && $postStatus === ContentStatuses::SoftDeleted ||
                ContentTypes::Comment && $postStatus === ContentStatuses::SoftDeleted
            )) {
            if (ContentTypes::Entry) {
                $deletedMessage = $this->context->language->sprintf(
                    $this->context->language->myshowcase_entry_deleted_message,
                    $userName
                );
            } else {
                $deletedMessage = $this->context->language->sprintf(
                    $this->context->language->myshowcase_comment_deleted_message,
                    $userName
                );
            }

            $templatesContext['is_deleted'] = true;

            $templatesContext['style_visibility'] = 'display: none;';
        }

        // Is the user (not moderator) logged in and have unapproved posts?
        if (!$this->is_preview && ($this->context->current_user->uid &&
                (
                    $post_type === ContentTypes::Entry && $postStatus === ContentStatuses::AwaitingApproval ||
                    $post_type === ContentTypes::Comment && $postStatus === ContentStatuses::AwaitingApproval
                ) &&
                $this->user_id === $this->context->current_user->uid &&
                !(
                    $post_type === ContentTypes::Entry && $entries_can_view ||
                    $post_type === ContentTypes::Comment && $comments_can_view
                ))) {
            $templatesContext['is_ignored'] = true;

            $templatesContext['ignored_message'] = $this->context->language->myshowcase_view_entry_under_moderation;

            $templatesContext['style_visibility'] = 'display: none;';
        }

        // Is this author on the ignore list of the current user? Hide this post
        if (!$this->is_preview && is_array($user_ignored_objects) &&
            isset($user_ignored_objects[$this->user_id]) &&
            !$templatesContext['is_deleted']) {
            $templatesContext['is_ignored'] = true;

            $templatesContext['ignored_message'] = $this->context->language->sprintf(
                $this->context->language->myshowcase_view_entry_currently_ignoring_user,
                $userName,
                $this->context->mybb->settings['bburl']
            );

            $templatesContext['style_visibility'] = 'display: none;';
        }

        if ($entry->status === ContentStatuses::AwaitingApproval) {
            $entry->setExtraData('background_class', 'trow_shaded');
        } elseif ($entry->status === ContentStatuses::SoftDeleted &&
            $this->context->current_user->hasPermission(UserPermissions::EntriesCanView)) {
            $entry->setExtraData('background_class', 'trow_shaded trow_deleted');
        } else {
            $entry->setExtraData('background_class', '');
        }

        $entry->setExtraData('unread_comments', false); // todo, implement unread comments

        // todo, the entry query should fetch this from the reports table
        if ($entry->getExtraData('reporters_list') === false) {
            $entry->setExtraData(
                'reporters_list',
                $this->context->entries_repository->getContentReports($entry->entry_id)
            );
        }

        if ($post_type === ContentTypes::Comment) {
            if ($comment->getExtraData('reporters_list') === false) {
                $comment->setExtraData(
                    'reporters_list',
                    $this->context->entries_repository->getContentReports($comment->entry_id)
                );
            }
        }

        $templatesContext['show_purge_spammer_button'] = purgespammer_show(
            $this->user_data->postnum,
            $this->user_data->usergroup,
            $this->user_data->uid
        );

        $templatesContext['show_edited_message'] = false;

        // Figure out if we need to show an "edited by" message
        if (!empty($entry->edit_user_id) && $entry->update_dateline && (
                !empty($this->context->mybb->settings['showeditedby']) ||
                (!empty($this->context->mybb->settings['showeditedbyadmin']) && $this->context->current_user->hasPermission(
                        UserPermissions::EntriesCanView
                    ))
            )) {
            $templatesContext['show_edited_message'] = true;

            $entry->setExtraData('edit_user_data', get_user($entry->edit_user_id));

            if ($entry->update_note) {
                $entry->update_note = postParser()->parse_badwords($entry->update_note ?? '');
            }
        }

        $entry
            ->setExtraData('show_ip', false)
            ->setExtraData('hide_ip', false)
            ->setExtraData('can_edit', false);

        if ($this->context->mybb->settings['logip'] !== 'no' && !empty($entry->ipaddress)) {
            $entry->setExtraData(
                'ip_address',
                my_inet_ntop($this->context->database->unescape_binary($entry->ipaddress))
            );

            if ($this->context->mybb->settings['logip'] === 'show') {
                $entry->setExtraData('show_ip', true);
            } elseif ($this->context->mybb->settings['logip'] === 'hide' &&
                ($this->context->current_user->hasPermission(
                        UserPermissions::EntriesCanView
                    ) || $this->context->mybb->usergroup['issupermod'])) {
                $entry->setExtraData('show_ip', true);
            }
        }

        if ($this->context->showcase->entries_enable_message && $post_type === ContentTypes::Entry) {
            $parser_options = [
                'allow_html' => !empty($this->context->showcase->parser_allow_html_in_entries) ||
                    $this->user_data->hasPermission(UserPermissions::ParserAllowHtmlInEntries),
                'allow_mycode' => !empty($this->context->showcase->parser_allow_mycode_in_entries),
                'allow_smilies' => !empty($this->context->showcase->parser_allow_smiles_in_entries),
                'allow_imgcode' => !empty($this->context->showcase->parser_allow_image_code_in_entries),
                'allow_videocode' => !empty($this->context->showcase->parser_allow_video_code_in_entries),
                'filter_badwords' => true,
                'shorten_urls' => false,
                'highlight' => false,
            ];

            if (isset($this->url_params['highlight'])) {
                $parser_options['highlight'] = $this->url_params['highlight'];
            }

            $entry->message = postParser()->parse_message(
                $entry->message,
                ['me_username' => $this->user_data->username ?? $entry->username] + $parser_options
            );
        } elseif ($post_type === ContentTypes::Comment) {
            static $parser_options = null;

            if ($parser_options === null) {
                $parser_options = [
                    'allow_html' => !empty($this->context->showcase->parser_allow_html_in_comments) ||
                        $this->user_data->hasPermission(UserPermissions::ParserAllowHtmlInComments),
                    'allow_mycode' => !empty($this->context->showcase->parser_allow_mycode_in_comments),
                    'allow_smilies' => !empty($this->context->showcase->parser_allow_smiles_in_comments),
                    'allow_imgcode' => !empty($this->context->showcase->parser_allow_image_code_in_comments),
                    'allow_videocode' => !empty($this->context->showcase->parser_affects_message_length_in_comments),
                    'filter_badwords' => true,
                    'shorten_urls' => false,
                    'highlight' => false,
                ];

                if (isset($this->url_params['highlight'])) {
                    $parser_options['highlight'] = $this->url_params['highlight'];
                }
            }

            $comment->message = postParser()->parse_message(
                $comment->message,
                ['me_username' => $this->user_data->username ?? $comment->username] + $parser_options
            );
        }

        if ($this->context->current_user->hasPermission(UserPermissions::EntriesCanView) ||
            ($this->context->current_user->uid === $entry->user_id && !$thread['closed'] && (
                    empty($this->context->mybb->usergroup['edittimelimit']) ||
                    $post['dateline'] > (TIME_NOW - ($this->context->mybb->usergroup['edittimelimit'] * 60))
                )) &&
            $this->context->current_user->uid) {
            $entry->setExtraData('can_edit', true);
        }

        hooksRun('render_build_entry_comment_end', $this);

        try {
            $page = $this->templateGet(
                $templatePrefix,
                $templatesContext,
            );
        } catch (Exception $e) {
            // $page is left uninitialized; caller receives null
        }

        return $page;
    }

    /**
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError
     */
    public function buildEntry(
        EntryEntity $entry,
        bool $is_preview = false,
    ): string {
        $this->is_preview = $is_preview;

        return $this->buildPost($entry);
    }

    /**
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError
     */
    public function buildComment(
        EntryEntity $entry,
        CommentEntity $comment_data,
        bool $is_preview = false,
    ): string {
        $this->is_preview = $is_preview;

        return $this->buildPost($entry, $comment_data, ContentTypes::Comment);
    }

    /**
     * @throws RuntimeError
     * @throws LoaderError
     * @throws SyntaxError
     */
    public function buildAttachments(
        EntryEntity $entry,
        Collection $attachment_objects,
        array &$entry_fields = [],
        int $post_type = ContentTypes::Entry,
    ): string {
        if ($attachment_objects->count() < 1) {
            return '';
        }

        $unapprovedCount = 0;

        $templatesContext = [
            'attachedThumbnails' => [],
            'attachedImages' => [],
            'attachedFiles' => [],
        ];

        $template_prefix = 'Entry';

        if ($post_type === ContentTypes::Comment) {
            $template_prefix = 'Comment';
        } elseif ($post_type === ContentTypes::CustomField) {
            $template_prefix .= 'Field';
        }

        /**
         * @var AttachmentEntity $attachment_data
         */
        foreach ($attachment_objects as $attachmentID => $attachment_data) {
            $attachment_data
                ->setExtraData('file_size', get_friendly_size($attachment_data->file_size))
                ->setExtraData(
                    'is_image',
                    str_starts_with($attachment_data->mime_type, 'image/')
                )
                ->setExtraData('icon', get_attachment_icon($attachment_data->attachment_name));

            $attachment_data->has_thumbnail = !empty($attachment_data->thumbnail_name) && $this->context->showcase->attachments_display_type === AttachmentsDisplayType::Thumbnails;

            // todo, maybe all attachments should be accessible from the same route, with entry_slug or post_hash optional
            if ($post_type === ContentTypes::Entry ||
                $post_type === ContentTypes::CustomField) {
                if ($entry->entry_id) {
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
                } else {
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
                }

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
            }

            if ($attachment_data->status or true) { // todo, unapproved attachments should display regardless
                $attachmentExtension = get_extension($attachment_data->attachment_name);

                $isImageAttachment = in_array($attachmentExtension, ['jpeg', 'gif', 'bmp', 'png', 'jpg']);

                // Support for [attachment=attachment_hash] code
                $attachmentInField = false;

                foreach ($entry_fields as $field_header => &$field_definition) {
                    if (str_contains(
                            $field_definition['value'],
                            '[attachment=' . $attachment_data->attachment_hash . ']'
                        ) || str_contains(
                            $field_definition['value_display'],
                            '[attachment=' . $attachment_data->attachment_hash . ']'
                        )) {
                        $attachmentInField = true;

                        $attachmentBit = $this->templateGet(
                            'pageView' . $template_prefix . 'Attachment',
                            [
                                'attachment_id' => $attachmentID,
                                'attachment_data' => $attachment_data,
                            ]
                        );

                        if (str_contains(
                            $field_definition['value'],
                            '[attachment=' . $attachment_data->attachment_hash . ']'
                        )) {
                            $field_definition['value'] = preg_replace(
                                '#\[attachment=' . $attachment_data->attachment_hash . ']#si',
                                $attachmentBit,
                                $field_definition['value']
                            );
                        }

                        if (str_contains(
                            $field_definition['value_display'],
                            '[attachment=' . $attachment_data->attachment_hash . ']'
                        )) {
                            $field_definition['value'] = preg_replace(
                                '#\[attachment=' . $attachment_data->attachment_hash . ']#si',
                                $attachmentBit,
                                $field_definition['value']
                            );
                        }
                    }
                }

                if ($attachmentInField) {
                    continue;
                }

                if ($attachment_data->has_thumbnail && $this->context->showcase->attachments_display_type === AttachmentsDisplayType::Thumbnails) {
                    $templatesContext['attachedThumbnails'][$attachmentID] = $attachment_data;
                } elseif (!$attachment_data->has_thumbnail && $this->context->showcase->attachments_display_type === AttachmentsDisplayType::FullImages) {
                    $templatesContext['attachedImages'][$attachmentID] = $attachment_data;
                } else {
                    $templatesContext['attachedFiles'][$attachmentID] = $attachment_data;
                }
            } else {
                ++$unapprovedCount;
            }
        }

        $templatesContext['show_unapproved'] = $unapprovedCount > 0 && $this->context->current_user->hasPermission(
                UserPermissions::CanManageAttachments
            );

        if ($templatesContext['show_unapproved']) {
            if ($unapprovedCount === 1) {
                $templatesContext['unapproved_message'] = $this->context->language->myshowcase_view_attachments_unapproved_single;
            } else {
                $templatesContext['unapproved_message'] = $this->context->language->sprintf(
                    $this->context->language->myshowcase_view_entry_button_deleted_show,
                    $unapprovedCount
                );
            }
        }

        if ($templatesContext) {
            return $this->templateGet(
                'pageView' . $template_prefix . 'Attachments',
                $templatesContext
            );
        }

        return '';
    }

    public function buildAttachmentsUpload(
        bool $isEditPage,
        int $post_type = ContentTypes::Entry
    ): string {
        return '';

        $myDropzoneSettingMaximumFileSize = 0;

        $myDropzoneSettingAllowedMimeFileTypes = [];

        $attachment_types = (new CacheManager($this->context->database, $this->context->mybb->cache))->get(
            CacheDataTypes::AttachmentTypes
        )[$this->context->showcase->showcase_id] ?? [];

        array_map(
            function ($attachmentType) use (
                &$myDropzoneSettingMaximumFileSize,
                &$myDropzoneSettingAllowedMimeFileTypes
            ): void {
                if (is_member($attachmentType['allowed_groups'])) {
                    $myDropzoneSettingMaximumFileSize = max(
                        $attachmentType['maximum_size'],
                        $myDropzoneSettingMaximumFileSize
                    );

                    $myDropzoneSettingAllowedMimeFileTypes[] = $attachmentType['mime_type'];

                    $myDropzoneSettingAllowedMimeFileTypes[] = $attachmentType['file_extension'];
                }
            },
            $attachment_types
        );

        $myDropzoneSettingAllowedMimeFileTypes = implode(',', $myDropzoneSettingAllowedMimeFileTypes);

        if ($isEditPage) {
            $createUpdateUrl = $this->context->routing->generateUrl(
                Routes::EntryUpdate,
                [
                    'entry_slug' => $entry->entry_slug,
                ],
                $this->url_params
            );
        } else {
            $createUpdateUrl = $this->context->routing->generateUrl(
                Routes::EntryCreate,
                $this->url_params
            );
        }

        $watermarkSelectedElement = '';

        if ($this->context->mybb->get_input('attachment_watermark_file')) {
            $watermarkSelectedElement = 'checked="checked"';
        }

        if ($this->context->current_user->getPermission(UserPermissions::AttachmentsLimitUploadQuotaAmount) ===
            PermissionEntity::NUMERIC_TYPE_UNLIMITED) {
            $usageQuoteNote = $this->context->language->sprintf(
                $this->context->language->myshowcase_attachments_usage_quote,
                $this->context->language->myshowcase_attachments_usage_quote_unlimited
            );
        } else {
            $usageQuoteNote = $this->context->language->sprintf(
                $this->context->language->myshowcase_attachments_usage_quote,
                get_friendly_size(
                    $this->context->current_user->getPermission(
                        UserPermissions::AttachmentsLimitUploadQuotaAmount
                    ) * 1024
                )
            );
        }

        $totalUserUsage = $this->context->attachments_repository->queryAttachmentSum(
            ["user_id='{$this->context->current_user->uid}'"]
        );

        $usageDetails = $viewMyAttachmentsLink = '';

        $template_prefix = 'Entry';

        if ($post_type === ContentTypes::Comment) {
            $template_prefix = 'Comment';
        }

        if ($totalUserUsage > 0) {
            $usageDetails = $this->context->language->sprintf(
                $this->context->language->myshowcase_attachments_usage_details,
                get_friendly_size($totalUserUsage)
            );

            $viewMyAttachmentsLink = $this->templateGet(
                'page' . $template_prefix . 'CreateUpdateAttachmentsBoxViewLink'
            );
        }

        return $this->templateGet('page' . $template_prefix . 'CreateUpdateAttachmentsBox', [
            'createUpdateUrl' => $createUpdateUrl,
            'myDropzoneSettingMaximumFileSize' => $myDropzoneSettingMaximumFileSize,
            'myDropzoneSettingAllowedMimeFileTypes' => $myDropzoneSettingAllowedMimeFileTypes,
            'usageQuoteNote' => $usageQuoteNote,
            'usageDetails' => $usageDetails,
            'totalUserUsage' => $totalUserUsage,
            'watermarkSelectedElement' => $watermarkSelectedElement,
        ]);
    }

    public function editorBuild(string &$code_buttons, string &$smiles_inserter, string $identifier = 'comment'): void
    {
        if ($this->context->showcase->parser_allow_mycode_in_entries &&
            (!$this->context->current_user->uid || !empty($this->context->mybb->user['showcodebuttons']))) {
            $code_buttons = build_mycode_inserter(
                $identifier,
                $this->context->showcase->parser_allow_smiles_in_entries
            );

            if ($this->context->showcase->parser_allow_smiles_in_entries) {
                $smiles_inserter = build_clickable_smilies();
            }
        }
    }
}