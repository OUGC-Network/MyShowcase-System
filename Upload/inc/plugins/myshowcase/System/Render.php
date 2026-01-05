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

namespace MyShowcase\System;

use MyBB;
use MyShowcase\Entity\Comment;
use MyShowcase\Plugin\RouterUrls;
use MyShowcase\Service\CommentManager;
use MyShowcase\Service\EntryManager;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

use function MyShowcase\Plugin\Functions\attachmentGet;
use function MyShowcase\Plugin\Functions\cacheGet;
use function MyShowcase\Plugin\Functions\fieldGetObject;
use function MyShowcase\Plugin\Functions\formatField;
use function MyShowcase\Plugin\Functions\templateGet;
use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\loadLanguage;

use const MyShowcase\Plugin\Core\CACHE_TYPE_ATTACHMENT_TYPES;
use const MyShowcase\Plugin\Core\DEBUG;
use const MyShowcase\Plugin\Core\ALL_UNLIMITED_VALUE;
use const MyShowcase\Plugin\Core\VERSION_CODE;

class Render
{
    public const POST_TYPE_ENTRY = 1;
    public const POST_TYPE_ENTRY_FIELD = 2;

    public const POST_TYPE_COMMENT = 3;

    public function __construct(
        public Showcase &$showcase,
        public string $keywords_highlight = '',
        public array $parserOptions = [],
        public array $fieldSetFieldsSearchFields = [],
        public array $urlParams = [],
        public int $page = 0,
        public int $current_page = 0,
    ) {
        global $mybb;

        if (isset($mybb->input['highlight'])) {
            $this->keywords_highlight = $mybb->get_input('highlight');

            $this->parserOptions['highlight'] = $this->keywords_highlight;
        }

        global $lang;

        loadLanguage();

        foreach ($this->showcase->fieldset_display_fields as $field_key => &$field_display_name) {
            $field_display_name = $lang->{"myShowcaseMainSort{$field_key}"} ?? ucfirst($field_key);
        }

        $this->fieldSetFieldsSearchFields = [
            'username' => $lang->myShowcaseMainSortUsername
        ];

        foreach ($this->showcase->fieldSetCache as $fieldID => $fieldDefinition) {
            $field_key = $fieldDefinition['field_key'];

            $fieldKeyUpper = ucfirst($field_key);

            $this->fieldSetFieldsSearchFields[$field_key] = $lang->{"myShowcaseMainSort{$fieldKeyUpper}"} ?? $lang->{"myshowcase_fieldset_field_{$field_key}"} ?? $fieldKeyUpper;
        }

        global $mybb;

        $this->urlParams = [];

        if ($mybb->get_input('unapproved', MyBB::INPUT_INT)) {
            $this->urlParams['unapproved'] = $mybb->get_input('unapproved', MyBB::INPUT_INT);
        }

        if (array_key_exists(
            $this->showcase->sort_field,
            $this->showcase->fieldset_display_fields
        )) {
            $this->urlParams['sort_by'] = $this->showcase->sort_field;
        }

        if ($this->showcase->search_exact) {
            $this->urlParams['exact_match'] = $this->showcase->search_exact;
        }

        if ($this->showcase->search_keywords) {
            $this->urlParams['keywords'] = $this->showcase->search_keywords;
        }

        if (in_array($this->showcase->searchField, array_keys($this->showcase->fieldSetSearchableFields))) {
            $this->urlParams['search_field'] = $this->showcase->searchField;
        }

        if ($this->showcase->sort_direction) {
            $this->urlParams['order_by'] = $this->showcase->sort_direction;
        }

        if ($mybb->get_input('page', MyBB::INPUT_INT) > 0) {
            $this->current_page = $mybb->get_input('page', MyBB::INPUT_INT);
        }

        if ($this->current_page) {
            $this->urlParams['page'] = $this->current_page;
        }

        $hookArguments = [
            'renderObject' => &$this,
        ];

        $hookArguments = hooksRun('system_render_construct_end', $hookArguments);
    }

    /**
     * @throws SyntaxError
     * @throws RuntimeError
     * @throws LoaderError
     */
    public function templateGet(string $templateName = '', array $templatesContext = []): string
    {
        global $mybb;

        return templateGet(
            $templateName,
            array_merge_recursive([
                'showcase' => [
                    'fieldset_display_fields' => $this->showcase->fieldset_display_fields,
                ],
                'config' => $this->showcase->config,
                'meta' => [],
                'current_page' => $this->current_page,
                'keywords' => $this->showcase->search_keywords,
                'search_exact' => $this->showcase->search_exact,
                'url' => [
                    'main' => $this->showcase->urlGenerate(RouterUrls::Main),
                ],
                'permissions' => $this->showcase->user_permissions,
                'errors' => empty($this->showcase->errorMessages) ? '' : inline_error(
                    $this->showcase->errorMessages
                ),
                'version' => DEBUG ? TIME_NOW : VERSION_CODE,
            ], $templatesContext),
            $this->showcase->config['custom_theme_template_prefix']
        );
    }

