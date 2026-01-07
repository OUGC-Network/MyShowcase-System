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

use MyShowcase\Collection\Collection;
use MyShowcase\Entity\Attachment;
use MyShowcase\Entity\Comment;
use MyShowcase\Entity\Entry;
use MyShowcase\Enum\ModeratorPermissions;
use MyShowcase\Enum\UserPermissions;
use MyShowcase\Fields\FieldFactory;
use MyShowcase\Repository\AttachmentsRepository;
use MyShowcase\Repository\EntriesRepository;
use MyShowcase\Enum\Routes;
use MyShowcase\Routing\Routing;
use MyShowcase\System\Showcase;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\templateGet;

use const MyShowcase\Plugin\Core\ALL_UNLIMITED_VALUE;
use const MyShowcase\Plugin\Core\DEBUG;
use const MyShowcase\Plugin\Core\VERSION_CODE;

class Render
{
    public const POST_TYPE_ENTRY = 1;
    public const POST_TYPE_ENTRY_FIELD = 2;

    public const POST_TYPE_COMMENT = 3;
    public const ATTACHMENT_TYPE_ENTRY = 1;
    public const ATTACHMENT_TYPE_FIELD = 2;
    public const ATTACHMENT_TYPE_COMMENT = 3;

    private array $parser_options = [];

    private int $post_type = self::POST_TYPE_ENTRY;

    private int $attachments_type = self::ATTACHMENT_TYPE_ENTRY;

    private bool $is_preview = false;

    public function __construct(
        private readonly FieldFactory $field_factory,
        private readonly int $current_user_id,
        private readonly array $user_permissions,
        private readonly array $url_params,
        private readonly array $config,
        private readonly array $attachment_types,
        private readonly Routing $routing,
        private readonly EntriesRepository $entry_repository,
        private readonly AttachmentsRepository $attachments_repository,
        private readonly EntriesManager $entries_manager,
        private readonly CommentsManager $comments_manager,
        private readonly array $fields_cache,
        private readonly bool $attachments_display_thumbnails,
        private readonly bool $attachments_display_full_images,
    ) {
    }

    /**
     * @throws SyntaxError
     * @throws RuntimeError
     * @throws LoaderError
     */
    public function templateGet(string $name = '', array $context = []): string
    {
        $context = array_merge($context, [
            'config' => $this->config,
            'current_page' => $this->current_page,
            'keywords' => $this->search_keywords,
            'search_exact' => $this->search_exact,
            'permissions' => $this->user_permissions,
            'error_messages' => empty($this->error_messages) ? '' : inline_error(
                $this->error_messages
            ),
            'version' => DEBUG ? TIME_NOW : VERSION_CODE,
            'pagination' => '',
        ]);

        return templateGet(
            $name,
            array_merge_recursive([
                'showcase' => [
                    'fieldset_display_fields' => $this->fieldset_display_fields,
                ],
                'meta' => [],
                'url' => [
                    'main' => $this->routing->generateUrl(Routes::Main),
                ],
            ], $context),
            $this->config['custom_theme_template_prefix']
        );
    }

