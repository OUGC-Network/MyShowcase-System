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

use DB_MySQLi;
use DB_PgSQL;
use DB_SQLite;
use MyBB;
use MyLanguage;
use MyShowcase\Collection\Collection;
use MyShowcase\Entity\Attachment;
use MyShowcase\Entity\Comment;
use MyShowcase\Entity\Entry;
use MyShowcase\Entity\Permission;
use MyShowcase\Entity\User;
use MyShowcase\Enum\SortDirections;
use MyShowcase\Enum\UserPermissions;
use MyShowcase\Repository\AttachmentsRepository;
use MyShowcase\Repository\EntriesRepository;
use MyShowcase\Enum\Routes;
use MyShowcase\Routing\Routing;
use MyShowcase\Util\SelectableLists;
use MysqlPdoDbDriver;
use PostgresPdoDbDriver;
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

class Render
{
    public const POST_TYPE_ENTRY = 1;
    public const POST_TYPE_ENTRY_FIELD = 2;

    public const POST_TYPE_COMMENT = 3;
    public const ATTACHMENT_TYPE_ENTRY = 1;
    public const ATTACHMENT_TYPE_FIELD = 2;
    public const ATTACHMENT_TYPE_COMMENT = 3;

    private int $post_type = self::POST_TYPE_ENTRY;

    public int $attachments_type = self::ATTACHMENT_TYPE_ENTRY;

    private bool $is_preview = false;

    private int $user_id = 0;

    private array $user_data = [];
    public array $error_messages = [];

    public array $url_params = [];
    public int $current_page = 1;

    public array $fieldset_display_fields = [];

    public array $context = [];

    public function __construct(
        private readonly DB_SQLite|DB_PgSQL|PostgresPdoDbDriver|MysqlPdoDbDriver|DB_MySQLi $database,
        private readonly MyBB $mybb,
        private readonly MyLanguage $language,
        private readonly User $current_user,
        private readonly Routing $routing,
        private readonly EntriesRepository $entries_repository,
        private readonly AttachmentsRepository $attachments_repository,
        private readonly EntriesManager $entries_manager,
        private readonly AttachmentsManager $attachments_manager,
        private readonly CommentsManager $comments_manager,
        private readonly array $config,
        private readonly array $attachment_types,
        private readonly array $fieldset_fields,
        private readonly array $fieldset_searchable_fields,
        private readonly array $parser_options,
    ) {
        $this->current_page = 1;

        if ($this->mybb->get_input('page', MyBB::INPUT_INT) > 0) {
            $this->current_page = $this->mybb->get_input('page', MyBB::INPUT_INT);
        }

        if ($this->current_page) {
            $this->url_params['page'] = $this->current_page;
        }

        if ($this->mybb->get_input('user_id', MyBB::INPUT_INT) > 0) {
            $this->url_params['user_id'] = $this->mybb->get_input('user_id', MyBB::INPUT_INT);
        }

        if ($this->mybb->get_input('unapproved', MyBB::INPUT_INT)) {
            $this->url_params['unapproved'] = $this->mybb->get_input('unapproved', MyBB::INPUT_INT);
        }

        $this->fieldset_display_fields = (new SelectableLists($this->language))->sortFieldsList();

        foreach ($this->fieldset_fields as $field_id => $field_definition) {
            if ($field_definition['display_in_main_page']) {
                $this->fieldset_display_fields[$field_definition['field_key']] = '';
            }
        }

        if ($this->mybb->get_input('sort_by') !== SortDirections::Descending) {
            $this->url_params['order_by'] = SortDirections::Ascending;
        } else {
            $this->url_params['order_by'] = SortDirections::Descending;
        }

        if (!array_key_exists($this->url_params['sort_by'], $this->fieldset_display_fields)) {
            $this->url_params['sort_by'] = 'dateline';
        }

        if ($this->mybb->get_input('exact_match', MyBB::INPUT_INT) === 1) {
            $this->url_params['exact_match'] = 1;
        }

        $this->url_params['keywords'] = $this->mybb->get_input('keywords');

        $this->url_params['sort_by'] = $this->mybb->get_input('search_field');

        if (!in_array($this->url_params['sort_by'], array_keys($this->fieldset_searchable_fields))) {
            $this->url_params['sort_by'] = '';
        }

        if ($this->mybb->get_input('threaded', MyBB::INPUT_INT) === 1) {
            $this->url_params['threaded'] = 1;
        }
    }