    /**
     * @throws SyntaxError
     * @throws RuntimeError
     * @throws LoaderError
     */
    private function buildPost(
        bool $isPreview = false,
        int $postType = self::POST_TYPE_COMMENT,
        int $commentsCounter = 0,
        ?Comment $comment_object = null,
    ): string {
        global $mybb, $lang, $theme;

        static $currentUserIgnoredUsers = null;

        if ($currentUserIgnoredUsers === null) {
            $currentUserIgnoredUsers = [];

            if ($this->showcase->current_user_id > 0 && !empty($mybb->user['ignorelist'])) {
                $currentUserIgnoredUsers = array_flip(explode(',', $mybb->user['ignorelist']));
            }
        }

        if ($postType === self::POST_TYPE_ENTRY) {
            $userID = $this->showcase->entryUserID;
        } else {
            $userID = $comment_object->user_id;

            $commentSlug = $comment_object->comment_slug;
        }

        $templatesContext = [
            'entry_data' => array_merge($this->showcase->entry_data, [
                'is_deleted' => false,
                'moderator_check' => false,
            ]),
            'comment_data' => $comment_object,
            'subject' => $this->buildEntrySubject(),
            'url' => [
                'entry' => '',
                'update' => '',
            ],
            'style_class' => '',
            'is_deleted' => false,
            'is_ignored' => false,
            'ignored_message' => '',
            'style_visibility' => '',
            'fields_objects' => $this->showcase->fieldSetCache,
            'entry_attachments' => '',
            'commentAttachments' => '',
            'class' => '',
            'show_report_button' => false,
            'show_warn_button' => false,
            'user_data' => get_user($userID) ?? [],
        ];

        $userData = &$templatesContext['user_data'];

        $hookArguments = [
            'renderObject' => &$this,
            'commentData' => &$comment_object,
            'postType' => $postType,
            'userID' => &$userID,
            'user_data' => &$userData,
        ];

        $extractVariables = [];

        $hookArguments['extractVariables'] = &$extractVariables;

        $hookArguments = hooksRun('render_build_entry_comment_start', $hookArguments);

        extract($hookArguments['extractVariables']);

        $extractVariables = [];

        $templatesContext['entry_id'] = $entryID = $this->showcase->entry_id;

        if ($postType === self::POST_TYPE_COMMENT) {
            $templatePrefix = 'pageViewComment';
        } else {
            $templatePrefix = 'pageViewEntry';
        }

        // todo, will be empty when preview a new entry/comment
        if (!empty($this->showcase->entry_data['entry_slug'])) {
            $templatesContext['url']['entry'] = $this->showcase->urlGenerate(
                RouterUrls::EntryView,
                [
                    'entry_slug' => $this->showcase->entry_data['entry_slug'],
                    'entry_slug_custom' => $this->showcase->entry_data['entry_slug_custom']
                ]
            );
        }

        if ($postType === self::POST_TYPE_COMMENT) {
            $templatesContext['comment_data.comment_slug'] = $comment_object->comment_slug;

            $templatesContext['commentMessage'] = $this->showcase->parseMessage(
                $comment_object->comment,
                $this->parserOptions
            );

            $templatesContext['commentID'] = $commentID = $comment_object->comment_id;

            if (!$isPreview) {
                $templatesContext['commentNumber'] = my_number_format($commentsCounter);

                $templatesContext['commentUrl'] = $this->showcase->urlGenerate(
                    RouterUrls::Comment,
                    ['comment_slug' => $commentSlug]
                );
            }
        } else {
            foreach ($templatesContext['fields_objects'] as $fieldID => &$fieldDefinition) {
                $fieldObject = fieldGetObject($this->showcase, $this->showcase->fieldSetCache[$fieldID]);

                if (!$fieldObject->isViewableInEntry()) {
                    unset($templatesContext['fields_objects'][$fieldID]);

                    continue;
                }

                $fieldObject->valueSet($this->showcase->entry_data[$fieldDefinition['field_key']] ?? '')
                    ->valuesBuild();

                $fieldDefinition['value'] = $fieldObject->getUserValue();

                $fieldDefinition['value_display'] = $fieldObject->getUserValueDisplay();
            }

            foreach ($templatesContext['fields_objects'] as $fieldID => &$fieldDefinition) {
                $fieldObject = fieldGetObject($this->showcase, $this->showcase->fieldSetCache[$fieldID]);

                $fieldDefinition['field_attachments'] = $fieldObject->getAttachments(
                    $templatesContext['fields_objects']
                );
            }

            if ($this->showcase->config['attachments_allow_entries'] &&
                $this->showcase->user_permissions[UserPermissions::CanViewAttachments]) {
                $templatesContext['entry_attachments'] = $this->entryBuildAttachments(
                    $this->showcase->attachmentsGetFromEntry(),
                    $templatesContext['fields_objects'],
                    $postType,
                    $commentSlug ?? ''
                );
            }
            /*
                        $templatesContext['entry_attachments'] = '';

                        if ($this->showcaseObject->config['attachments_allow_entries'] &&
                            $this->showcaseObject->user_permissions[UserPermissions::CanViewAttachments]) {
                            $templatesContext['entry_attachments'] = $this->entryBuildAttachments(
                                $templatesContext['fields_objects'],
                                $postType,
                                $commentSlug ?? ''
                            );
                        }*/
        }

        // todo, this should probably account for additional groups, but I just took it from post bit logic for now
        if (!empty($userData['uid'])) {
            $templatesContext['user_permissions'] = user_permissions($userData['uid']);
        } else {
            $templatesContext['user_permissions'] = usergroup_permissions(1);
        }

        $templatesContext['userProfileLinkPlain'] = get_profile_link($userID);

        $userName = htmlspecialchars_uni($userData['username'] ?? $lang->guest);

        $userNameFormatted = format_name(
            $userName,
            $userData['usergroup'] ?? 0,
            $userData['displaygroup'] ?? 0
        );

        $templatesContext['userProfileLink'] = build_profile_link($userNameFormatted, $userID);

        $templatesContext['user_avatar'] = $templatesContext['userStars'] = $templatesContext['userGroupImage'] = $userDetailsReputationLink = $userDetailsWarningLevel = $templatesContext['userSignature'] = '';

        if ($postType === self::POST_TYPE_ENTRY && $this->showcase->config['display_avatars_entries'] ||
            $postType === self::POST_TYPE_COMMENT && $this->showcase->config['display_avatars_comments']) {
            if (isset($userData['avatar']) && isset($mybb->user['showavatars']) && !empty($mybb->user['showavatars']) || !$this->showcase->current_user_id) {
                $templatesContext['user_avatar'] = $userAvatar = format_avatar(
                    $userData['avatar'],
                    $userData['avatardimensions'],
                    $this->showcase->maximumAvatarSize
                );
            }
        }

        if (!empty($groupPermissions['usertitle']) && empty($userData['usertitle'])) {
            $userData['usertitle'] = $groupPermissions['usertitle'] ?? '';
        } elseif (empty($groupPermissions['usertitle']) && ($userTitlesCache = $this->cacheGetUserTitles())) {
            foreach ($userTitlesCache as $postNumber => $titleinfo) {
                if ($userData['postnum'] >= $postNumber) {
                    if (empty($userData['usertitle'])) {
                        $userData['usertitle'] = $titleinfo['title'];
                    }

                    $groupPermissions['stars'] = $titleinfo['stars'];

                    $groupPermissions['starimage'] = $titleinfo['starimage'];

                    break;
                }
            }
        }

        $templatesContext['userTitle'] = htmlspecialchars_uni($userData['usertitle']);

        if ($postType === self::POST_TYPE_ENTRY && $this->showcase->config['display_stars_entries'] ||
            $postType === self::POST_TYPE_COMMENT && $this->showcase->config['display_stars_comments']) {
            if (!empty($groupPermissions['starimage']) && isset($groupPermissions['stars'])) {
                $groupStarImage = str_replace('{theme}', $theme['imgdir'], $groupPermissions['starimage']);

                $templatesContext['userStars'] .= '<br />';
            }
        }

        if ($postType === self::POST_TYPE_ENTRY && $this->showcase->config['display_group_image_entries'] ||
            $postType === self::POST_TYPE_COMMENT && $this->showcase->config['display_group_image_comments']) {
            if (!empty($groupPermissions['image'])) {
                $groupImage = str_replace(['{lang}', '{theme}'],
                    [$mybb->user['language'] ?? $mybb->settings['language'], $theme['imgdir']],
                    $groupPermissions['image']);

                $groupTitle = $groupPermissions['title'];
            }
        }

        $templatesContext['url_base'] = $urlBase;

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

        $moderatorCanManageEntries = $this->showcase->user_permissions[ModeratorPermissions::CanManageEntries];

        $moderatorCanManageComments = $this->showcase->user_permissions[ModeratorPermissions::CanManageComments];

        $userCanUpdateEntries = $userID === $this->showcase->current_user_id && $this->showcase->user_permissions[UserPermissions::CanUpdateEntries];

        $userCanSoftDeleteEntries = $userID === $this->showcase->current_user_id && $this->showcase->user_permissions[UserPermissions::CanDeleteEntries];

        $userCanUpdateComments = $userID === $this->showcase->current_user_id && $this->showcase->user_permissions[UserPermissions::CanUpdateComments];

        $userCanSoftDeleteComments = $userID === $this->showcase->current_user_id && $this->showcase->user_permissions[UserPermissions::CanDeleteComments];

        if (!$isPreview && $postType === self::POST_TYPE_ENTRY && ($moderatorCanManageEntries || $userCanUpdateEntries)) {
            $templatesContext['url']['update'] = $this->showcase->urlGenerate(
                RouterUrls::EntryUpdate,
                [
                    'entry_slug' => $this->showcase->entry_data['entry_slug'],
                    'entry_slug_custom' => $this->showcase->entry_data['entry_slug_custom'],
                ]
            );
        } elseif (!$isPreview && $postType === self::POST_TYPE_COMMENT && ($moderatorCanManageComments || $userCanUpdateComments)) {
            $templatesContext['url']['update'] = $this->showcase->urlGenerate(
                RouterUrls::CommentUpdate,
                [
                    'entry_slug' => $this->showcase->entry_data['entry_slug'],
                    'entry_slug_custom' => $this->showcase->entry_data['entry_slug_custom'],
                    'comment_slug' => $commentSlug
                ]
            );
        }

        $templatesContext['buttonWarn'] = '';

        if (!empty($mybb->settings['enablewarningsystem']) &&
            !empty($groupPermissions['canreceivewarnings']) &&
            (!empty($mybb->usergroup['canwarnusers']) || ($this->showcase->current_user_id === $userID && !empty($mybb->settings['canviewownwarning'])))) {
            if ($mybb->settings['maxwarningpoints'] < 1) {
                $mybb->settings['maxwarningpoints'] = 10;
            }

            $warningLevel = round(
                $userData['warningpoints'] / $mybb->settings['maxwarningpoints'] * 100
            );

            if ($warningLevel > 100) {
                $warningLevel = 100;
            }

            $warningLevel = get_colored_warning_level($warningLevel);

            // If we can warn them, it's not the same person, and we're in a PM or a post.
            if (!empty($mybb->usergroup['canwarnusers']) && $userID !== $this->showcase->current_user_id) {
                $templatesContext['show_warn_button'] = true;

                $warningUrl = "warnings.php?uid={$userID}";
            } else {
                $warningUrl = 'usercp.php';
            }

            if ($postType === self::POST_TYPE_ENTRY && $this->showcase->config['display_group_image_entries'] ||
                $postType === self::POST_TYPE_COMMENT && $this->showcase->config['display_group_image_comments']) {
            }
        }

        if (($postType === self::POST_TYPE_ENTRY && $this->showcase->config['display_signatures_entries'] ||
                $postType === self::POST_TYPE_COMMENT && $this->showcase->config['display_signatures_comments']) &&
            !empty($userData['username']) &&
            !empty($userData['signature']) &&
            (!$this->showcase->current_user_id || !empty($mybb->user['showsigs'])) &&
            (empty($userData['suspendsignature']) || !empty($userData['suspendsignature']) && !empty($userData['suspendsigtime']) && $userData['suspendsigtime'] < TIME_NOW) &&
            !empty($groupPermissions['canusesig']) &&
            (empty($groupPermissions['canusesigxposts']) || $groupPermissions['canusesigxposts'] > 0 && $userData['postnum'] > $groupPermissions['canusesigxposts']) &&
            !is_member($mybb->settings['hidesignatures'])) {
            $signatureParserOptions = [
                'allow_html' => !empty($mybb->settings['sightml']),
                'allow_mycode' => !empty($mybb->settings['sigmycode']),
                'allow_smilies' => !empty($mybb->settings['sigsmilies']),
                'allow_imgcode' => !empty($mybb->settings['sigimgcode']),
                'me_username' => $userData['username']
            ];

            if ($groupPermissions['signofollow']) {
                $signatureParserOptions['nofollow_on'] = true;
            }

            if ($this->showcase->current_user_id && empty($mybb->user['showimages']) || empty($mybb->settings['guestimages']) && !$this->showcase->current_user_id) {
                $signatureParserOptions['allow_imgcode'] = false;
            }

            $templatesContext['userSignature'] = $this->showcase->parseMessage(
                $userData['signature'],
                $signatureParserOptions
            );
        }

        if ($postType === self::POST_TYPE_ENTRY) {
            $templatesContext['date'] = $this->showcase->entry_data['dateline'];
        } else {
            $templatesContext['date'] = $comment_object->dateline;
        }

        if ($moderatorCanManageEntries && (
                $postType === self::POST_TYPE_ENTRY && !empty($this->showcase->entry_data['edit_user_id']) ||
                $postType === self::POST_TYPE_COMMENT && !empty($comment_object->edit_user_id)
            )) {
            $editUserData = get_user(
                $postType === self::POST_TYPE_ENTRY ? $this->showcase->entry_data['edit_user_id'] : $comment_object->edit_user_id
            );
        }

        $templatesContext['buttonEmail'] = '';

        if (empty($userData['hideemail']) && $userID !== $this->showcase->current_user_id && !empty($mybb->usergroup['cansendemail'])) {
        }

        $templatesContext['buttonPrivateMessage'] = '';

        if (!empty($mybb->settings['enablepms']) &&
            $userID !== $this->showcase->current_user_id &&
            ((!empty($userData['receivepms']) &&
                    !empty($groupPermissions['canusepms']) &&
                    !empty($mybb->usergroup['cansendpms']) &&
                    my_strpos(
                        ',' . $userData['ignorelist'] . ',',
                        ',' . $this->showcase->current_user_id . ','
                    ) === false) ||
                !empty($mybb->usergroup['canoverridepm']))) {
        }

        $templatesContext['buttonWebsite'] = '';

        if (!empty($userData['website']) &&
            !is_member($mybb->settings['hidewebsite']) &&
            !empty($groupPermissions['canchangewebsite'])) {
            $userWebsite = htmlspecialchars_uni($userData['website']);
        }

        require_once MYBB_ROOT . 'inc/functions_user.php';

        if (!$isPreview && $userID && purgespammer_show($userData['postnum'] ?? 0, $userData['usergroup'], $userID)) {
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

        $userPermissions = user_permissions($userID);

        if (!in_array(
                $this->showcase->current_user_id,
                $reportUserIDs
            ) && !empty($userPermissions['canbereported'])) {
            $templatesContext['show_report_button'] = true;
        }

        if ($postType === self::POST_TYPE_ENTRY) {
            $postStatus = (int)$this->showcase->entry_data['status'];
        } else {
            $postStatus = $comment_object->status;
        }

        $templatesContext['buttonApprove'] = $templatesContext['buttonUnpprove'] = $templatesContext['buttonRestore'] = $templatesContext['buttonSoftDelete'] = $templatesContext['buttonDelete'] = '';

        $entries_manager = new EntryManager($this->showcase);

        if (!$isPreview && $postType === self::POST_TYPE_ENTRY && ($moderatorCanManageEntries || $userCanSoftDeleteEntries)) {
            if ($moderatorCanManageEntries && $postStatus === $entries_manager::STATUS_PENDING) {
                $approveUrl = $this->showcase->urlGenerate(
                    RouterUrls::EntryApprove,
                    [
                        'entry_slug' => $this->showcase->entry_data['entry_slug'],
                        'entry_slug_custom' => $this->showcase->entry_data['entry_slug_custom'],
                    ]
                );
                /*$templatesContext['buttonApprove'] = $this->templateGet($templatePrefix . 'ButtonApprove', [
                    'approveUrl' => $approveUrl,
                    'entry_slug' => $entrySlug,
                ]);*/
            } elseif ($moderatorCanManageEntries && $postStatus === $entries_manager::STATUS_VISIBLE) {
                $unapproveUrl = $this->showcase->urlGenerate(
                    RouterUrls::EntryUnapprove,
                    [
                        'entry_slug' => $this->showcase->entry_data['entry_slug'],
                        'entry_slug_custom' => $this->showcase->entry_data['entry_slug_custom'],
                    ]
                );
                /*$templatesContext['buttonUnpprove'] = $this->templateGet($templatePrefix . 'ButtonUnapprove', [
                    'entry_slug' => $entrySlug,
                    'unapproveUrl' => $unapproveUrl,
                ]);*/
            }

            if ($moderatorCanManageEntries && $postStatus === $entries_manager::STATUS_DELETED) {
                $restoreUrl = $this->showcase->urlGenerate(
                    RouterUrls::EntryRestore,
                    [
                        'entry_slug' => $this->showcase->entry_data['entry_slug'],
                        'entry_slug_custom' => $this->showcase->entry_data['entry_slug_custom'],
                    ]
                );
                /*$templatesContext['buttonRestore'] = $this->templateGet($templatePrefix . 'ButtonRestore', [
                    'entry_slug' => $entrySlug,
                    'restoreUrl' => $restoreUrl,
                ]);*/
            } elseif ($postStatus === $entries_manager::STATUS_VISIBLE && $userCanSoftDeleteEntries) {
                $softDeleteUrl = $this->showcase->urlGenerate(
                    RouterUrls::EntrySoftDelete,
                    [
                        'entry_slug' => $this->showcase->entry_data['entry_slug'],
                        'entry_slug_custom' => $this->showcase->entry_data['entry_slug_custom'],
                    ]
                );
                /*$templatesContext['buttonSoftDelete'] = $this->templateGet($templatePrefix . 'ButtonSoftDelete', [
                    'comment_data.comment_slug' => $commentSlug,
                    'softDeleteUrl' => $softDeleteUrl,
                    'entry_slug' => $entrySlug,
                ]);*/
            }

            //only mods, original author (if allowed) or owner (if allowed) can delete comments
            if ($moderatorCanManageEntries) {
                $deleteUrl = $this->showcase->urlGenerate(
                    RouterUrls::EntryDelete,
                    [
                        'entry_slug' => $this->showcase->entry_data['entry_slug'],
                        'entry_slug_custom' => $this->showcase->entry_data['entry_slug_custom'],
                    ]
                );
                /*$templatesContext['buttonDelete'] = $this->templateGet($templatePrefix . 'ButtonDelete', [
                    'entry_slug' => $entrySlug,
                    'deleteUrl' => $deleteUrl,
                ]);*/
            }
        }

        $comments_manager = new CommentManager($this->showcase);

        if (!$isPreview && $postType === self::POST_TYPE_COMMENT && ($moderatorCanManageComments || $userCanSoftDeleteComments)) {
            if ($moderatorCanManageComments && $postStatus === $comments_manager::STATUS_PENDING) {
                $templatesContext['url_comment_approve'] =
                    $this->showcase->config['url_showcase'] . '/' . $this->showcase->urlGenerate(
                        RouterUrls::CommentView,
                        array_merge($this->showcase->urlParams, [
                            'entry_slug' => $this->showcase->entry_data['entry_slug'],
                            'entry_slug_custom' => $this->showcase->entry_data['entry_slug_custom'],
                            'comment_slug' => $commentSlug,
                            'approve' => 1
                        ])
                    );
            } elseif ($moderatorCanManageComments && $postStatus === $comments_manager::STATUS_VISIBLE) {
                $templatesContext['url_comment_unapprove'] = $this->showcase->urlGenerate(
                    RouterUrls::CommentUnapprove,
                    [
                        'entry_slug' => $this->showcase->entry_data['entry_slug'],
                        'entry_slug_custom' => $this->showcase->entry_data['entry_slug_custom'],
                        'comment_slug' => $commentSlug
                    ]
                );
            }

            if ($moderatorCanManageComments && $postStatus === $comments_manager::STATUS_DELETED) {
                $templatesContext['url_comment_restore'] = $this->showcase->urlGenerate(
                    RouterUrls::CommentRestore,
                    [
                        'entry_slug' => $this->showcase->entry_data['entry_slug'],
                        'entry_slug_custom' => $this->showcase->entry_data['entry_slug_custom'],
                        'comment_slug' => $commentSlug
                    ]
                );
            } elseif ($postStatus === $comments_manager::STATUS_VISIBLE) {
                $templatesContext['url_comment_soft_delete'] = $this->showcase->urlGenerate(
                    RouterUrls::CommentSoftDelete,
                    [
                        'entry_slug' => $this->showcase->entry_data['entry_slug'],
                        'entry_slug_custom' => $this->showcase->entry_data['entry_slug_custom'],
                        'comment_slug' => $commentSlug
                    ]
                );
            }

            //only mods, original author (if allowed) or owner (if allowed) can delete comments
            if ($moderatorCanManageComments) {
                $templatesContext['url_comment_delete'] = $this->showcase->urlGenerate(
                    RouterUrls::CommentDelete,
                    [
                        'entry_slug' => $this->showcase->entry_data['entry_slug'],
                        'entry_slug_custom' => $this->showcase->entry_data['entry_slug_custom'],
                        'comment_slug' => $commentSlug
                    ]
                );
            }
        }

        $hookArguments['extractVariables'] = &$extractVariables;

        $hookArguments = hooksRun('render_build_entry_comment_end', $hookArguments);

        extract($hookArguments['extractVariables']);

        $templatesContext['userDetails'] = '';

        if ($postType === self::POST_TYPE_ENTRY && $this->showcase->config['display_user_details_entries'] ||
            $postType === self::POST_TYPE_COMMENT && $this->showcase->config['display_user_details_comments']) {
            $userPostNumber = my_number_format($userData['postnum'] ?? 0);

            $userThreadNumber = my_number_format($userData['threadnum'] ?? 0);

            $userRegistrationDate = my_date($mybb->settings['regdateformat'], $userData['regdate'] ?? 0);

            if (!empty($groupPermissions['usereputationsystem']) && !empty($mybb->settings['enablereputation'])) {
                $userReputation = get_reputation($userData['reputation'], $userID);
            }

            if ($userID) {
            }
        }

        switch ($postStatus) {
            case $comments_manager::STATUS_PENDING:
                $templatesContext['style_class'] = 'unapproved_post';
                break;
            case $comments_manager::STATUS_DELETED:
                $templatesContext['style_class'] = 'unapproved_post deleted_post';
                break;
        }

        if (!$isPreview && (
                self::POST_TYPE_ENTRY && $postStatus === $entries_manager::STATUS_DELETED ||
                self::POST_TYPE_COMMENT && $postStatus === $comments_manager::STATUS_DELETED
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
        if (!$isPreview && ($this->showcase->current_user_id &&
                (
                    $postType === self::POST_TYPE_ENTRY && $postStatus === $entries_manager::STATUS_PENDING ||
                    $postType === self::POST_TYPE_COMMENT && $postStatus === $comments_manager::STATUS_PENDING
                ) &&
                $userID === $this->showcase->current_user_id &&
                !(
                    $postType === self::POST_TYPE_ENTRY && $moderatorCanManageEntries ||
                    $postType === self::POST_TYPE_COMMENT && $moderatorCanManageComments
                ))) {
            $templatesContext['is_ignored'] = true;

            $templatesContext['ignored_message'] = $lang->myshowcase_view_entry_under_moderation;

            $templatesContext['style_visibility'] = 'display: none;';
        }

        // Is this author on the ignore list of the current user? Hide this post
        if (!$isPreview && is_array($currentUserIgnoredUsers) &&
            $userID &&
            isset($currentUserIgnoredUsers[$userID]) &&
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
        if (isset($this->showcase->entry_data['reporters'])) {
            $templatesContext['skip_report'] = my_unserialize($this->showcase->entry_data['reporters']);

            if (is_array($templatesContext['skip_report'])) {
                $templatesContext['skip_report'][] = 0;
            } else {
                $templatesContext['skip_report'] = [];
            }
        } else {
            $templatesContext['skip_report'] = [];
        }

        $templatesContext['show_purge_spammer_button'] = purgespammer_show(
            $userData['postnum'],
            $userData['usergroup'],
            $userData['uid']
        );

        return $this->templateGet(
            $templatePrefix,
            $templatesContext,
        );
    }

    public function buildEntry(bool $isPreview = false): string
    {
        return $this->buildPost(
            $isPreview,
            self::POST_TYPE_ENTRY,
        );
    }

    /**
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError
     */
    public function buildComment(
        Comment $comment_object,
        int $commentsCounter = 0,
        bool $isPreview = false,
        bool $isCreatePage = false
    ): string {
        return $this->buildPost(
            isPreview: $isPreview,
            commentsCounter: $commentsCounter,
            comment_object: $comment_object,
        );
    }

    public function buildEntryFields(): array
    {
        global $mybb, $lang;

        $entryFieldsList = [];

        foreach ($this->showcase->fieldSetCache as $fieldID => $fieldDefinition) {
            $fieldObject = fieldGetObject($this->showcase, $fieldDefinition);

            $entryFieldsList[$fieldDefinition['field_key']] = $fieldObject->valueSet(
                $this->showcase->entry_data[$fieldDefinition['field_key']] ?? ''
            )->renderEntry();
            /*
                        _dump($fieldID, $fieldDefinition, $this->showcaseObject->fields);
                        $field_key = $fieldDefinition['field_key'];

                        $htmlType = $fieldDefinition['html_type'];

                        $fieldHeader = $lang->{'myshowcase_fieldset_field_' . $field_key} ?? $field_key;

                        //set parser options for current field

                        $entryFieldValue = $this->showcaseObject->entryData[$field_key] ?? '';

                        switch ($htmlType) {
                            case FieldHtmlTypes::TextArea:
                                if (!empty($entryFieldValue) || $this->showcaseObject->config['display_empty_fields']) {
                                    if (empty($entryFieldValue)) {
                                        $entryFieldValue = $lang->myShowcaseEntryFieldValueEmpty;
                                    } elseif ($fieldDefinition['parse'] || $this->highlightTerms) {
                                        $entryFieldValue = $this->showcaseObject->parseMessage(
                                            $entryFieldValue,
                                            $this->parserOptions,
                                        );
                                    } else {
                                        $entryFieldValue = htmlspecialchars_uni($entryFieldValue);

                                        $entryFieldValue = nl2br($entryFieldValue);
                                    }

                                    $entryFieldsList[$field_key] = $this->templateGet('pageViewDataFieldTextArea');
                                }

                                break;
                            case FieldHtmlTypes::Text:
                                //format values as requested
                                formatField((int)$fieldDefinition['format'], $entryFieldValue);

                                if (!empty($entryFieldValue) || $this->showcaseObject->config['display_empty_fields']) {
                                    if (empty($entryFieldValue)) {
                                        $entryFieldValue = $lang->myShowcaseEntryFieldValueEmpty;
                                    } elseif ($fieldDefinition['parse'] || $this->highlightTerms) {
                                        $entryFieldValue = $this->showcaseObject->parseMessage(
                                            $entryFieldValue,
                                            $this->parserOptions
                                        );
                                    } else {
                                        $entryFieldValue = htmlspecialchars_uni($entryFieldValue);
                                    }

                                    $entryFieldsList[$field_key] = $this->templateGet('pageViewDataFieldTextBox');
                                }
                                break;
                            case FieldHtmlTypes::Url:
                                if (!empty($entryFieldValue) || $this->showcaseObject->config['display_empty_fields']) {
                                    if (empty($entryFieldValue)) {
                                        $entryFieldValue = $lang->myShowcaseEntryFieldValueEmpty;
                                    } elseif ($fieldDefinition['parse']) {
                                        $entryFieldValue = postParser()->mycode_parse_url(
                                            $entryFieldValue
                                        );
                                    } else {
                                        $entryFieldValue = htmlspecialchars_uni($entryFieldValue);
                                    }

                                    $entryFieldsList[$field_key] = $this->templateGet('pageViewDataFieldUrl');
                                }
                                break;
                            case FieldHtmlTypes::Radio:
                                if (!empty($entryFieldValue) || $this->showcaseObject->config['display_empty_fields']) {
                                    if (empty($entryFieldValue)) {
                                        $entryFieldValue = $lang->myShowcaseEntryFieldValueEmpty;
                                    } else {
                                        $entryFieldValue = htmlspecialchars_uni($entryFieldValue);
                                    }

                                    $entryFieldsList[$field_key] = $this->templateGet('pageViewDataFieldRadio');
                                }
                                break;
                            case FieldHtmlTypes::CheckBox:
                                if (!empty($entryFieldValue) || $this->showcaseObject->config['display_empty_fields']) {
                                    if (empty($entryFieldValue)) {
                                        $entryFieldValue = $entryFieldValueImage = $lang->myShowcaseEntryFieldValueEmpty;
                                    } else {
                                        if ((int)$entryFieldValue === CHECK_BOX_IS_CHECKED) {
                                            $imageName = 'valid';

                                            $imageAlternativeText = $lang->myShowcaseEntryFieldValueCheckBoxYes;
                                        } else {
                                            $imageName = 'invalid';

                                            $imageAlternativeText = $lang->myShowcaseEntryFieldValueCheckBoxNo;
                                        }

                                        $entryFieldValueImage = $this->templateGet('pageViewDataFieldCheckBoxImage', [
            'imageName' => $imageName,
            ]);
                                    }

                                    $entryFieldsList[$field_key] = $this->templateGet('pageViewDataFieldCheckBox');
                                }
                                break;
                            case FieldHtmlTypes::SelectSingle:
                                break;
                            case FieldHtmlTypes::Date:
                                if (!empty($entryFieldValue) || $this->showcaseObject->config['display_empty_fields']) {
                                    if (empty($entryFieldValue)) {
                                        $entryFieldValue = $lang->myShowcaseEntryFieldValueEmpty;
                                    } else {
                                        $entryFieldValue = '';

                                        list($month, $day, $year) = array_pad(
                                            array_map('intval', explode('|', $entryFieldValue)),
                                            3,
                                            0
                                        );

                                        if ($month > 0 && $day > 0 && $year > 0) {
                                            $entryFieldValue = my_date(
                                                $mybb->settings['dateformat'],
                                                mktime(0, 0, 0, $month, $day, $year)
                                            );
                                        } else {
                                            if ($month) {
                                                $entryFieldValue .= $month;
                                            }

                                            if ($day) {
                                                $entryFieldValue .= ($entryFieldValue ? '-' : '') . $day;
                                            }

                                            if ($year) {
                                                $entryFieldValue .= ($entryFieldValue ? '-' : '') . $year;
                                            }
                                        }
                                    }

                                    $entryFieldsList[$field_key] = eval(getTemplate('pageViewDataFieldDate'));
                                }

                                break;
                        }
            */
        }

        return $entryFieldsList;
    }

    /**
     * @throws RuntimeError
     * @throws LoaderError
     * @throws SyntaxError
     */
    public function entryBuildAttachments(
        array $attachmentObjects,
        array &$entryFields,
        int $postType = self::POST_TYPE_ENTRY,
        string $commentSlug = ''
    ): string {
        if (!$attachmentObjects) {
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

        if ($postType === self::POST_TYPE_COMMENT) {
            $template_prefix = 'Comment';
        }

        if ($postType === self::POST_TYPE_ENTRY_FIELD) {
            $template_prefix .= 'Field';
        }

        foreach ($attachmentObjects as $attachmentID => $attachmentData) {
            /*$attachmentData = array_merge(
                $attachmentData,
                [
                    'file_size' => get_friendly_size($attachmentData['file_size']),
                    'is_image' => str_starts_with($attachmentData['mime_type'], 'image/'),
                    'icon' => get_attachment_icon($attachmentData['attachment_name']),
                    'has_thumbnail' => !empty($attachmentData['thumbnail_name']) && $this->showcaseObject->attachmentsDisplayThumbnails,
                    'url' => empty($this->showcaseObject->entry_data['entry_slug']) ? '' : $this->showcaseObject->urlGenerate(
                        RouterUrls::AttachmentView,
                        [
                            'entry_slug' => $this->showcaseObject->entry_data['entry_slug'],
                            'entry_slug_custom' => $this->showcaseObject->entry_data['entry_slug_custom'],
                            'attachment_hash' => $attachmentData['attachment_hash']
                        ]
                    ),
                    'url_thumbnail' => empty($this->showcaseObject->entry_data['entry_slug']) ? '' : $this->showcaseObject->urlGenerate(
                        RouterUrls::ThumbnailView,
                        [
                            'entry_slug' => $this->showcaseObject->entry_data['entry_slug'],
                            'entry_slug_custom' => $this->showcaseObject->entry_data['entry_slug_custom'],
                            'attachment_hash' => $attachmentData['attachment_hash']
                        ]
                    ),
                ]
            );*/

            if ($attachmentData['status'] or true) {
                $attachmentExtension = get_extension($attachmentData['attachment_name']);

                $isImageAttachment = in_array($attachmentExtension, ['jpeg', 'gif', 'bmp', 'png', 'jpg']);

                if (!$attachmentData['dateline']) {
                    $attachmentData['dateline'] = $this->showcase->entry_data['dateline'];
                }

                // Support for [attachment=attachment_hash] code
                $attachmentInField = false;

                foreach ($entryFields as $field_header => &$fieldDefinition) {
                    if (str_contains(
                            $fieldDefinition['value'],
                            '[attachment=' . $attachmentData['attachment_hash'] . ']'
                        ) || str_contains(
                            $fieldDefinition['value_display'],
                            '[attachment=' . $attachmentData['attachment_hash'] . ']'
                        )) {
                        $attachmentInField = true;

                        $attachmentBit = $this->templateGet('pageView' . $template_prefix . 'Attachment', [
                            'attachment_id' => $attachmentID,
                            'attachment_data' => $attachmentData,
                            'display_thumbnail' => $this->showcase->attachmentsDisplayThumbnails,
                            'display_full' => $this->showcase->attachmentsDisplayFullSizeImage,
                        ]);

                        if (str_contains(
                            $fieldDefinition['value'],
                            '[attachment=' . $attachmentData['attachment_hash'] . ']'
                        )) {
                            $fieldDefinition['value'] = preg_replace(
                                '#\[attachment=' . $attachmentData['attachment_hash'] . ']#si',
                                $attachmentBit,
                                $fieldDefinition['value']
                            );
                        }

                        if (str_contains(
                            $fieldDefinition['value_display'],
                            '[attachment=' . $attachmentData['attachment_hash'] . ']'
                        )) {
                            $fieldDefinition['value'] = preg_replace(
                                '#\[attachment=' . $attachmentData['attachment_hash'] . ']#si',
                                $attachmentBit,
                                $fieldDefinition['value']
                            );
                        }
                    }
                }

                if ($attachmentInField) {
                    continue;
                }

                if ($attachmentData['has_thumbnail'] && $this->showcase->attachmentsDisplayThumbnails) {
                    $templatesContext['attachedThumbnails'][$attachmentID] = $attachmentData;
                } elseif (!$attachmentData['has_thumbnail'] && $this->showcase->attachmentsDisplayFullSizeImage) {
                    $templatesContext['attachedImages'][$attachmentID] = $attachmentData;
                } else {
                    $templatesContext['attachedFiles'][$attachmentID] = $attachmentData;
                }
            } else {
                ++$unapprovedCount;
            }
        }

        $templatesContext['show_unapproved'] = $unapprovedCount > 0 && $this->showcase->user_permissions[ModeratorPermissions::CanManageAttachments];

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
            return $this->templateGet('pageView' . $template_prefix . 'Attachments', $templatesContext);
        }

        return '';
    }

    public function cacheGetUserTitles(): array
    {
        static $titlesCache = null;

        if ($titlesCache === null) {
            global $cache;

            $titlesCache = [];

            foreach ((array)$cache->read('usertitles') as $userTitle) {
                if (!empty($userTitle)) {
                    $titlesCache[(int)$userTitle['posts']] = $userTitle;
                }
            }
        }

        return $titlesCache;
    }

    public function buildAttachmentsUpload(bool $isEditPage, int $post_type = self::POST_TYPE_ENTRY): string
    {
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
            cacheGet(CACHE_TYPE_ATTACHMENT_TYPES)[$this->showcase->showcase_id] ?? []
        );

        $myDropzoneSettingAllowedMimeFileTypes = implode(',', $myDropzoneSettingAllowedMimeFileTypes);

        $entrySlug = $this->showcase->entry_data['entry_slug'];

        if ($isEditPage) {
            $createUpdateUrl = $this->showcase->urlGenerate(
                RouterUrls::EntryUpdate,
                [
                    'entry_slug' => $entrySlug,
                    'entry_slug_custom' => $this->showcase->entry_data['entry_slug_custom'],
                ],
                $this->showcase->urlParams
            );
        } else {
            $createUpdateUrl = $this->showcase->urlGenerate(
                RouterUrls::EntryCreate,
                $this->showcase->urlParams
            );
        }

        $watermarkSelectedElement = '';

        if ($mybb->get_input('attachment_watermark_file')) {
            $watermarkSelectedElement = 'checked="checked"';
        }

        if ($this->showcase->user_permissions['attachments_upload_quote'] === ALL_UNLIMITED_VALUE) {
            $usageQuoteNote = $lang->sprintf(
                $lang->myShowcaseAttachmentsUsageQuote,
                $lang->myShowcaseAttachmentsUsageQuoteUnlimited
            );
        } else {
            $usageQuoteNote = $lang->sprintf(
                $lang->myShowcaseAttachmentsUsageQuote,
                get_friendly_size($this->showcase->user_permissions['attachments_upload_quote'] * 1024)
            );
        }

        $totalUserUsage = (int)(attachmentGet(
            [
                "showcase_id='{$this->showcase->showcase_id}'",
                "user_id='{$this->showcase->current_user_id}'"
            ],
            ['SUM(file_size) AS total_user_usage'],
            ['limit' => 1]
        )['total_user_usage'] ?? 0);

        $usageDetails = $viewMyAttachmentsLink = '';

        $template_prefix = 'Entry';

        if ($post_type === self::POST_TYPE_COMMENT) {
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

    public function buildEntrySubject(): string
    {
        global $mybb, $lang;

        $entrySubject = [];

        foreach ($this->showcase->fieldSetCache as $fieldID => $fieldDefinition) {
            if (!$fieldDefinition['enable_subject']) {
                continue;
            }

            $field_key = $fieldDefinition['field_key'];

            $htmlType = $fieldDefinition['html_type'];

            $entryFieldText = $this->showcase->entry_data[$field_key] ?? '';

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
                $entryFieldText = $this->showcase->parseMessage($entryFieldText);
            }

            if (!empty($entryFieldText)) {
                $entrySubject[] = $entryFieldText;
            }
        }

        $entrySubject = implode(' ', $entrySubject);

        if (!$entrySubject) {
            $entrySubject = str_replace(
                '{username}',
                $this->showcase->entry_data['username'],
                $lang->myshowcase_viewing_user
            );
        }

        return $entrySubject;
    }

    public function buildCommentsFormEditor(string &$editorCodeButtons, string &$editorSmilesInserter): void
    {
        if ($this->showcase->config['comments_build_editor']) {
            $this->buildEditor($editorCodeButtons, $editorSmilesInserter);
        }
    }

    public function buildEditor(
        string &$editorCodeButtons,
        string &$editorSmilesInserter,
        string $editorID = 'comment'
    ): void {
        global $mybb;

        $currentUserID = (int)$mybb->user['uid'];

        if (!empty($mybb->settings['bbcodeinserter']) &&
            $this->showcase->config['parser_allow_mycode'] &&
            (!$currentUserID || !empty($mybb->user['showcodebuttons']))) {
            $editorCodeButtons = build_mycode_inserter(
                $editorID,
                $this->showcase->config['parser_allow_smiles']
            );

            if ($this->showcase->config['parser_allow_smiles']) {
                $editorSmilesInserter = build_clickable_smilies();
            }
        }
    }

}