    public function groupUserTitlesGet(): array
    {
        static $titles_cache = null;

        if ($titles_cache === null) {
            global $cache;

            $titles_cache = [];

            foreach ((array)$cache->read('usertitles') as $title) {
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

        global $mybb, $lang, $theme;

        static $user_ignored_objects = null;

        if ($user_ignored_objects === null) {
            $user_ignored_objects = [];

            if ($this->current_user_id > 0 && !empty($mybb->user['ignorelist'])) {
                $user_ignored_objects = array_filter(array_flip(explode(',', $mybb->user['ignorelist'])));
            }
        }

        if ($this->post_type === self::POST_TYPE_ENTRY && $entry_data->user_id) {
            $user_id = $entry_data->user_id;
        } elseif ($this->post_type === self::POST_TYPE_COMMENT && $comment_data->user_id) {
            $user_id = $comment_data->user_id;
        } else {
            $user_id = $this->current_user_id;
        }

        $user_data = get_user($user_id);

        $templatesContext = [
            'entry_data' => $entry_data,
            'comment_data' => $comment_data,
            'url' => [
                'entry' => '',
                'update' => '',
            ],
            'style_class' => '',
            'is_deleted' => false,
            'is_ignored' => false,
            'ignored_message' => '',
            'style_visibility' => '',
            'fields_objects' => $this->fields_cache,
            'entry_attachments' => '',
            'commentAttachments' => '',
            'class' => '',
            'show_report_button' => false,
            'show_warn_button' => false,
            'user_data' => $user_data,
            'user_signature' => '',
        ];

        if ($this->post_type === self::POST_TYPE_ENTRY) {
            $templatesContext['subject'] = $this->entries_manager->buildSubject($entry_data);

            $entry_data
                ->setExtraData('is_deleted', false)
                ->setExtraData('moderator_check', false);
        }

        $hookArguments = [
            'renderObject' => &$this,
            'comment_data' => &$comment_object,
            'userID' => &$userID,
            'user_data' => &$user_data,
        ];

        $extractVariables = [];

        $hookArguments['extractVariables'] = &$extractVariables;

        $hookArguments = hooksRun('render_build_entry_comment_start', $hookArguments);

        extract($hookArguments['extractVariables']);

        $extractVariables = [];

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
            $entry_data->addAttachment($attachment_data);
        }

        if ($this->post_type === self::POST_TYPE_COMMENT) {
            $templatesContext['formatted_message'] = $this->entries_manager->parseMessage(
                $comment_data->comment,
                $this->parser_options
            );

            if (!$this->is_preview) {
                $templatesContext['url']['comment'] = $this->routing->generateUrl(
                    Routes::Comment,
                    [
                        'comment_slug' => $comment_data->comment_slug
                    ]
                );
            }
        } else {
            foreach ($templatesContext['fields_objects'] as $fieldID => &$fieldDefinition) {
                $fieldObject = $this->field_factory->create(
                    $this->fields_cache[$fieldID],
                    $entry_data->entry_id,
                    $entry_data->post_hash
                );

                if (!$fieldObject->isViewableInEntry()) {
                    unset($templatesContext['fields_objects'][$fieldID]);

                    continue;
                }

                $fieldObject->valueSet($entry_data->getExtraData($fieldDefinition['field_key']) ?? '')
                    ->valuesBuild($entry_data);

                $fieldDefinition['value'] = $fieldObject->getUserValue();

                $fieldDefinition['value_display'] = $fieldObject->getUserValueDisplay();
            }

            foreach ($templatesContext['fields_objects'] as $fieldID => &$fieldDefinition) {
                $fieldObject = $this->field_factory->create(
                    $this->fields_cache[$fieldID],
                    $entry_data->entry_id,
                    $entry_data->post_hash
                );

                $this->attachments_type = self::ATTACHMENT_TYPE_FIELD;

                $fieldDefinition['field_attachments'] = $this->buildAttachments(
                    $entry_data,
                    $entry_data->getAttachments()->filter(
                        function (Attachment $attachment) use ($fieldID): bool {
                            return $attachment->field_id === $fieldID;
                        }
                    ),
                    $templatesContext['fields_objects'],
                );
            }

            $this->attachments_type = $this->post_type;

            if ($this->config['attachments_allow_entries'] &&
                $this->user_permissions[UserPermissions::CanViewAttachments]) {
                $templatesContext['entry_attachments'] = $this->buildAttachments(
                    $entry_data,
                    $entry_data->getAttachments()->filter(
                        fn(Attachment $attachment) => empty($attachment->field_id)
                    ),
                    $templatesContext['fields_objects'],
                );
            }
            /*
                        $templatesContext['entry_attachments'] = '';

                        if ($this->showcaseObject->config['attachments_allow_entries'] &&
                            $this->showcaseObject->user_permissions[UserPermissions::CanViewAttachments]) {
                            $templatesContext['entry_attachments'] = $this->entryBuildAttachments(
                                $templatesContext['fields_objects'],
                                $postType,
                                $comment_data->comment_slug ?? ''
                            );
                        }*/
        }

        // todo, this should probably account for additional groups, but I just took it from post bit logic for now
        if (!empty($user_data['uid'])) {
            $templatesContext['user_permissions'] = user_permissions($user_data['uid']);
        } else {
            $templatesContext['user_permissions'] = usergroup_permissions(1);
        }

        $userName = htmlspecialchars_uni($user_data['username'] ?? $lang->guest);

        $userNameFormatted = format_name(
            $userName,
            $user_data['usergroup'] ?? 0,
            $user_data['displaygroup'] ?? 0
        );

        $templatesContext['user_avatar'] = '';

        if ($this->post_type === self::POST_TYPE_ENTRY && $this->config['display_avatars_entries'] ||
            $this->post_type === self::POST_TYPE_COMMENT && $this->config['display_avatars_comments']) {
            if (isset($user_data['avatar']) && isset($mybb->user['showavatars']) && !empty($mybb->user['showavatars']) || !$this->current_user_id) {
                $templatesContext['user_avatar'] = $userAvatar = format_avatar(
                    $user_data['avatar'],
                    $user_data['avatardimensions'],
                );
            }
        }

        if (!empty($groupPermissions['usertitle']) && empty($user_data['usertitle'])) {
            $user_data['usertitle'] = $groupPermissions['usertitle'] ?? '';
        } elseif (empty($groupPermissions['usertitle']) &&
            ($userTitlesCache = $this->groupUserTitlesGet())) {
            foreach ($userTitlesCache as $postNumber => $titleinfo) {
                if ($user_data['postnum'] >= $postNumber) {
                    if (empty($user_data['usertitle'])) {
                        $user_data['usertitle'] = $titleinfo['title'];
                    }

                    $groupPermissions['stars'] = $titleinfo['stars'];

                    $groupPermissions['starimage'] = $titleinfo['starimage'];

                    break;
                }
            }
        }

        if ($this->post_type === self::POST_TYPE_ENTRY && $this->config['display_stars_entries'] ||
            $this->post_type === self::POST_TYPE_COMMENT && $this->config['display_stars_comments']) {
            if (!empty($groupPermissions['starimage']) && isset($groupPermissions['stars'])) {
                $groupStarImage = str_replace('{theme}', $theme['imgdir'], $groupPermissions['starimage']);
            }
        }

        if ($this->post_type === self::POST_TYPE_ENTRY && $this->config['display_group_image_entries'] ||
            $this->post_type === self::POST_TYPE_COMMENT && $this->config['display_group_image_comments']) {
            if (!empty($groupPermissions['image'])) {
                $groupImage = str_replace(['{lang}', '{theme}'],
                    [$mybb->user['language'] ?? $mybb->settings['language'], $theme['imgdir']],
                    $groupPermissions['image']);

                $groupTitle = $groupPermissions['title'];
            }
        }

        $templatesContext['userOnlineStatus'] = 'offline';

        if (isset($userData['lastactive'])) {
            if ($userData['lastactive'] > TIME_NOW - $mybb->settings['wolcutoff'] &&
                (empty($userData['invisible']) || !empty($mybb->usergroup['canviewwolinvis'])) &&
                (int)$userData['lastvisit'] !== (int)$userData['lastactive']) {
                $templatesContext['userOnlineStatus'] = 'online';
            } elseif (!empty($userData['away']) && !empty($mybb->settings['allowaway'])) {
                $templatesContext['userOnlineStatus'] = 'away';
            }
        }

        $moderatorCanManageEntries = $this->user_permissions[ModeratorPermissions::CanManageEntries];

        $moderatorCanManageComments = $this->user_permissions[ModeratorPermissions::CanManageComments];

        $userCanUpdateEntries = $user_id === $this->current_user_id && $this->user_permissions[UserPermissions::CanUpdateEntries];

        $userCanSoftDeleteEntries = $user_id === $this->current_user_id && $this->user_permissions[UserPermissions::CanDeleteEntries];

        $userCanUpdateComments = $user_id === $this->current_user_id && $this->user_permissions[UserPermissions::CanUpdateComments];

        $userCanSoftDeleteComments = $user_id === $this->current_user_id && $this->user_permissions[UserPermissions::CanDeleteComments];

        if (!$this->is_preview && $this->post_type === self::POST_TYPE_ENTRY && $entry_data->entry_id && ($moderatorCanManageEntries || $userCanUpdateEntries)) {
            $templatesContext['url']['update'] = $this->routing->generateUrl(
                Routes::EntryUpdate,
                [
                    'entry_slug' => $entry_data->entry_slug,
                    'entry_slug_custom' => $entry_data->entry_slug_custom,
                ]
            );
        } elseif (!$this->is_preview && $this->post_type === self::POST_TYPE_COMMENT && $comment_data->comment_id && ($moderatorCanManageComments || $userCanUpdateComments)) {
            $templatesContext['url']['update'] = $this->routing->generateUrl(
                Routes::CommentUpdate,
                [
                    'comment_slug' => $comment_data->comment_slug
                ]
            );
        }

        if (!empty($mybb->settings['enablewarningsystem']) &&
            !empty($groupPermissions['canreceivewarnings']) &&
            (!empty($mybb->usergroup['canwarnusers']) || ($this->current_user_id === $user_id && !empty($mybb->settings['canviewownwarning'])))) {
            if ($mybb->settings['maxwarningpoints'] < 1) {
                $mybb->settings['maxwarningpoints'] = 10;
            }

            $warningLevel = round(
                $user_data['warningpoints'] / $mybb->settings['maxwarningpoints'] * 100
            );

            if ($warningLevel > 100) {
                $warningLevel = 100;
            }

            $warningLevel = get_colored_warning_level($warningLevel);

            // If we can warn them, it's not the same person, and we're in a PM or a post.
            if (!empty($mybb->usergroup['canwarnusers']) && $user_id !== $this->current_user_id) {
                $templatesContext['show_warn_button'] = true;

                $warningUrl = "warnings.php?uid={$user_id}";
            } else {
                $warningUrl = 'usercp.php';
            }

            if ($this->post_type === self::POST_TYPE_ENTRY && $this->config['display_group_image_entries'] ||
                $this->post_type === self::POST_TYPE_COMMENT && $this->config['display_group_image_comments']) {
            }
        }

        if (($this->post_type === self::POST_TYPE_ENTRY && $this->config['display_signatures_entries'] ||
                $this->post_type === self::POST_TYPE_COMMENT && $this->config['display_signatures_comments']) &&
            !empty($user_data['username']) &&
            !empty($user_data['signature']) &&
            (!$this->current_user_id || !empty($mybb->user['showsigs'])) &&
            (empty($user_data['suspendsignature']) || !empty($user_data['suspendsignature']) && !empty($user_data['suspendsigtime']) && $user_data['suspendsigtime'] < TIME_NOW) &&
            !empty($groupPermissions['canusesig']) &&
            (empty($groupPermissions['canusesigxposts']) || $groupPermissions['canusesigxposts'] > 0 && $user_data['postnum'] > $groupPermissions['canusesigxposts']) &&
            !is_member($mybb->settings['hidesignatures'])) {
            $signatureParserOptions = [
                'allow_html' => !empty($mybb->settings['sightml']),
                'allow_mycode' => !empty($mybb->settings['sigmycode']),
                'allow_smilies' => !empty($mybb->settings['sigsmilies']),
                'allow_imgcode' => !empty($mybb->settings['sigimgcode']),
                'me_username' => $user_data['username']
            ];

            if ($groupPermissions['signofollow']) {
                $signatureParserOptions['nofollow_on'] = true;
            }

            if ($this->current_user_id && empty($mybb->user['showimages']) || empty($mybb->settings['guestimages']) && !$this->current_user_id) {
                $signatureParserOptions['allow_imgcode'] = false;
            }

            $templatesContext['user_signature'] = $this->entries_manager->parseMessage(
                $user_data['signature'],
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

        if (!empty($user_data['website']) &&
            !is_member($mybb->settings['hidewebsite']) &&
            !empty($groupPermissions['canchangewebsite'])) {
            $userWebsite = htmlspecialchars_uni($user_data['website']);
        }

        require_once MYBB_ROOT . 'inc/functions_user.php';

        if (!$this->is_preview && $user_id &&
            purgespammer_show($user_data['postnum'] ?? 0, $user_data['usergroup'], $user_id)) {
            $templatesContext['show_purge_spammer_button'] = true;
        }

        global $db;

        $query = $db->simple_select(
            'reportedcontent',
            'uid'
        );

        $reportUserIDs = [];

        while ($reportData = $db->fetch_array($query)) {
            $reportUserIDs[] = (int)$reportData['uid'];
        }

        $userPermissions = user_permissions($user_id);

        if (!in_array(
                $this->current_user_id,
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
                        'entry_slug_custom' => $entry_data->entry_slug_custom,
                        'action' => 'approve',
                    ]
                );
            } elseif ($moderatorCanManageEntries && $postStatus === $this->entries_manager::STATUS_VISIBLE) {
                $unapproveUrl = $this->routing->generateUrl(
                    Routes::EntryUpdate,
                    [
                        'entry_slug' => $entry_data->entry_slug,
                        'entry_slug_custom' => $entry_data->entry_slug_custom,
                        'action' => 'unapprove',
                    ]
                );
            }

            if ($moderatorCanManageEntries && $postStatus === $this->entries_manager::STATUS_DELETED) {
                $restoreUrl = $this->routing->generateUrl(
                    Routes::EntryUpdate,
                    [
                        'entry_slug' => $entry_data->entry_slug,
                        'entry_slug_custom' => $entry_data->entry_slug_custom,
                        'action' => 'restore',
                    ]
                );
            } elseif ($postStatus === $this->entries_manager::STATUS_VISIBLE && $userCanSoftDeleteEntries) {
                $softDeleteUrl = $this->routing->generateUrl(
                    Routes::EntryUpdate,
                    [
                        'entry_slug' => $entry_data->entry_slug,
                        'entry_slug_custom' => $entry_data->entry_slug_custom,
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
                        'entry_slug_custom' => $entry_data->entry_slug_custom,
                        'action' => 'delete',
                    ]
                );
            }
        } elseif (!$this->is_preview && $this->post_type === self::POST_TYPE_COMMENT && $comment_data->comment_id && ($moderatorCanManageComments || $userCanSoftDeleteComments)) {
            if ($moderatorCanManageComments && $postStatus === $this->comments_manager::STATUS_PENDING) {
                $templatesContext['url_comment_approve'] = $this->config['url_showcase'] . '/' . $this->routing->generateUrl(
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
                        'comment_slug' => $comment_data->comment_slug,
                        'action' => 'delete',
                    ]
                );
            }
        }

        $hookArguments['extractVariables'] = &$extractVariables;

        $hookArguments = hooksRun('render_build_entry_comment_end', $hookArguments);

        extract($hookArguments['extractVariables']);

        if ($this->post_type === self::POST_TYPE_ENTRY && $this->config['display_user_details_entries'] ||
            $this->post_type === self::POST_TYPE_COMMENT && $this->config['display_user_details_comments']) {
            $userPostNumber = my_number_format($user_data['postnum'] ?? 0);

            $userThreadNumber = my_number_format($user_data['threadnum'] ?? 0);

            $userRegistrationDate = my_date($mybb->settings['regdateformat'], $user_data['regdate'] ?? 0);

            if (!empty($groupPermissions['usereputationsystem']) && !empty($mybb->settings['enablereputation'])) {
                $userReputation = get_reputation($user_data['reputation'], $user_id);
            }
        }

        switch ($postStatus) {
            case $this->comments_manager::STATUS_PENDING:
                $templatesContext['style_class'] = 'unapproved_post';
                break;
            case $this->comments_manager::STATUS_DELETED:
                $templatesContext['style_class'] = 'unapproved_post deleted_post';
                break;
        }

        if (!$this->is_preview && (
                self::POST_TYPE_ENTRY && $postStatus === $this->entries_manager::STATUS_DELETED ||
                self::POST_TYPE_COMMENT && $postStatus === $this->comments_manager::STATUS_DELETED
            )) {
            if (self::POST_TYPE_ENTRY) {
                $deletedMessage = $lang->sprintf($lang->myShowcaseEntryDeletedMessage, $userName);
            } else {
                $deletedMessage = $lang->sprintf($lang->myShowcaseCommentDeletedMessage, $userName);
            }

            $templatesContext['is_deleted'] = true;

            $templatesContext['style_visibility'] = 'display: none;';
        }

        // Is the user (not moderator) logged in and have unapproved posts?
        if (!$this->is_preview && ($this->current_user_id &&
                (
                    $this->post_type === self::POST_TYPE_ENTRY && $postStatus === $this->entries_manager::STATUS_PENDING ||
                    $this->post_type === self::POST_TYPE_COMMENT && $postStatus === $this->comments_manager::STATUS_PENDING
                ) &&
                $user_id === $this->current_user_id &&
                !(
                    $this->post_type === self::POST_TYPE_ENTRY && $moderatorCanManageEntries ||
                    $this->post_type === self::POST_TYPE_COMMENT && $moderatorCanManageComments
                ))) {
            $templatesContext['is_ignored'] = true;

            $templatesContext['ignored_message'] = $lang->myshowcase_view_entry_under_moderation;

            $templatesContext['style_visibility'] = 'display: none;';
        }

        // Is this author on the ignore list of the current user? Hide this post
        if (!$this->is_preview && is_array($user_ignored_objects) &&
            isset($user_ignored_objects[$user_id]) &&
            !$templatesContext['is_deleted']) {
            $templatesContext['is_ignored'] = true;

            $templatesContext['ignored_message'] = $lang->sprintf(
                $lang->myshowcase_view_entry_currently_ignoring_user,
                $userName,
                $mybb->settings['bburl']
            );

            $templatesContext['style_visibility'] = 'display: none;';
        }

        // todo, the entry query should fetch this from the reports table
        if ($entry_data->getExtraData('reporters_list') === false) {
            $entry_data->setExtraData(
                'reporters_list',
                $this->entry_repository->getContentReports($entry_data->entry_id)
            );
        }

        if ($this->post_type === self::POST_TYPE_COMMENT) {
            if ($comment_data->getExtraData('reporters_list') === false) {
                $comment_data->setExtraData(
                    'reporters_list',
                    $this->entry_repository->getContentReports($comment_data->entry_id)
                );
            }
        }

        $templatesContext['show_purge_spammer_button'] = purgespammer_show(
            $user_data['postnum'],
            $user_data['usergroup'],
            $user_data['uid']
        );

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
        array $parser_options = [],
        bool $is_preview = false,
    ): string {
        $this->parser_options = $parser_options;

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
        array $parser_options = [],
        bool $is_preview = false,
    ): string {
        $this->parser_options = $parser_options;

        $this->post_type = self::POST_TYPE_COMMENT;

        $this->is_preview = $is_preview;

        return $this->buildPost($entry_data, $comment_data);
    }