    /**
     * @throws SyntaxError
     * @throws RuntimeError
     * @throws LoaderError
     */
    public function templateGet(string $name = '', array $context = []): string
    {
        $this->context = array_merge_recursive([
            'showcase' => [
                'fieldset_fields' => $this->fieldset_fields,
                'fieldset_display_fields' => $this->fieldset_display_fields,
            ],
            'meta' => [
                'canonical' => '',
                'page' => [],
            ],
            'url' => [
                'showcase' => $this->config['showcase_url'],
                'main' => $this->routing->generateUrl(Routes::Main),
            ],
        ],
            array_merge([
                'config' => $this->config,
                'current_page' => $this->current_page,
                'current_user' => $this->current_user,
                'url_params' => $this->url_params,
                'permissions' => $this->current_user->getPermissions(),
                'error_messages' => empty($this->error_messages) ? '' : inline_error(
                    $this->error_messages
                ),
                'version' => DEBUG ? TIME_NOW : VERSION_CODE,
                'pagination' => '',
            ], $context));

        hooksRun('render_template_get', $this);


        if ($this->config['custom_template_prefix'] !== '' &&
            $this->templateExists($this->config['custom_template_prefix'] . '/' . $name)) {
            return template(
                '@ext.myshowcase/' . $this->config['custom_template_prefix'] . '/' . $name . '.twig',
                $this->context
            );
        } else {
            return template(
                '@ext.myshowcase/' . $name . '.twig',
                $this->context
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

    public function urlParamsAppend(array $params): void
    {
        $this->url_params = array_merge($this->url_params, $params);
    }

    public function groupUserTitlesGet(): array
    {
        static $titles_cache = null;

        if ($titles_cache === null) {
            $titles_cache = [];

            foreach ((array)$this->mybb->cache->read('usertitles') as $title) {
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
        ?Entry $entry_data = null,
        ?Comment $comment_data = null,
    ): string {
        $this->attachments_type = $this->post_type;

        static $user_ignored_objects = null;

        if ($user_ignored_objects === null) {
            $user_ignored_objects = [];

            if ($this->current_user->user_id > 0 && !empty($this->mybb->user['ignorelist'])) {
                $user_ignored_objects = array_filter(array_flip(explode(',', $this->mybb->user['ignorelist'])));
            }
        }

        if ($this->post_type === self::POST_TYPE_ENTRY && $entry_data->user_id) {
            $this->user_id = $entry_data->user_id;
        } elseif ($this->post_type === self::POST_TYPE_COMMENT && $comment_data->user_id) {
            $this->user_id = $comment_data->user_id;
        } else {
            $this->user_id = $this->current_user->user_id;
        }

        $this->user_data = get_user($this->user_id);

        hooksRun('render_build_entry_comment_start', $this);

        $templatesContext = [
            'entry_data' => $entry_data,
            'comment_data' => $comment_data,
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
        ];

        // todo, add showcase setting to enable/disable post icons
        if (true) {
            $templatesContext['icons_cache'] = (array)$this->mybb->cache->read('posticons');
        }

        if ($this->post_type === self::POST_TYPE_ENTRY) {
            $entry_data->subject = postParser()->highlight_message(
                postParser()->parse_badwords($entry_data->subject),
                $this->url_params['keywords']
            );

            $entry_data
                ->setExtraData('is_deleted', false)
                ->setExtraData('moderator_check', false);
        }

        if ($this->post_type === self::POST_TYPE_ENTRY) {
            $templatePrefix = 'pageViewEntry';
        } else {
            $templatePrefix = 'pageViewComment';
        }

        // todo, will be empty when preview a new entry/comment
        if (!empty($entry_data->entry_slug)) {
            $templatesContext['url']['entry'] = $this->routing->generateUrl(
                Routes::EntryView,
                [
                    'entry_slug' => $entry_data->entry_slug,
                    'entry_slug_custom' => $entry_data->entry_slug_custom
                ]
            );
        }

        if ($entry_data->entry_id) {
            $where_clauses = ["entry_id='{$entry_data->entry_id}'"];
        } else {
            $where_clauses = ["post_hash='{$entry_data->post_hash}'"];
        }

        foreach (
            $this->attachments_repository->queryAttachments(
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
                    'edit_stamp',
                ],
            ) as $attachment_id => $attachment_data
        ) {
            $this->attachments_manager->setAttachment($attachment_data);
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

        if ($this->post_type === self::POST_TYPE_COMMENT) {
            $templatesContext['formatted_message'] = $this->entries_manager->parseMessage(
                $comment_data->message,
                $this->parser_options
            );

            if (!$this->is_preview) {
                $templatesContext['url']['comment'] = $this->routing->generateUrl(
                    Routes::CommentView,
                    [
                        'comment_slug' => $comment_data->comment_slug
                    ]
                );
            }
        } else {
            $this->attachments_type = $this->post_type;

            if ($this->config['attachments_allow_in_entries'] &&
                $this->current_user->hasPermission(UserPermissions::AttachmentsCanView)) {
                $templatesContext['entry_attachments'] = $this->buildAttachments(
                    $entry_data,
                    $entry_data->getAttachments()->filter(
                        fn(Attachment $attachment) => empty($attachment->field_id)
                    ),
                );
            }
        }

        // todo, this should probably account for additional groups, but I just took it from post bit logic for now
        if (!empty($this->user_data['uid'])) {
            $templatesContext['user_permissions'] = user_permissions($this->user_data['uid']);
        } else {
            $templatesContext['user_permissions'] = usergroup_permissions(1);
        }

        $userName = htmlspecialchars_uni($this->user_data['username'] ?? $this->language->guest);

        $userNameFormatted = format_name(
            $userName,
            $this->user_data['usergroup'] ?? 0,
            $this->user_data['displaygroup'] ?? 0
        );

        $templatesContext['user_avatar'] = '';

        if ((
                $this->post_type === self::POST_TYPE_ENTRY && $this->config['entries_display_avatars'] ||
                $this->post_type === self::POST_TYPE_COMMENT && $this->config['comments_display_avatars']
            ) &&
            (
                isset($this->user_data['avatar']) &&
                isset($this->mybb->user['showavatars']) &&
                !empty($this->mybb->user['showavatars']) ||
                !$this->current_user->user_id
            )) {
            $templatesContext['user_avatar'] = $userAvatar = format_avatar(
                $this->user_data['avatar'],
                $this->user_data['avatardimensions'],
            );
        }

        if (!empty($groupPermissions['usertitle']) && empty($this->user_data['usertitle'])) {
            $this->user_data['usertitle'] = $groupPermissions['usertitle'] ?? '';
        } elseif (empty($groupPermissions['usertitle']) &&
            ($userTitlesCache = $this->groupUserTitlesGet())) {
            foreach ($userTitlesCache as $postNumber => $titleinfo) {
                if ($this->user_data['postnum'] >= $postNumber) {
                    if (empty($this->user_data['usertitle'])) {
                        $this->user_data['usertitle'] = $titleinfo['title'];
                    }

                    $groupPermissions['stars'] = $titleinfo['stars'];

                    $groupPermissions['starimage'] = $titleinfo['starimage'];

                    break;
                }
            }
        }

        $templatesContext['userOnlineStatus'] = 'offline';

        if (isset($userData['lastactive'])) {
            if ($userData['lastactive'] > TIME_NOW - $this->mybb->settings['wolcutoff'] &&
                (empty($userData['invisible']) || !empty($this->mybb->usergroup['canviewwolinvis'])) &&
                (int)$userData['lastvisit'] !== (int)$userData['lastactive']) {
                $templatesContext['userOnlineStatus'] = 'online';
            } elseif (!empty($userData['away']) && !empty($this->mybb->settings['allowaway'])) {
                $templatesContext['userOnlineStatus'] = 'away';
            }
        }

        $moderatorCanManageEntries = $this->current_user->hasPermission(UserPermissions::EntriesCanView);

        $moderatorCanManageComments = $this->current_user->hasPermission(UserPermissions::CommentsCanView);

        $userCanUpdateEntries = $this->user_id === $this->current_user->user_id && $this->current_user->hasPermission(
                UserPermissions::EntriesCanUpdate
            );

        $userCanSoftDeleteEntries = $this->user_id === $this->current_user->user_id && $this->current_user->hasPermission(
                UserPermissions::EntriesCanDelete
            );

        $userCanUpdateComments = $this->user_id === $this->current_user->user_id && $this->current_user->hasPermission(
                UserPermissions::CommentsCanUpdate
            );

        $userCanSoftDeleteComments = $this->user_id === $this->current_user->user_id && $this->current_user->hasPermission(
                UserPermissions::CommentsCanDelete
            );

        if (!$this->is_preview && $this->post_type === self::POST_TYPE_ENTRY && $entry_data->entry_id && ($moderatorCanManageEntries || $userCanUpdateEntries)) {
            $templatesContext['url']['update'] = $this->routing->generateUrl(
                Routes::EntryUpdate,
                [
                    'entry_slug' => $entry_data->entry_slug,
                ]
            );
        } elseif (!$this->is_preview && $this->post_type === self::POST_TYPE_COMMENT && $comment_data->comment_id && ($moderatorCanManageComments || $userCanUpdateComments)) {
            $templatesContext['url']['update'] = $this->routing->generateUrl(
                Routes::CommentUpdate,
                [
                    'entry_slug' => $entry_data->entry_slug,
                    'entry_slug_custom' => $entry_data->entry_slug_custom,
                    'comment_slug' => $comment_data->comment_slug,
                ]
            );
        }

        if (!empty($this->mybb->settings['enablewarningsystem']) &&
            !empty($groupPermissions['canreceivewarnings']) &&
            (!empty($this->mybb->usergroup['canwarnusers']) || ($this->current_user->user_id === $this->user_id && !empty($this->mybb->settings['canviewownwarning'])))) {
            if ($this->mybb->settings['maxwarningpoints'] < 1) {
                $this->mybb->settings['maxwarningpoints'] = 10;
            }

            $warningLevel = round(
                $this->user_data['warningpoints'] / $this->mybb->settings['maxwarningpoints'] * 100
            );

            if ($warningLevel > 100) {
                $warningLevel = 100;
            }

            $warningLevel = get_colored_warning_level($warningLevel);

            // If we can warn them, it's not the same person, and we're in a PM or a post.
            if (!empty($this->mybb->usergroup['canwarnusers']) && $this->user_id !== $this->current_user->user_id) {
                $templatesContext['show_warn_button'] = true;

                $warningUrl = "warnings.php?uid={$this->user_id}";
            } else {
                $warningUrl = 'usercp.php';
            }

            if ($this->post_type === self::POST_TYPE_ENTRY && $this->config['entries_display_group_image'] ||
                $this->post_type === self::POST_TYPE_COMMENT && $this->config['comments_display_group_image']) {
            }
        }

        if (($this->post_type === self::POST_TYPE_ENTRY && $this->config['entries_display_signatures'] ||
                $this->post_type === self::POST_TYPE_COMMENT && $this->config['comments_display_signatures']) &&
            !empty($this->user_data['username']) &&
            !empty($this->user_data['signature']) &&
            (!$this->current_user->user_id || !empty($this->mybb->user['showsigs'])) &&
            (empty($this->user_data['suspendsignature']) || !empty($this->user_data['suspendsignature']) && !empty($this->user_data['suspendsigtime']) && $this->user_data['suspendsigtime'] < TIME_NOW) &&
            !empty($groupPermissions['canusesig']) &&
            (empty($groupPermissions['canusesigxposts']) || $groupPermissions['canusesigxposts'] > 0 && $this->user_data['postnum'] > $groupPermissions['canusesigxposts']) &&
            !is_member($this->mybb->settings['hidesignatures'])) {
            $signatureParserOptions = [
                'allow_html' => !empty($this->mybb->settings['sightml']),
                'allow_mycode' => !empty($this->mybb->settings['sigmycode']),
                'allow_smilies' => !empty($this->mybb->settings['sigsmilies']),
                'allow_imgcode' => !empty($this->mybb->settings['sigimgcode']),
                'me_username' => $this->user_data['username']
            ];

            if ($groupPermissions['signofollow']) {
                $signatureParserOptions['nofollow_on'] = true;
            }

            if ($this->current_user->user_id && empty($this->mybb->user['showimages']) || empty($this->mybb->settings['guestimages']) && !$this->current_user->user_id) {
                $signatureParserOptions['allow_imgcode'] = false;
            }

            $templatesContext['user_signature'] = $this->entries_manager->parseMessage(
                $this->user_data['signature'],
                $signatureParserOptions
            );
        }

        if ($moderatorCanManageEntries && (
                $this->post_type === self::POST_TYPE_ENTRY && !empty($entry_data->edit_user_id) ||
                $this->post_type === self::POST_TYPE_COMMENT && !empty($comment_data->edit_user_id)
            )) {
            $editUserData = get_user(
                $this->post_type === self::POST_TYPE_ENTRY ? $entry_data->edit_user_id : $comment_data->edit_user_id
            );
        }

        if (!empty($this->user_data['website']) &&
            !is_member($this->mybb->settings['hidewebsite']) &&
            !empty($groupPermissions['canchangewebsite'])) {
            $userWebsite = htmlspecialchars_uni($this->user_data['website']);
        }

        require_once MYBB_ROOT . 'inc/functions_user.php';

        if (!$this->is_preview && $this->user_id &&
            purgespammer_show($this->user_data['postnum'] ?? 0, $this->user_data['usergroup'], $this->user_id)) {
            $templatesContext['show_purge_spammer_button'] = true;
        }

        $query = $this->database->simple_select(
            'reportedcontent',
            'uid'
        );

        $reportUserIDs = [];

        while ($reportData = $this->database->fetch_array($query)) {
            $reportUserIDs[] = (int)$reportData['uid'];
        }

        $userPermissions = user_permissions($this->user_id);

        if (!in_array(
                $this->current_user->user_id,
                $reportUserIDs
            ) && !empty($userPermissions['canbereported'])) {
            $templatesContext['show_report_button'] = true;
        }

        if ($this->post_type === self::POST_TYPE_ENTRY) {
            $postStatus = (int)$entry_data->status;
        } else {
            $postStatus = $comment_data->status;
        }

        if (!$this->is_preview && $this->post_type === self::POST_TYPE_ENTRY && $entry_data->entry_id && ($moderatorCanManageEntries || $userCanSoftDeleteEntries)) {
            if ($moderatorCanManageEntries && $postStatus === $this->entries_manager::STATUS_PENDING) {
                $approveUrl = $this->routing->generateUrl(
                    Routes::EntryUpdate,
                    [
                        'entry_slug' => $entry_data->entry_slug,
                        'action' => 'approve',
                    ]
                );
            } elseif ($moderatorCanManageEntries && $postStatus === $this->entries_manager::STATUS_VISIBLE) {
                $unapproveUrl = $this->routing->generateUrl(
                    Routes::EntryUpdate,
                    [
                        'entry_slug' => $entry_data->entry_slug,
                        'action' => 'unapprove',
                    ]
                );
            }

            if ($moderatorCanManageEntries && $postStatus === $this->entries_manager::STATUS_DELETED) {
                $restoreUrl = $this->routing->generateUrl(
                    Routes::EntryUpdate,
                    [
                        'entry_slug' => $entry_data->entry_slug,
                        'action' => 'restore',
                    ]
                );
            } elseif ($postStatus === $this->entries_manager::STATUS_VISIBLE && $userCanSoftDeleteEntries) {
                $softDeleteUrl = $this->routing->generateUrl(
                    Routes::EntryUpdate,
                    [
                        'entry_slug' => $entry_data->entry_slug,
                        'action' => 'soft_delete',
                    ]
                );
            }

            //only mods, original author (if allowed) or owner (if allowed) can delete comments
            if ($moderatorCanManageEntries) {
                $deleteUrl = $this->routing->generateUrl(
                    Routes::EntryUpdate,
                    [
                        'entry_slug' => $entry_data->entry_slug,
                        'action' => 'delete',
                    ]
                );
            }
        } elseif (!$this->is_preview && $this->post_type === self::POST_TYPE_COMMENT && $comment_data->comment_id && ($moderatorCanManageComments || $userCanSoftDeleteComments)) {
            if ($moderatorCanManageComments && $postStatus === $this->comments_manager::STATUS_PENDING) {
                $templatesContext['url_comment_approve'] = $this->config['showcase_url'] . $this->routing->generateUrl(
                        Routes::CommentView,
                        array_merge($this->url_params, [
                            'entry_slug' => $entry_data->entry_slug,
                            'entry_slug_custom' => $entry_data->entry_slug_custom,
                            'comment_slug' => $comment_data->comment_slug,
                            'action' => 'approve',
                        ])
                    );
            } elseif ($moderatorCanManageComments && $postStatus === $this->comments_manager::STATUS_VISIBLE) {
                $templatesContext['url_comment_unapprove'] = $this->routing->generateUrl(
                    Routes::CommentUpdate,
                    [
                        'entry_slug' => $entry_data->entry_slug,
                        'entry_slug_custom' => $entry_data->entry_slug_custom,
                        'comment_slug' => $comment_data->comment_slug,
                        'action' => 'unapprove',
                    ]
                );
            }

            if ($moderatorCanManageComments && $postStatus === $this->comments_manager::STATUS_DELETED) {
                $templatesContext['url_comment_restore'] = $this->routing->generateUrl(
                    Routes::CommentUpdate,
                    [
                        'entry_slug' => $entry_data->entry_slug,
                        'entry_slug_custom' => $entry_data->entry_slug_custom,
                        'comment_slug' => $comment_data->comment_slug,
                        'action' => 'restore',
                    ]
                );
            } elseif ($postStatus === $this->comments_manager::STATUS_VISIBLE) {
                $templatesContext['url_comment_soft_delete'] = $this->routing->generateUrl(
                    Routes::CommentUpdate,
                    [
                        'entry_slug' => $entry_data->entry_slug,
                        'entry_slug_custom' => $entry_data->entry_slug_custom,
                        'comment_slug' => $comment_data->comment_slug,
                        'action' => 'soft_delete',
                    ]
                );
            }

            //only mods, original author (if allowed) or owner (if allowed) can delete comments
            if ($moderatorCanManageComments) {
                $templatesContext['url_comment_delete'] = $this->routing->generateUrl(
                    Routes::CommentUpdate,
                    [
                        'entry_slug' => $entry_data->entry_slug,
                        'entry_slug_custom' => $entry_data->entry_slug_custom,
                        'comment_slug' => $comment_data->comment_slug,
                        'action' => 'delete',
                    ]
                );
            }
        }

        if ($this->post_type === self::POST_TYPE_ENTRY && $this->config['entries_display_details'] ||
            $this->post_type === self::POST_TYPE_COMMENT && $this->config['comments_display_details']) {
            $userPostNumber = my_number_format($this->user_data['postnum'] ?? 0);

            $userThreadNumber = my_number_format($this->user_data['threadnum'] ?? 0);

            $userRegistrationDate = my_date($this->mybb->settings['regdateformat'], $this->user_data['regdate'] ?? 0);

            if (!empty($groupPermissions['usereputationsystem']) && !empty($this->mybb->settings['enablereputation'])) {
                $userReputation = get_reputation($this->user_data['reputation'], $this->user_id);
            }
        }

        switch ($postStatus) {
            case $this->comments_manager::STATUS_PENDING:
                $templatesContext['unapproved_class'] = 'unapproved_post';
                break;
            case $this->comments_manager::STATUS_DELETED:
                $templatesContext['unapproved_class'] = 'unapproved_post deleted_post';
                break;
        }

        if (!$this->is_preview && (
                self::POST_TYPE_ENTRY && $postStatus === $this->entries_manager::STATUS_DELETED ||
                self::POST_TYPE_COMMENT && $postStatus === $this->comments_manager::STATUS_DELETED
            )) {
            if (self::POST_TYPE_ENTRY) {
                $deletedMessage = $this->language->sprintf($this->language->myShowcaseEntryDeletedMessage, $userName);
            } else {
                $deletedMessage = $this->language->sprintf($this->language->myShowcaseCommentDeletedMessage, $userName);
            }

            $templatesContext['is_deleted'] = true;

            $templatesContext['style_visibility'] = 'display: none;';
        }

        // Is the user (not moderator) logged in and have unapproved posts?
        if (!$this->is_preview && ($this->current_user->user_id &&
                (
                    $this->post_type === self::POST_TYPE_ENTRY && $postStatus === $this->entries_manager::STATUS_PENDING ||
                    $this->post_type === self::POST_TYPE_COMMENT && $postStatus === $this->comments_manager::STATUS_PENDING
                ) &&
                $this->user_id === $this->current_user->user_id &&
                !(
                    $this->post_type === self::POST_TYPE_ENTRY && $moderatorCanManageEntries ||
                    $this->post_type === self::POST_TYPE_COMMENT && $moderatorCanManageComments
                ))) {
            $templatesContext['is_ignored'] = true;

            $templatesContext['ignored_message'] = $this->language->myshowcase_view_entry_under_moderation;

            $templatesContext['style_visibility'] = 'display: none;';
        }

        // Is this author on the ignore list of the current user? Hide this post
        if (!$this->is_preview && is_array($user_ignored_objects) &&
            isset($user_ignored_objects[$this->user_id]) &&
            !$templatesContext['is_deleted']) {
            $templatesContext['is_ignored'] = true;

            $templatesContext['ignored_message'] = $this->language->sprintf(
                $this->language->myshowcase_view_entry_currently_ignoring_user,
                $userName,
                $this->mybb->settings['bburl']
            );

            $templatesContext['style_visibility'] = 'display: none;';
        }

        // todo, the entry query should fetch this from the reports table
        if ($entry_data->getExtraData('reporters_list') === false) {
            $entry_data->setExtraData(
                'reporters_list',
                $this->entries_repository->getContentReports($entry_data->entry_id)
            );
        }

        if ($this->post_type === self::POST_TYPE_COMMENT) {
            if ($comment_data->getExtraData('reporters_list') === false) {
                $comment_data->setExtraData(
                    'reporters_list',
                    $this->entries_repository->getContentReports($comment_data->entry_id)
                );
            }
        }

        $templatesContext['show_purge_spammer_button'] = purgespammer_show(
            $this->user_data['postnum'],
            $this->user_data['usergroup'],
            $this->user_data['uid']
        );

        $templatesContext['show_edited_message'] = false;

        // Figure out if we need to show an "edited by" message
        if ($entry_data->edit_user_id && $entry_data->edit_stamp && (
                !empty($this->mybb->settings['showeditedby']) ||
                (!empty($this->mybb->settings['showeditedbyadmin']) && $this->current_user->hasPermission(
                        UserPermissions::EntriesCanView
                    ))
            )) {
            $templatesContext['show_edited_message'] = true;

            $entry_data->setExtraData('edit_user_data', get_user($entry_data->edit_user_id));

            if ($entry_data->edit_note) {
                $entry_data->edit_note = postParser()->parse_badwords($entry_data->edit_note ?? '');
            }
        }

        $entry_data
            ->setExtraData('show_ip', false)
            ->setExtraData('hide_ip', false)
            ->setExtraData('can_edit', false);

        if ($this->mybb->settings['logip'] !== 'no' && !empty($entry_data->ipaddress)) {
            $entry_data->setExtraData(
                'ip_address',
                my_inet_ntop($this->database->unescape_binary($entry_data->ipaddress))
            );

            if ($this->mybb->settings['logip'] === 'show') {
                $entry_data->setExtraData('show_ip', true);
            } elseif ($this->mybb->settings['logip'] === 'hide' &&
                ($this->current_user->hasPermission(
                        UserPermissions::EntriesCanView
                    ) || $this->mybb->usergroup['issupermod'])) {
                $entry_data->setExtraData('show_ip', true);
            }
        }

        $entry_data->message = $this->entries_manager->parseMessage($entry_data->message ?? '', $this->parser_options);

        if ($this->current_user->hasPermission(UserPermissions::EntriesCanView) ||
            ($this->current_user->user_id === $entry_data->user_id && empty($thread['closed']) && (
                    empty($this->mybb->usergroup['edittimelimit']) ||
                    $post['dateline'] > (TIME_NOW - ($this->mybb->usergroup['edittimelimit'] * 60))
                )) &&
            $this->current_user->user_id) {
            $entry_data->setExtraData('can_edit', true);
        }

        hooksRun('render_build_entry_comment_end', $this);

        return $this->templateGet(
            $templatePrefix,
            $templatesContext,
        );
    }

    /**
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError
     */
    public function buildEntry(
        Entry $entry,
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
        Entry $entry_data,
        Comment $comment_data,
        bool $is_preview = false,
    ): string {
        $this->post_type = self::POST_TYPE_COMMENT;

        $this->is_preview = $is_preview;

        return $this->buildPost($entry_data, $comment_data);
    }

    /**
     * @throws RuntimeError
     * @throws LoaderError
     * @throws SyntaxError
     */
    public function buildAttachments(
        Entry $entry_data,
        Collection $attachment_objects,
        array &$entry_fields = [],
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

        if ($this->attachments_type === self::POST_TYPE_COMMENT) {
            $template_prefix = 'Comment';
        } elseif ($this->attachments_type === self::POST_TYPE_ENTRY_FIELD) {
            $template_prefix .= 'Field';
        }

        /**
         * @var Attachment $attachment_data
         */
        foreach ($attachment_objects as $attachmentID => $attachment_data) {
            $attachment_data
                ->setExtraData('file_size', get_friendly_size($attachment_data->file_size))
                ->setExtraData(
                    'is_image',
                    str_starts_with($attachment_data->mime_type, 'image/')
                )
                ->setExtraData('icon', get_attachment_icon($attachment_data->attachment_name));

            $attachment_data->has_thumbnail = !empty($attachment_data->thumbnail_name) && $this->config['attachments_display_thumbnails'];

            // todo, maybe all attachments should be accessible from the same route, with entry_slug or post_hash optional
            if ($this->attachments_type === self::ATTACHMENT_TYPE_ENTRY || $this->attachments_type === self::ATTACHMENT_TYPE_FIELD) {
                if ($entry_data->entry_id) {
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
                } else {
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
                }

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
            }

            if ($attachment_data->status or true) { // todo, unapproved attachments should display regardless
                $attachmentExtension = get_extension($attachment_data->attachment_name);

                $isImageAttachment = in_array($attachmentExtension, ['jpeg', 'gif', 'bmp', 'png', 'jpg']);

                // Support for [attachment=attachment_hash] code
                $attachmentInField = false;

                foreach ($entry_fields as $field_header => &$fieldDefinition) {
                    if (str_contains(
                            $fieldDefinition['value'],
                            '[attachment=' . $attachment_data->attachment_hash . ']'
                        ) || str_contains(
                            $fieldDefinition['value_display'],
                            '[attachment=' . $attachment_data->attachment_hash . ']'
                        )) {
                        $attachmentInField = true;

                        $attachmentBit = $this->templateGet(
                            'pageView' . $template_prefix . 'Attachment',
                            [
                                'attachment_id' => $attachmentID,
                                'attachment_data' => $attachment_data,
                                'display_thumbnail' => $this->config['attachments_display_thumbnails'],
                                'display_full' => $this->config['attachments_display_full_images'],
                            ]
                        );

                        if (str_contains(
                            $fieldDefinition['value'],
                            '[attachment=' . $attachment_data->attachment_hash . ']'
                        )) {
                            $fieldDefinition['value'] = preg_replace(
                                '#\[attachment=' . $attachment_data->attachment_hash . ']#si',
                                $attachmentBit,
                                $fieldDefinition['value']
                            );
                        }

                        if (str_contains(
                            $fieldDefinition['value_display'],
                            '[attachment=' . $attachment_data->attachment_hash . ']'
                        )) {
                            $fieldDefinition['value'] = preg_replace(
                                '#\[attachment=' . $attachment_data->attachment_hash . ']#si',
                                $attachmentBit,
                                $fieldDefinition['value']
                            );
                        }
                    }
                }

                if ($attachmentInField) {
                    continue;
                }

                if ($attachment_data->has_thumbnail && $this->config['attachments_display_thumbnails']) {
                    $templatesContext['attachedThumbnails'][$attachmentID] = $attachment_data;
                } elseif (!$attachment_data->has_thumbnail && $this->config['attachments_display_full_images']) {
                    $templatesContext['attachedImages'][$attachmentID] = $attachment_data;
                } else {
                    $templatesContext['attachedFiles'][$attachmentID] = $attachment_data;
                }
            } else {
                ++$unapprovedCount;
            }
        }

        $templatesContext['show_unapproved'] = $unapprovedCount > 0 && $this->current_user->hasPermission(
                UserPermissions::CanManageAttachments
            );

        if ($templatesContext['show_unapproved']) {
            if ($unapprovedCount === 1) {
                $templatesContext['unapproved_message'] = $this->language->myshowcase_view_attachments_unapproved_single;
            } else {
                $templatesContext['unapproved_message'] = $this->language->sprintf(
                    $this->language->myshowcase_view_entry_button_deleted_show,
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

    public function buildAttachmentsUpload(bool $isEditPage, int $post_type = self::POST_TYPE_ENTRY): string
    {
        return '';
        $this->post_type = $post_type;

        $myDropzoneSettingMaximumFileSize = 0;

        $myDropzoneSettingAllowedMimeFileTypes = [];

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
            $this->attachment_types
        );

        $myDropzoneSettingAllowedMimeFileTypes = implode(',', $myDropzoneSettingAllowedMimeFileTypes);

        if ($isEditPage) {
            $createUpdateUrl = $this->routing->generateUrl(
                Routes::EntryUpdate,
                [
                    'entry_slug' => $entry_data->entry_slug,
                ],
                $this->url_params
            );
        } else {
            $createUpdateUrl = $this->routing->generateUrl(
                Routes::EntryCreate,
                $this->url_params
            );
        }

        $watermarkSelectedElement = '';

        if ($this->mybb->get_input('attachment_watermark_file')) {
            $watermarkSelectedElement = 'checked="checked"';
        }

        if ($this->current_user->getPermission(UserPermissions::AttachmentsLimitUploadQuotaAmount) ===
            Permission::NUMERIC_TYPE_UNLIMITED) {
            $usageQuoteNote = $this->language->sprintf(
                $this->language->myShowcaseAttachmentsUsageQuote,
                $this->language->myShowcaseAttachmentsUsageQuoteUnlimited
            );
        } else {
            $usageQuoteNote = $this->language->sprintf(
                $this->language->myShowcaseAttachmentsUsageQuote,
                get_friendly_size(
                    $this->current_user->getPermission(UserPermissions::AttachmentsLimitUploadQuotaAmount) * 1024
                )
            );
        }

        $totalUserUsage = $this->attachments_repository->queryAttachmentSum(
            ["user_id='{$this->current_user->user_id}'"]
        );

        $usageDetails = $viewMyAttachmentsLink = '';

        $template_prefix = 'Entry';

        if ($this->post_type === self::POST_TYPE_COMMENT) {
            $template_prefix = 'Comment';
        }

        if ($totalUserUsage > 0) {
            $usageDetails = $this->language->sprintf(
                $this->language->myShowcaseAttachmentsUsageDetails,
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

    public function parseMessage(string $message, array $options = []): string
    {
        return postParser()->parse_message($message, array_merge($this->parser_options, $options));
    }

    public function editorBuild(string &$code_buttons, string &$smiles_inserter, string $identifier = 'comment'): void
    {
        if (!empty($this->mybb->settings['bbcodeinserter']) &&
            $this->config['parser_allow_mycode'] &&
            (!$this->current_user->user_id || !empty($this->mybb->user['showcodebuttons']))) {
            $code_buttons = build_mycode_inserter(
                $identifier,
                $this->config['parser_allow_smiles']
            );

            if ($this->config['parser_allow_smiles']) {
                $smiles_inserter = build_clickable_smilies();
            }
        }
    }
}