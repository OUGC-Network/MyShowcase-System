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

use MyBB\Stopwatch\Stopwatch;
use MyShowcase\Plugin\RouterUrls;
use Twig\Environment;

use function MyBB\app;
use function MyShowcase\Plugin\Functions\attachmentGet;
use function MyShowcase\Plugin\Functions\cacheGet;
use function MyShowcase\Plugin\Functions\fieldGetObject;
use function MyShowcase\Plugin\Functions\formatField;
use function MyShowcase\Plugin\Functions\getTemplate;
use function MyShowcase\Plugin\Functions\getTemplateTwig;
use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\loadLanguage;
use function MyShowcase\Plugin\Functions\templateGetCachedName;
use function MyShowcase\SimpleRouter\url;

use const MyShowcase\Plugin\Core\CACHE_TYPE_ATTACHMENT_TYPES;
use const MyShowcase\Plugin\Core\DEBUG;
use const MyShowcase\Plugin\Core\ALL_UNLIMITED_VALUE;
use const MyShowcase\Plugin\Core\ATTACHMENT_THUMBNAIL_SMALL;
use const MyShowcase\Plugin\Core\COMMENT_STATUS_PENDING_APPROVAL;
use const MyShowcase\Plugin\Core\COMMENT_STATUS_SOFT_DELETED;
use const MyShowcase\Plugin\Core\COMMENT_STATUS_VISIBLE;
use const MyShowcase\Plugin\Core\ENTRY_STATUS_PENDING_APPROVAL;
use const MyShowcase\Plugin\Core\ENTRY_STATUS_SOFT_DELETED;
use const MyShowcase\Plugin\Core\ENTRY_STATUS_VISIBLE;

class Render
{
    public const POST_TYPE_ENTRY = 1;

    public const POST_TYPE_COMMENT = 2;

    public function __construct(
        public Showcase &$showcaseObject,
        public string $highlightTerms = '',
        public string $searchKeyWords = '',
        public int $searchExactMatch = 0,
        public array $parserOptions = [],
        public array $fieldSetFieldsSearchFields = [],
        public array $urlParams = [],
        public int $page = 0,
        public int $pageCurrent = 0,
    ) {
        global $mybb;

        if (isset($mybb->input['highlight'])) {
            $this->highlightTerms = $mybb->get_input('highlight');

            $this->parserOptions['highlight'] = $this->highlightTerms;
        }

        if (isset($mybb->input['keywords'])) {
            $this->searchKeyWords = $mybb->get_input('keywords');
        }

        if (!empty($mybb->input['exact_match'])) {
            $this->searchExactMatch = 1;
        }

        global $lang;

        loadLanguage();

        foreach ($this->showcaseObject->fieldSetFieldsDisplayFields as $fieldKey => &$fieldDisplayName) {
            $fieldDisplayName = $lang->{"myShowcaseMainSort{$fieldKey}"} ?? ucfirst($fieldKey);
        }

        $this->fieldSetFieldsSearchFields = [
            'username' => $lang->myShowcaseMainSortUsername
        ];

        foreach ($this->showcaseObject->fieldSetCache as $fieldID => $fieldData) {
            $fieldKey = $fieldData['field_key'];

            $fieldKeyUpper = ucfirst($fieldKey);

            $this->fieldSetFieldsSearchFields[$fieldKey] = $lang->{"myShowcaseMainSort{$fieldKeyUpper}"} ?? $lang->{"myshowcase_field_{$fieldKey}"} ?? $fieldKeyUpper;
        }

        global $mybb;

        $this->urlParams = [];

        if ($mybb->get_input('unapproved', MyBB::INPUT_INT)) {
            $this->urlParams['unapproved'] = $mybb->get_input('unapproved', MyBB::INPUT_INT);
        }

        if (array_key_exists($this->showcaseObject->sortByField, $this->showcaseObject->fieldSetFieldsDisplayFields)) {
            $this->urlParams['sort_by'] = $this->showcaseObject->sortByField;
        }

        if ($this->searchExactMatch) {
            $this->urlParams['exact_match'] = $this->searchExactMatch;
        }

        if ($this->searchKeyWords) {
            $this->urlParams['keywords'] = $this->searchKeyWords;
        }

        if (in_array($this->showcaseObject->searchField, array_keys($this->showcaseObject->fieldSetSearchableFields))) {
            $this->urlParams['search_field'] = $this->showcaseObject->searchField;
        }

        if ($this->showcaseObject->orderBy) {
            $this->urlParams['order_by'] = $this->showcaseObject->orderBy;
        }

        if ($mybb->get_input('page', MyBB::INPUT_INT) > 0) {
            $this->pageCurrent = $mybb->get_input('page', MyBB::INPUT_INT);
        }

        if ($this->pageCurrent) {
            $this->urlParams['page'] = $this->pageCurrent;
        }

        $hookArguments = [
            'renderObject' => &$this,
        ];

        $hookArguments = hooksRun('system_render_construct_end', $hookArguments);
    }

    public function templateGet(string $templateName = '', bool $enableHTMLComments = true): string
    {
        return getTemplate(
            $templateName,
            $enableHTMLComments,
            trim($this->showcaseObject->config['custom_theme_template_prefix'])
        );
    }

    public function templateGetTwig(string $templateName = '', array $context = []): string
    {
        $stopwatchPeriod = app(Stopwatch::class)->start($templateName, 'core.view.template');

        /** @var Environment $twig */
        $twig = app(Environment::class);

        $result = $twig->createTemplate(
            getTemplateTwig($templateName)
        )->render($context);

        $stopwatchPeriod->stop();

        return $result;
    }

    public function templateGetCacheStatus(string $templateName): string
    {
        return templateGetCachedName(
            $templateName,
            trim($this->showcaseObject->config['custom_theme_template_prefix'])
        );
    }