    /**
     * @throws RuntimeError
     * @throws LoaderError
     * @throws SyntaxError
     */
    private function buildAttachments(
        Entry $entry_data,
        Collection $attachment_objects,
        array &$entry_fields,
    ): string {
        if ($attachment_objects->count() < 1) {
            return '';
        }

        global $lang;

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

            $attachment_data->has_thumbnail = !empty($attachment_data->thumbnail_name) && $this->attachments_display_thumbnails;

            // todo, maybe all attachments should be accessible from the same route, with entry_slug or post_hash optional
            if ($this->attachments_type === self::ATTACHMENT_TYPE_ENTRY || $this->attachments_type === self::ATTACHMENT_TYPE_FIELD) {
                if ($entry_data->entry_id) {
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
                } else {
                    $attachment_data->setExtraData(
                        'url',
                        $this->routing->generateUrl(
                            Routes::AttachmentViewFromPostHash,
                            [
                                'post_hash' => $entry_data->post_hash,
                                'attachment_hash' => $attachment_data->attachment_hash,
                            ],
                        )
                    );
                }

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
                                'display_thumbnail' => $this->attachments_display_thumbnails,
                                'display_full' => $this->attachments_display_full_images,
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

                if ($attachment_data->has_thumbnail && $this->attachments_display_thumbnails) {
                    $templatesContext['attachedThumbnails'][$attachmentID] = $attachment_data;
                } elseif (!$attachment_data->has_thumbnail && $this->attachments_display_full_images) {
                    $templatesContext['attachedImages'][$attachmentID] = $attachment_data;
                } else {
                    $templatesContext['attachedFiles'][$attachmentID] = $attachment_data;
                }
            } else {
                ++$unapprovedCount;
            }
        }