    private function buildPost(
        string $alternativeBackground,
        bool $isPreview = false,
        int $postType = self::POST_TYPE_COMMENT,
        int $commentsCounter = 0,
        array $commentData = [],
    ): string {
        global $mybb, $lang, $theme;

        $currentUserID = (int)$mybb->user['uid'];

        static $currentUserIgnoredUsers = null;

        if ($currentUserIgnoredUsers === null) {
            $currentUserIgnoredUsers = [];

            if ($currentUserID > 0 && !empty($mybb->user['ignorelist'])) {
                $currentUserIgnoredUsers = array_flip(explode(',', $mybb->user['ignorelist']));
            }
        }

        if ($postType === self::POST_TYPE_ENTRY) {
            $userID = $this->showcaseObject->entryUserID;
        } else {
            $userID = (int)$commentData['user_id'];

            $commentSlug = $commentData['comment_slug'];
        }

        $templatesContext = [
            'entrySubject' => $this->buildEntrySubject(),
        ];


        $templatesContext['userData'] = $userData = get_user($userID);

        // user may be deleted/etc
        if (empty($userData)) {
            $templatesContext['userData'] = $userData = [];
        }

        $hookArguments = [
            'renderObject' => &$this,
            'commentData' => &$commentData,
            'alternativeBackground' => $alternativeBackground,
            'postType' => $postType,
            'userID' => &$userID,
            'userData' => &$userData,
        ];

        $extractVariables = [];

        $hookArguments['extractVariables'] = &$extractVariables;

        $hookArguments = hooksRun('render_build_entry_comment_start', $hookArguments);

        extract($hookArguments['extractVariables']);

        $extractVariables = [];

        $templatesContext['entryID'] = $entryID = $this->showcaseObject->entryID;

        if ($postType === self::POST_TYPE_COMMENT) {
            $templatePrefix = 'pageViewCommentsComment';
        } else {
            $templatePrefix = 'pageViewEntry';
        }

        $templatesContext['entrySlug'] = $this->showcaseObject->entryData['entry_slug'];

        $templatesContext['entryUrl'] = url(
            RouterUrls::EntryView,
            [
                'entry_slug' => $templatesContext['entrySlug'],
                'entry_slug_custom' => $this->showcaseObject->entryData['entry_slug_custom']
            ]
        )->getRelativeUrl();

        if ($postType === self::POST_TYPE_COMMENT) {
            $templatesContext['commentSlug'] = $commentData['comment_slug'];

            $templatesContext['commentMessage'] = $this->showcaseObject->parseMessage(
                $commentData['comment'],
                $this->parserOptions
            );

            $templatesContext['commentID'] = $commentID = (int)$commentData['comment_id'];

            $commentUrl = '';

            if (!$isPreview) {
                $templatesContext['commentNumber'] = my_number_format($commentsCounter);

                $templatesContext['commentUrl'] = url(
                    RouterUrls::Comment,
                    ['comment_slug' => $commentSlug]
                )->getRelativeUrl();

                $commentUrl = url(RouterUrls::Comment, ['comment_slug' => $commentSlug]
                )->getRelativeUrl();

                $templatesContext['commentUrl'] = $this->templateGetTwig($templatePrefix . 'Url', $templatesContext);
            }
        } else {
            $entryFields = $this->buildEntryFields();

            $templatesContext['commentsNumber'] = my_number_format($this->showcaseObject->entryData['comments']);

            $templatesContext['entryUrl'] = $this->templateGetTwig($templatePrefix . 'Url', $templatesContext);

            $templatesContext['entryAttachments'] = '';

            if ($this->showcaseObject->config['attachments_allow_entries'] &&
                $this->showcaseObject->userPermissions[UserPermissions::CanViewAttachments]) {
                $templatesContext['entryAttachments'] = $this->entryBuildAttachments(
                    $entryFields,
                    $postType,
                    $commentSlug ?? ''
                );
            }

            $templatesContext['entryFields'] = implode('', $entryFields);
        }

        // todo, this should probably account for additional groups, but I just took it from post bit logic for now
        if (!empty($userData['usergroup'])) {
            $groupPermissions = usergroup_permissions($userData['usergroup']);
        } else {
            $groupPermissions = usergroup_permissions(1);
        }

        $templatesContext['userProfileLinkPlain'] = get_profile_link($userID);

        $userName = htmlspecialchars_uni($userData['username'] ?? $lang->guest);

        $userNameFormatted = format_name(
            $userName,
            $userData['usergroup'] ?? 0,
            $userData['displaygroup'] ?? 0
        );

        $templatesContext['userProfileLink'] = build_profile_link($userNameFormatted, $userID);

        $templatesContext['userAvatar'] = $templatesContext['userStars'] = $templatesContext['userGroupImage'] = $userDetailsReputationLink = $userDetailsWarningLevel = $templatesContext['userSignature'] = '';

        if ($postType === self::POST_TYPE_ENTRY && $this->showcaseObject->config['display_avatars_entries'] ||
            $postType === self::POST_TYPE_COMMENT && $this->showcaseObject->config['display_avatars_comments']) {
            if (isset($userData['avatar']) && isset($mybb->user['showavatars']) && !empty($mybb->user['showavatars']) || !$currentUserID) {
                $templatesContext['userAvatar'] = $userAvatar = format_avatar(
                    $userData['avatar'],
                    $userData['avatardimensions'],
                    $this->showcaseObject->maximumAvatarSize
                );
                /*
                                $templatesContext['userAvatar'] = $this->templateGetTwig($templatePrefix . 'UserAvatar', [
                                    'userProfileLinkPlain' => $templatesContext['userProfileLinkPlain'],
                                    'userAvatar' => $userAvatar,
                                    'userData' => $userData,
                                ]);*/
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

        if ($postType === self::POST_TYPE_ENTRY && $this->showcaseObject->config['display_stars_entries'] ||
            $postType === self::POST_TYPE_COMMENT && $this->showcaseObject->config['display_stars_comments']) {
            if (!empty($groupPermissions['starimage']) && isset($groupPermissions['stars'])) {
                $groupStarImage = str_replace('{theme}', $theme['imgdir'], $groupPermissions['starimage']);

                for ($i = 0; $i < $groupPermissions['stars']; ++$i) {
                    $templatesContext['userStars'] = $this->templateGetTwig('UserStar', [
                        'groupStarImage' => $groupStarImage,
                    ]);
                }

                $templatesContext['userStars'] .= '<br />';
            }
        }

        if ($postType === self::POST_TYPE_ENTRY && $this->showcaseObject->config['display_group_image_entries'] ||
            $postType === self::POST_TYPE_COMMENT && $this->showcaseObject->config['display_group_image_comments']) {
            if (!empty($groupPermissions['image'])) {
                $groupImage = str_replace(['{lang}', '{theme}'],
                    [$mybb->user['language'] ?? $mybb->settings['language'], $theme['imgdir']],
                    $groupPermissions['image']);

                $groupTitle = $groupPermissions['title'];

                $templatesContext['userGroupImage'] = $this->templateGetTwig('UserGroupImage', [
                    'groupImage' => $groupImage,
                    'groupTitle' => $groupTitle,
                ]);
            }
        }

        $templatesContext['userOnlineStatus'] = '';

        if (isset($userData['lastactive'])) {
            if ($userData['lastactive'] > TIME_NOW - $mybb->settings['wolcutoff'] &&
                (empty($userData['invisible']) || !empty($mybb->usergroup['canviewwolinvis'])) &&
                (int)$userData['lastvisit'] !== (int)$userData['lastactive']) {
                $templatesContext['userOnlineStatus'] = $this->templateGetTwig('UserOnlineStatusOnline', [
                    'baseUrl' => $baseUrl,
                ]);
            } elseif (!empty($userData['away']) && !empty($mybb->settings['allowaway'])) {
                $templatesContext['userOnlineStatus'] = $this->templateGetTwig('UserOnlineStatusAway', [
                    'userProfileLinkPlain' => $userProfileLinkPlain,
                ]);
            } else {
                $templatesContext['userOnlineStatus'] = $this->templateGetTwig('UserOnlineStatusOffline');
            }
        }

        $moderatorCanManageEntries = $this->showcaseObject->userPermissions[ModeratorPermissions::CanManageEntries];

        $moderatorCanManageComments = $this->showcaseObject->userPermissions[ModeratorPermissions::CanManageComments];

        $userCanUpdateEntries = $userID === $currentUserID && $this->showcaseObject->userPermissions[UserPermissions::CanUpdateEntries];

        $userCanSoftDeleteEntries = $userID === $currentUserID && $this->showcaseObject->userPermissions[UserPermissions::CanDeleteEntries];

        $userCanUpdateComments = $userID === $currentUserID && $this->showcaseObject->userPermissions[UserPermissions::CanUpdateComments];

        $userCanSoftDeleteComments = $userID === $currentUserID && $this->showcaseObject->userPermissions[UserPermissions::CanDeleteComments];

        $templatesContext['buttonEdit'] = '';

        if (!$isPreview && $postType === self::POST_TYPE_ENTRY && ($moderatorCanManageEntries || $userCanUpdateEntries)) {
            $editUrl = url(
                RouterUrls::EntryUpdate,
                [
                    'entry_slug' => $templatesContext['entrySlug'],
                    'entry_slug_custom' => $this->showcaseObject->entryData['entry_slug_custom'],
                ]
            )->getRelativeUrl();

            $templatesContext['buttonEdit'] = $this->templateGetTwig($templatePrefix . 'ButtonEdit', [
                'editUrl' => $editUrl,
                'entrySlug' => $templatesContext['entrySlug'],
            ]);
        } elseif (!$isPreview && $postType === self::POST_TYPE_COMMENT && ($moderatorCanManageComments || $userCanUpdateComments)) {
            $editUrl = url(
                RouterUrls::CommentUpdate,
                [
                    'entry_slug' => $templatesContext['entrySlug'],
                    'entry_slug_custom' => $this->showcaseObject->entryData['entry_slug_custom'],
                    'comment_slug' => $commentSlug
                ]
            )->getRelativeUrl();

            $templatesContext['buttonEdit'] = $this->templateGetTwig($templatePrefix . 'ButtonEdit', [
                'editUrl' => $editUrl,
                'commentSlug' => $commentSlug,
            ]);
        }

        $templatesContext['buttonWarn'] = '';

        if (!empty($mybb->settings['enablewarningsystem']) &&
            !empty($groupPermissions['canreceivewarnings']) &&
            (!empty($mybb->usergroup['canwarnusers']) || ($currentUserID === $userID && !empty($mybb->settings['canviewownwarning'])))) {
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
            if (!empty($mybb->usergroup['canwarnusers']) && $userID !== $currentUserID) {
                $templatesContext['buttonWarn'] = $this->templateGetTwig($templatePrefix . 'ButtonWarn', [
                    'baseUrl' => $this->showcaseObject->urlBase,
                    'userID' => $userID,
                    'showcaseID' => $this->showcaseObject->showcase_id,
                    'entrySlug' => $templatesContext['entrySlug'],
                    'commentSlug' => $commentSlug,
                ]);

                $warningUrl = "warnings.php?uid={$userID}";
            } else {
                $warningUrl = 'usercp.php';
            }

            if ($postType === self::POST_TYPE_ENTRY && $this->showcaseObject->config['display_group_image_entries'] ||
                $postType === self::POST_TYPE_COMMENT && $this->showcaseObject->config['display_group_image_comments']) {
                $userDetailsWarningLevel = $this->templateGetTwig($templatePrefix . 'UserWarningLevel', [
                    'warningUrl' => $warningUrl,
                    'warningLevel' => $warningLevel,
                ]);
            }
        }

        if (($postType === self::POST_TYPE_ENTRY && $this->showcaseObject->config['display_signatures_entries'] ||
                $postType === self::POST_TYPE_COMMENT && $this->showcaseObject->config['display_signatures_comments']) &&
            !empty($userData['username']) &&
            !empty($userData['signature']) &&
            (!$currentUserID || !empty($mybb->user['showsigs'])) &&
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

            if ($currentUserID && empty($mybb->user['showimages']) || empty($mybb->settings['guestimages']) && !$currentUserID) {
                $signatureParserOptions['allow_imgcode'] = false;
            }

            $templatesContext['userSignature'] = $this->templateGetTwig($templatePrefix . 'UserSignature', [
                'userSignature' => $this->showcaseObject->parseMessage(
                    $userData['signature'],
                    $signatureParserOptions
                ),
                'signatureParserOptions' => $signatureParserOptions,
                'config' => $this->showcaseObject->config,
            ]);
        }

        if ($postType === self::POST_TYPE_ENTRY) {
            $templatesContext['date'] = $this->showcaseObject->entryData['dateline'];
        } else {
            $templatesContext['date'] = $commentData['dateline'];
        }

        if ($moderatorCanManageEntries && (
                $postType === self::POST_TYPE_ENTRY && !empty($this->showcaseObject->entryData['edit_user_id']) ||
                $postType === self::POST_TYPE_COMMENT && !empty($commentData['edit_user_id'])
            )) {
            $editUserData = get_user(
                $postType === self::POST_TYPE_ENTRY ? $this->showcaseObject->entryData['edit_user_id'] : $commentData['edit_user_id']
            );

            $templatesContext['editedBy'] = $this->templateGetTwig(
                $templatePrefix . 'EditedBy',
                [
                    'editUserProfileLink' => build_profile_link(
                        $editUserData['username'],
                        $editUserData['uid']
                    ),
                    'entrySlug' => $entrySlug,
                    'commentSlug' => $commentSlug,
                    'myShowcaseEntryEditedBy' => $myShowcaseEntryEditedBy, // todo ?
                    'myShowcaseCommentEditedBy' => $myShowcaseCommentEditedBy, // todo ?
                ]
            );
        }

        $templatesContext['buttonEmail'] = '';

        if (empty($userData['hideemail']) && $userID !== $currentUserID && !empty($mybb->usergroup['cansendemail'])) {
            $templatesContext['buttonEmail'] = $this->templateGetTwig(
                $templatePrefix . 'ButtonEmail',
                [
                    'baseUrl' => $this->showcaseObject->urlBase,
                    'userID' => $userID,
                ]
            );
        }

        $templatesContext['buttonPrivateMessage'] = '';

        if (!empty($mybb->settings['enablepms']) &&
            $userID !== $currentUserID &&
            ((!empty($userData['receivepms']) &&
                    !empty($groupPermissions['canusepms']) &&
                    !empty($mybb->usergroup['cansendpms']) &&
                    my_strpos(
                        ',' . $userData['ignorelist'] . ',',
                        ',' . $currentUserID . ','
                    ) === false) ||
                !empty($mybb->usergroup['canoverridepm']))) {
            $templatesContext['buttonPrivateMessage'] = $this->templateGetTwig(
                $templatePrefix . 'ButtonPrivateMessage',
                [
                    'baseUrl' => $this->showcaseObject->urlBase,
                    'userID' => $userID,
                ]
            );
        }

        $templatesContext['buttonWebsite'] = '';

        if (!empty($userData['website']) &&
            !is_member($mybb->settings['hidewebsite']) &&
            !empty($groupPermissions['canchangewebsite'])) {
            $userWebsite = htmlspecialchars_uni($userData['website']);

            $templatesContext['buttonWebsite'] = $this->templateGetTwig($templatePrefix . 'ButtonWebsite', [
                'userWebsite' => $userWebsite,
            ]);
        }

        $templatesContext['buttonPurgeSpammer'] = '';

        require_once MYBB_ROOT . 'inc/functions_user.php';

        if (!$isPreview && $userID && purgespammer_show($userData['postnum'] ?? 0, $userData['usergroup'], $userID)) {
            $templatesContext['buttonPurgeSpammer'] = $this->templateGetTwig($templatePrefix . 'ButtonPurgeSpammer', [
                'baseUrl' => $this->showcaseObject->urlBase,
                'userID' => $userID,
            ]);
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

        $templatesContext['buttonReport'] = '';

        if ($postType === self::POST_TYPE_ENTRY) {
            if (!in_array($currentUserID, $reportUserIDs) && !empty($userPermissions['canbereported'])) {
                $templatesContext['buttonReport'] = $this->templateGetTwig($templatePrefix . 'ButtonReport', [
                    'entrySlug' => $entrySlug,
                    'commentSlug' => $commentSlug,
                    'showcaseID' => $showcaseID,
                ]);
            }

            $postStatus = (int)$this->showcaseObject->entryData['status'];
        } else {
            $postStatus = (int)$commentData['status'];
        }

        $templatesContext['buttonApprove'] = $templatesContext['buttonUnpprove'] = $templatesContext['buttonRestore'] = $templatesContext['buttonSoftDelete'] = $templatesContext['buttonDelete'] = '';

        if (!$isPreview && $postType === self::POST_TYPE_ENTRY && ($moderatorCanManageEntries || $userCanSoftDeleteEntries)) {
            if ($moderatorCanManageEntries && $postStatus === ENTRY_STATUS_PENDING_APPROVAL) {
                $approveUrl = url(
                    RouterUrls::EntryApprove,
                    [
                        'entry_slug' => $templatesContext['entrySlug'],
                        'entry_slug_custom' => $this->showcaseObject->entryData['entry_slug_custom'],
                    ]
                )->getRelativeUrl();

                $templatesContext['buttonApprove'] = $this->templateGetTwig($templatePrefix . 'ButtonApprove', [
                    'approveUrl' => $approveUrl,
                    'entrySlug' => $entrySlug,
                ]);
            } elseif ($moderatorCanManageEntries && $postStatus === ENTRY_STATUS_VISIBLE) {
                $unapproveUrl = url(
                    RouterUrls::EntryUnapprove,
                    [
                        'entry_slug' => $templatesContext['entrySlug'],
                        'entry_slug_custom' => $this->showcaseObject->entryData['entry_slug_custom'],
                    ]
                )->getRelativeUrl();

                $templatesContext['buttonUnpprove'] = $this->templateGetTwig($templatePrefix . 'ButtonUnapprove', [
                    'entrySlug' => $entrySlug,
                    'unapproveUrl' => $unapproveUrl,
                ]);
            }

            if ($moderatorCanManageEntries && $postStatus === ENTRY_STATUS_SOFT_DELETED) {
                $restoreUrl = url(
                    RouterUrls::EntryRestore,
                    [
                        'entry_slug' => $templatesContext['entrySlug'],
                        'entry_slug_custom' => $this->showcaseObject->entryData['entry_slug_custom'],
                    ]
                )->getRelativeUrl();

                $templatesContext['buttonRestore'] = $this->templateGetTwig($templatePrefix . 'ButtonRestore', [
                    'entrySlug' => $entrySlug,
                    'restoreUrl' => $restoreUrl,
                ]);
            } elseif ($postStatus === ENTRY_STATUS_VISIBLE && $userCanSoftDeleteEntries) {
                $softDeleteUrl = url(
                    RouterUrls::EntrySoftDelete,
                    [
                        'entry_slug' => $templatesContext['entrySlug'],
                        'entry_slug_custom' => $this->showcaseObject->entryData['entry_slug_custom'],
                    ]
                )->getRelativeUrl();

                $templatesContext['buttonSoftDelete'] = $this->templateGetTwig($templatePrefix . 'ButtonSoftDelete', [
                    'commentSlug' => $commentSlug,
                    'softDeleteUrl' => $softDeleteUrl,
                    'entrySlug' => $entrySlug,
                ]);
            }

            //only mods, original author (if allowed) or owner (if allowed) can delete comments
            if ($moderatorCanManageEntries) {
                $deleteUrl = url(
                    RouterUrls::EntryDelete,
                    [
                        'entry_slug' => $templatesContext['entrySlug'],
                        'entry_slug_custom' => $this->showcaseObject->entryData['entry_slug_custom'],
                    ]
                )->getRelativeUrl();

                $templatesContext['buttonDelete'] = $this->templateGetTwig($templatePrefix . 'ButtonDelete', [
                    'entrySlug' => $entrySlug,
                    'deleteUrl' => $deleteUrl,
                ]);
            }
        }

        if (!$isPreview && $postType === self::POST_TYPE_COMMENT && ($moderatorCanManageComments || $userCanSoftDeleteComments)) {
            if ($moderatorCanManageComments && $postStatus === COMMENT_STATUS_PENDING_APPROVAL) {
                $approveUrl = $this->showcaseObject->urlGetCommentApprove(
                    $templatesContext['entrySlug'],
                    $this->showcaseObject->entryData['entry_slug_custom'],
                    $commentSlug
                );

                $templatesContext['buttonApprove'] = $this->templateGetTwig($templatePrefix . 'ButtonApprove', [
                    'approveUrl' => $approveUrl,
                    'deleteUrl' => $deleteUrl,
                    'commentSlug' => $commentSlug,
                ]);
            } elseif ($moderatorCanManageComments && $postStatus === COMMENT_STATUS_VISIBLE) {
                $unapproveUrl = url(
                    RouterUrls::CommentUnapprove,
                    [
                        'entry_slug' => $templatesContext['entrySlug'],
                        'entry_slug_custom' => $this->showcaseObject->entryData['entry_slug_custom'],
                        'comment_slug' => $commentSlug
                    ]
                )->getRelativeUrl();

                $templatesContext['buttonUnpprove'] = $this->templateGetTwig($templatePrefix . 'ButtonUnapprove', [
                    'commentSlug' => $commentSlug,
                    'unapproveUrl' => $unapproveUrl,
                ]);
            }

            if ($moderatorCanManageComments && $postStatus === COMMENT_STATUS_SOFT_DELETED) {
                $restoreUrl = url(
                    RouterUrls::CommentRestore,
                    [
                        'entry_slug' => $templatesContext['entrySlug'],
                        'entry_slug_custom' => $this->showcaseObject->entryData['entry_slug_custom'],
                        'comment_slug' => $commentSlug
                    ]
                )->getRelativeUrl();

                $templatesContext['buttonRestore'] = $this->templateGetTwig($templatePrefix . 'ButtonRestore', [
                    'commentSlug' => $commentSlug,
                    'restoreUrl' => $restoreUrl,
                ]);
            } elseif ($postStatus === COMMENT_STATUS_VISIBLE) {
                $softDeleteUrl = url(
                    RouterUrls::CommentSoftDelete,
                    [
                        'entry_slug' => $templatesContext['entrySlug'],
                        'entry_slug_custom' => $this->showcaseObject->entryData['entry_slug_custom'],
                        'comment_slug' => $commentSlug
                    ]
                )->getRelativeUrl();

                $templatesContext['buttonSoftDelete'] = $this->templateGetTwig($templatePrefix . 'ButtonSoftDelete', [
                    'commentSlug' => $commentSlug,
                    'softDeleteUrl' => $softDeleteUrl,
                    'entrySlug' => $entrySlug,
                ]);
            }

            //only mods, original author (if allowed) or owner (if allowed) can delete comments
            if ($moderatorCanManageComments) {
                $deleteUrl = url(
                    RouterUrls::CommentDelete,
                    [
                        'entry_slug' => $templatesContext['entrySlug'],
                        'entry_slug_custom' => $this->showcaseObject->entryData['entry_slug_custom'],
                        'comment_slug' => $commentSlug
                    ]
                )->getRelativeUrl();

                $templatesContext['buttonDelete'] = $this->templateGetTwig($templatePrefix . 'ButtonDelete', [
                    'commentSlug' => $commentSlug,
                    'deleteUrl' => $deleteUrl,
                ]);
            }
        }

        $hookArguments['extractVariables'] = &$extractVariables;

        $hookArguments = hooksRun('render_build_entry_comment_end', $hookArguments);

        extract($hookArguments['extractVariables']);

        $templatesContext['userDetails'] = '';

        if ($postType === self::POST_TYPE_ENTRY && $this->showcaseObject->config['display_user_details_entries'] ||
            $postType === self::POST_TYPE_COMMENT && $this->showcaseObject->config['display_user_details_comments']) {
            $userPostNumber = my_number_format($userData['postnum'] ?? 0);

            $userThreadNumber = my_number_format($userData['threadnum'] ?? 0);

            $userRegistrationDate = my_date($mybb->settings['regdateformat'], $userData['regdate'] ?? 0);

            if (!empty($groupPermissions['usereputationsystem']) && !empty($mybb->settings['enablereputation'])) {
                $userReputation = get_reputation($userData['reputation'], $userID);

                $userDetailsReputationLink = $this->templateGetTwig($templatePrefix . 'UserReputation', [
                    'userReputation' => $userReputation,
                ]);
            }

            if ($userID) {
                $templatesContext['userDetails'] = $this->templateGetTwig($templatePrefix . 'UserDetails', [
                    'userPostNumber' => $userPostNumber,
                    'userThreadNumber' => $userThreadNumber,
                    'userRegistrationDate' => $userRegistrationDate,
                    'userDetailsReputationLink' => $userDetailsReputationLink,
                    'userDetailsWarningLevel' => $userDetailsWarningLevel,
                ]);
            }
        }

        $templatesContext['styleClass'] = '';

        switch ($postStatus) {
            case COMMENT_STATUS_PENDING_APPROVAL:
                $templatesContext['styleClass'] = 'unapproved_post';
                break;
            case COMMENT_STATUS_SOFT_DELETED:
                $templatesContext['styleClass'] = 'unapproved_post deleted_post';
                break;
        }

        $templatesContext['deletedBit'] = $templatesContext['ignoredBit'] = $templatesContext['postVisibility'] = '';

        if (!$isPreview && (
                self::POST_TYPE_ENTRY && $postStatus === ENTRY_STATUS_SOFT_DELETED ||
                self::POST_TYPE_COMMENT && $postStatus === COMMENT_STATUS_SOFT_DELETED
            )) {
            if (self::POST_TYPE_ENTRY) {
                $deletedMessage = $lang->sprintf($lang->myShowcaseEntryDeletedMessage, $userName);
            } else {
                $deletedMessage = $lang->sprintf($lang->myShowcaseCommentDeletedMessage, $userName);
            }

            $templatesContext['deletedBit'] = $this->templateGetTwig($templatePrefix . 'DeletedBit', [
                'userProfileLink' => $userProfileLink,
                'entrySlug' => $entrySlug,
                'commentSlug' => $commentSlug,
            ]);

            $templatesContext['postVisibility'] = 'display: none;';
        }

        // Is the user (not moderator) logged in and have unapproved posts?
        if (!$isPreview && ($currentUserID &&
                (
                    $postType === self::POST_TYPE_ENTRY && $postStatus === ENTRY_STATUS_PENDING_APPROVAL ||
                    $postType === self::POST_TYPE_COMMENT && $postStatus === COMMENT_STATUS_PENDING_APPROVAL
                ) &&
                $userID === $currentUserID &&
                !(
                    $postType === self::POST_TYPE_ENTRY && $moderatorCanManageEntries ||
                    $postType === self::POST_TYPE_COMMENT && $moderatorCanManageComments
                ))) {
            $ignoredMessage = $lang->sprintf($lang->postbit_post_under_moderation, $userName);

            $templatesContext['ignoredBit'] = $this->templateGetTwig($templatePrefix . 'IgnoredBit', [
                'baseUrl' => $this->showcaseObject->urlBase,
                'entryUrl' => $entryUrl,
                'commentSlug' => $commentSlug,
                'ignoredMessage' => $ignoredMessage,
            ]);

            $templatesContext['postVisibility'] = 'display: none;';
        }

        // Is this author on the ignore list of the current user? Hide this post
        if (!$isPreview && is_array($currentUserIgnoredUsers) &&
            $userID &&
            isset($currentUserIgnoredUsers[$userID]) &&
            empty($templatesContext['deletedBit'])) {
            $ignoredMessage = $lang->sprintf(
                $lang->myShowcaseEntryIgnoredUserMessage,
                $userName,
                $mybb->settings['bburl']
            );

            $templatesContext['ignoredBit'] = $this->templateGetTwig($templatePrefix . 'IgnoredBit', [
                'baseUrl' => $this->showcaseObject->urlBase,
                'entryUrl' => $entryUrl,
                'commentSlug' => $commentSlug,
                'ignoredMessage' => $ignoredMessage,
            ]);

            $templatesContext['postVisibility'] = 'display: none;';
        }

        return $this->templateGetTwig($templatePrefix, $templatesContext);
    }

    public function buildEntry(bool $isPreview = false): string
    {
        return $this->buildPost(
            alt_trow(true),
            $isPreview,
            self::POST_TYPE_ENTRY,
        );
    }

    public function buildComment(
        array $commentData,
        string $alternativeBackground,
        int $commentsCounter = 0,
        bool $isPreview = false,
        bool $isCreatePage = false
    ): string {
        if ($isCreatePage) {
            global $mybb;

            $currentUserID = (int)$mybb->user['uid'];

            $commentData = array_merge([
                'user_id' => $currentUserID,
                'comment_slug' => '',
                'comment_id' => 0,
                'dateline' => TIME_NOW,
                'status' => COMMENT_STATUS_VISIBLE,
            ], $commentData);
        }

        return $this->buildPost(
            $alternativeBackground,
            isPreview: $isPreview,
            commentsCounter: $commentsCounter,
            commentData: $commentData,
        );
    }

    public function buildEntryFields(): array
    {
        global $mybb, $lang;

        $entryFieldsList = [];

        foreach ($this->showcaseObject->fieldSetCache as $fieldID => $fieldData) {
            $fieldObject = fieldGetObject($this->showcaseObject, $fieldData);

            $entryFieldsList[$fieldData['field_key']] = $fieldObject->setUserValue(
                $this->showcaseObject->entryData[$fieldData['field_key']] ?? ''
            )->renderEntry();
            /*
                        _dump($fieldID, $fieldData, $this->showcaseObject->fields);
                        $fieldKey = $fieldData['field_key'];

                        $htmlType = $fieldData['html_type'];

                        $fieldHeader = $lang->{'myshowcase_field_' . $fieldKey} ?? $fieldKey;

                        //set parser options for current field

                        $entryFieldValue = $this->showcaseObject->entryData[$fieldKey] ?? '';

                        switch ($htmlType) {
                            case FieldHtmlTypes::TextArea:
                                if (!empty($entryFieldValue) || $this->showcaseObject->config['display_empty_fields']) {
                                    if (empty($entryFieldValue)) {
                                        $entryFieldValue = $lang->myShowcaseEntryFieldValueEmpty;
                                    } elseif ($fieldData['parse'] || $this->highlightTerms) {
                                        $entryFieldValue = $this->showcaseObject->parseMessage(
                                            $entryFieldValue,
                                            $this->parserOptions,
                                        );
                                    } else {
                                        $entryFieldValue = htmlspecialchars_uni($entryFieldValue);

                                        $entryFieldValue = nl2br($entryFieldValue);
                                    }

                                    $entryFieldsList[$fieldKey] = eval($this->templateGet('pageViewDataFieldTextArea'));
                                }

                                break;
                            case FieldHtmlTypes::Text:
                                //format values as requested
                                formatField((int)$fieldData['format'], $entryFieldValue);

                                if (!empty($entryFieldValue) || $this->showcaseObject->config['display_empty_fields']) {
                                    if (empty($entryFieldValue)) {
                                        $entryFieldValue = $lang->myShowcaseEntryFieldValueEmpty;
                                    } elseif ($fieldData['parse'] || $this->highlightTerms) {
                                        $entryFieldValue = $this->showcaseObject->parseMessage(
                                            $entryFieldValue,
                                            $this->parserOptions
                                        );
                                    } else {
                                        $entryFieldValue = htmlspecialchars_uni($entryFieldValue);
                                    }

                                    $entryFieldsList[$fieldKey] = eval($this->templateGet('pageViewDataFieldTextBox'));
                                }
                                break;
                            case FieldHtmlTypes::Url:
                                if (!empty($entryFieldValue) || $this->showcaseObject->config['display_empty_fields']) {
                                    if (empty($entryFieldValue)) {
                                        $entryFieldValue = $lang->myShowcaseEntryFieldValueEmpty;
                                    } elseif ($fieldData['parse']) {
                                        $entryFieldValue = postParser()->mycode_parse_url(
                                            $entryFieldValue
                                        );
                                    } else {
                                        $entryFieldValue = htmlspecialchars_uni($entryFieldValue);
                                    }

                                    $entryFieldsList[$fieldKey] = eval($this->templateGet('pageViewDataFieldUrl'));
                                }
                                break;
                            case FieldHtmlTypes::Radio:
                                if (!empty($entryFieldValue) || $this->showcaseObject->config['display_empty_fields']) {
                                    if (empty($entryFieldValue)) {
                                        $entryFieldValue = $lang->myShowcaseEntryFieldValueEmpty;
                                    } else {
                                        $entryFieldValue = htmlspecialchars_uni($entryFieldValue);
                                    }

                                    $entryFieldsList[$fieldKey] = eval($this->templateGet('pageViewDataFieldRadio'));
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

                                        $entryFieldValueImage = eval($this->templateGet('pageViewDataFieldCheckBoxImage', [
            'imageName' => $imageName,
            ]));
                                    }

                                    $entryFieldsList[$fieldKey] = eval($this->templateGet('pageViewDataFieldCheckBox'));
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

                                    $entryFieldsList[$fieldKey] = eval(getTemplate('pageViewDataFieldDate'));
                                }

                                break;
                        }
            */
        }

        return $entryFieldsList;
    }

    public function entryBuildAttachments(
        array &$entryFields,
        int $postType = self::POST_TYPE_ENTRY,
        string $commentSlug = ''
    ): string {
        $attachmentObjects = attachmentGet(
            ["entry_id='{$this->showcaseObject->entryID}'", "showcase_id='{$this->showcaseObject->showcase_id}'"],
            [
                'status',
                'attachment_name',
                'file_size',
                'downloads',
                'dateline',
                'thumbnail_name',
                'thumbnail_dimensions',
                'attachment_hash'
            ]
        );

        if (!$attachmentObjects) {
            return '';
        }

        $entrySlug = $this->showcaseObject->entryData['entry_slug'];

        $entryID = $this->showcaseObject->entryID;

        global $mybb, $theme, $templates, $lang;

        $unapprovedCount = 0;

        $thumbnailsCount = 0;

        $attachedFiles = $attachedThumbnails = $attachedImages = '';

        $templatesContextAttachment = [];

        foreach ($attachmentObjects as $attachmentID => $attachmentData) {
            $templatesContextAttachment['attachmentUrl'] = url(
                RouterUrls::AttachmentView,
                [
                    'entry_slug' => $entrySlug,
                    'entry_slug_custom' => $this->showcaseObject->entryData['entry_slug_custom'],
                    'attachment_hash' => $attachmentData['attachment_hash']
                ]
            )->getRelativeUrl();

            if (empty($attachmentData['thumbnail_name'])) {
                $templatesContextAttachment['attachmentThumbnailUrl'] = $templatesContextAttachment['attachmentUrl'];
            } else {
                $templatesContextAttachment['attachmentThumbnailUrl'] = url(
                    RouterUrls::ThumbnailView,
                    [
                        'entry_slug' => $entrySlug,
                        'entry_slug_custom' => $this->showcaseObject->entryData['entry_slug_custom'],
                        'attachment_hash' => $attachmentData['attachment_hash']
                    ]
                )->getRelativeUrl();
            }

            if ($attachmentData['status']) { // There is an attachment thats status!
                $templatesContextAttachment['attachmentFileName'] = htmlspecialchars_uni(
                    $attachmentData['attachment_name']
                );

                $templatesContextAttachment['attachmentFileSize'] = get_friendly_size($attachmentData['file_size']);

                $attachmentExtension = get_extension($attachmentData['attachment_name']);

                $isImageAttachment = in_array($attachmentExtension, ['jpeg', 'gif', 'bmp', 'png', 'jpg']);

                $templatesContextAttachment['attachmentIcon'] = get_attachment_icon($attachmentExtension);

                $templatesContextAttachment['attachmentDownloads'] = my_number_format($attachmentData['downloads']);

                if (!$attachmentData['dateline']) {
                    $attachmentData['dateline'] = $this->showcaseObject->entryData['dateline'];
                }

                $templatesContextAttachment['attachmentDate'] = my_date('normal', $attachmentData['dateline']);

                // Support for [attachment=attachment_hash] code
                $attachmentInField = false;

                foreach ($entryFields as $fieldKey => &$fieldValue) {
                    if (str_contains(
                        $fieldValue,
                        '[attachment=' . $attachmentData['attachment_hash'] . ']'
                    )) {
                        $attachmentInField = true;

                        // Show as thumbnail IF image is big && thumbnail exists && setting=='thumb'
                        // Show as full size image IF setting=='fullsize' || (image is small && permissions allow)
                        // Show as download for all other cases
                        if ((int)$attachmentData['thumbnail_dimensions'] !== ATTACHMENT_THUMBNAIL_SMALL &&
                            $attachmentData['thumbnail_name'] !== '' &&
                            $this->showcaseObject->attachmentsDisplayThumbnails) {
                            $attachmentBit = $this->templateGetTwig(
                                'pageViewEntryAttachmentsThumbnailsItem',
                                $templatesContextAttachment
                            );
                        } elseif ((((int)$attachmentData['thumbnail_dimensions'] === ATTACHMENT_THUMBNAIL_SMALL &&
                                    $this->showcaseObject->userPermissions[UserPermissions::CanDownloadAttachments]) ||
                                $this->showcaseObject->attachmentsDisplayFullSizeImage) &&
                            $isImageAttachment) {
                            $attachmentBit = eval($this->templateGet('pageViewEntryAttachmentsImagesItem'));
                        } else {
                            $attachmentBit = $this->templateGetTwig(
                                'pageViewEntryAttachmentsFilesItem',
                                $templatesContextAttachment
                            );
                        }

                        $fieldValue = preg_replace(
                            '#\[attachment=' . $attachmentData['attachment_hash'] . ']#si',
                            $attachmentBit,
                            $fieldValue
                        );
                    }
                }

                if (!$attachmentInField &&
                    (int)$attachmentData['thumbnail_dimensions'] !== ATTACHMENT_THUMBNAIL_SMALL &&
                    $attachmentData['thumbnail_name'] !== '' &&
                    $this->showcaseObject->attachmentsDisplayThumbnails) {
                    // Show as thumbnail IF image is big && thumbnail exists && setting=='thumb'
                    // Show as full size image IF setting=='fullsize' || (image is small && permissions allow)
                    // Show as download for all other cases
                    $attachedThumbnails .= $this->templateGetTwig(
                        'pageViewEntryAttachmentsThumbnailsItem',
                        $templatesContextAttachment
                    );

                    if ($thumbnailsCount === $this->showcaseObject->config['attachments_grouping']) {
                        $attachedThumbnails .= '<br />';

                        $thumbnailsCount = 0;
                    }

                    ++$thumbnailsCount;
                } elseif (!$attachmentInField && (((int)$attachmentData['thumbnail_dimensions'] === ATTACHMENT_THUMBNAIL_SMALL &&
                            $this->showcaseObject->userPermissions[UserPermissions::CanDownloadAttachments]) ||
                        $this->showcaseObject->attachmentsDisplayFullSizeImage) &&
                    $isImageAttachment) {
                    if ($this->showcaseObject->userPermissions[UserPermissions::CanDownloadAttachments]) {
                        $attachedImages .= eval($this->templateGet('pageViewEntryAttachmentsImagesItem'));
                    } else {
                        $attachedThumbnails .= $this->templateGetTwig(
                            'pageViewEntryAttachmentsThumbnailsItem',
                            $templatesContextAttachment
                        );

                        if ($thumbnailsCount === $this->showcaseObject->config['attachments_grouping']) {
                            $attachedThumbnails .= '<br />';

                            $thumbnailsCount = 0;
                        }

                        ++$thumbnailsCount;
                    }
                } elseif (!$attachmentInField) {
                    $attachedFiles .= $this->templateGetTwig(
                        'pageViewEntryAttachmentsFilesItem',
                        $templatesContextAttachment
                    );
                }
            } else {
                ++$unapprovedCount;
            }
        }

        if ($unapprovedCount > 0 && $this->showcaseObject->userPermissions[ModeratorPermissions::CanManageAttachments]) {
            if ($unapprovedCount === 1) {
                $unapprovedMessage = $lang->postbit_unapproved_attachment;
            } else {
                $unapprovedMessage = $lang->sprintf(
                    $lang->postbit_unapproved_attachments,
                    $unapprovedCount
                );
            }

            $attachedFiles .= $this->templateGetTwig('pageViewEntryAttachmentsFilesItemUnapproved', [
                'unapprovedMessage' => $unapprovedMessage,
            ]);

            $attachedFiles .= eval($this->templateGet('pageViewEntryAttachmentsFilesItemUnapproved'));
        }

        $templatesContext = [];

        if ($attachedFiles) {
            $attachedFiles = $this->templateGetTwig('pageViewEntryAttachmentsFiles', [
                'attachedFiles' => $attachedFiles,
            ]);

            $templatesContext['attachedFiles'] = $attachedFiles;
        }

        if ($attachedThumbnails) {
            $attachedThumbnails = eval($this->templateGet('pageViewEntryAttachmentsThumbnails'));

            $templatesContext['attachedThumbnails'] = $attachedThumbnails;
        }

        if ($attachedImages) {
            $attachedImages = eval($this->templateGet('pageViewEntryAttachmentsImages'));

            $templatesContext['attachedImages'] = $attachedImages;
        }

        if ($attachedThumbnails || $attachedImages || $attachedFiles) {
            return $this->templateGetTwig('pageViewEntryAttachments', $templatesContext);
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

    public function buildAttachmentsUpload(bool $isEditPage): string
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
            cacheGet(CACHE_TYPE_ATTACHMENT_TYPES)[$this->showcaseObject->showcase_id]
        );

        $myDropzoneSettingAllowedMimeFileTypes = implode(',', $myDropzoneSettingAllowedMimeFileTypes);

        $entrySlug = $this->showcaseObject->entryData['entry_slug'];

        if ($isEditPage) {
            $createUpdateUrl = url(
                RouterUrls::EntryUpdate,
                [
                    'entry_slug' => $entrySlug,
                    'entry_slug_custom' => $this->showcaseObject->entryData['entry_slug_custom'],
                ],
                $this->showcaseObject->urlParams
            )->getRelativeUrl();
        } else {
            $createUpdateUrl = url(
                RouterUrls::EntryCreate,
                getParams: $this->showcaseObject->urlParams
            )->getRelativeUrl();
        }

        $watermarkSelectedElement = '';

        if ($mybb->get_input('attachment_watermark_file')) {
            $watermarkSelectedElement = 'checked="checked"';
        }

        $currentUserID = (int)$mybb->user['uid'];

        if ($this->showcaseObject->userPermissions['attachments_upload_quote'] === ALL_UNLIMITED_VALUE) {
            $usageQuoteNote = $lang->sprintf(
                $lang->myShowcaseAttachmentsUsageQuote,
                $lang->myShowcaseAttachmentsUsageQuoteUnlimited
            );
        } else {
            $usageQuoteNote = $lang->sprintf(
                $lang->myShowcaseAttachmentsUsageQuote,
                get_friendly_size($this->showcaseObject->userPermissions['attachments_upload_quote'] * 1024)
            );
        }

        $totalUserUsage = (int)(attachmentGet(
            ["showcase_id='{$this->showcaseObject->showcase_id}'", "user_id='{$currentUserID}'"],
            ['SUM(file_size) AS total_user_usage'],
            ['limit' => 1]
        )['total_user_usage'] ?? 0);

        $usageDetails = $viewMyAttachmentsLink = '';

        if ($totalUserUsage > 0) {
            $usageDetails = $lang->sprintf(
                $lang->myShowcaseAttachmentsUsageDetails,
                get_friendly_size($totalUserUsage)
            );

            $viewMyAttachmentsLink = $this->templateGetTwig('pageEntryCommentCreateUpdateAttachmentsBoxViewLink');
        }

        return $this->templateGetTwig('pageEntryCommentCreateUpdateAttachmentsBox', [
            'createUpdateUrl' => $createUpdateUrl,
            'myDropzoneSettingMaximumFileSize' => $myDropzoneSettingMaximumFileSize,
            'myDropzoneSettingAllowedMimeFileTypes' => $myDropzoneSettingAllowedMimeFileTypes,
            'usageQuoteNote' => $usageQuoteNote,
            'usageDetails' => $usageDetails,
            'viewMyAttachmentsLink' => $viewMyAttachmentsLink,
            'watermarkSelectedElement' => $watermarkSelectedElement,
        ]);
    }

    public function buildEntrySubject(): string
    {
        global $mybb, $lang;

        $entrySubject = [];

        foreach ($this->showcaseObject->fieldSetCache as $fieldID => $fieldData) {
            if (!$fieldData['enable_subject']) {
                continue;
            }

            $fieldKey = $fieldData['field_key'];

            $htmlType = $fieldData['html_type'];

            $entryFieldText = $this->showcaseObject->entryData[$fieldKey] ?? '';

            if (!$fieldData['parse']) {
                // todo, remove this legacy updating the database and updating the format field to TINYINT
                formatField((int)$fieldData['format'], $entryFieldText);

                if ($htmlType === FieldHtmlTypes::Date) {
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
                }
            } else {
                $entryFieldText = $this->showcaseObject->parseMessage($entryFieldText);
            }

            if (!empty($entryFieldText)) {
                $entrySubject[] = $entryFieldText;
            }
        }

        $entrySubject = implode(' ', $entrySubject);

        if (!$entrySubject) {
            $entrySubject = str_replace(
                '{username}',
                $this->showcaseObject->entryData['username'],
                $lang->myshowcase_viewing_user
            );
        }

        return $entrySubject;
    }

    public function buildCommentsFormEditor(string &$editorCodeButtons, string &$editorSmilesInserter): void
    {
        if ($this->showcaseObject->config['comments_build_editor']) {
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
            $this->showcaseObject->config['parser_allow_mycode'] &&
            (!$currentUserID || !empty($mybb->user['showcodebuttons']))) {
            $editorCodeButtons = build_mycode_inserter(
                $editorID,
                $this->showcaseObject->config['parser_allow_smiles']
            );

            if ($this->showcaseObject->config['parser_allow_smiles']) {
                $editorSmilesInserter = build_clickable_smilies();
            }
        }
    }

}