        $templatesContext['show_unapproved'] = $unapprovedCount > 0 && $this->user_permissions[ModeratorPermissions::CanManageAttachments];

        if ($templatesContext['show_unapproved']) {
            if ($unapprovedCount === 1) {
                $templatesContext['unapproved_message'] = $lang->myshowcase_view_attachments_unapproved_single;
            } else {
                $templatesContext['unapproved_message'] = $lang->sprintf(
                    $lang->myshowcase_view_entry_button_deleted_show,
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
        $this->post_type = $post_type;

        global $mybb, $lang, $theme;

        if (DEBUG) {
            $version = TIME_NOW;
        }

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
                    'entry_slug_custom' => $entry_data->entry_slug_custom,
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

        if ($mybb->get_input('attachment_watermark_file')) {
            $watermarkSelectedElement = 'checked="checked"';
        }

        if ($this->user_permissions['attachments_upload_quote'] === ALL_UNLIMITED_VALUE) {
            $usageQuoteNote = $lang->sprintf(
                $lang->myShowcaseAttachmentsUsageQuote,
                $lang->myShowcaseAttachmentsUsageQuoteUnlimited
            );
        } else {
            $usageQuoteNote = $lang->sprintf(
                $lang->myShowcaseAttachmentsUsageQuote,
                get_friendly_size($this->user_permissions['attachments_upload_quote'] * 1024)
            );
        }

        $totalUserUsage = $this->attachments_repository->queryAttachmentSum(
            ["user_id='{$this->current_user_id}'"]
        );

        $usageDetails = $viewMyAttachmentsLink = '';

        $template_prefix = 'Entry';

        if ($this->post_type === self::POST_TYPE_COMMENT) {
            $template_prefix = 'Comment';
        }

        if ($totalUserUsage > 0) {
            $usageDetails = $lang->sprintf(
                $lang->myShowcaseAttachmentsUsageDetails,
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
}