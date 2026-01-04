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

use JetBrains\PhpStorm\NoReturn;
use MyShowcase\Plugin\RouterUrls;
use myshowcase\src\Service\CommentManager;
use MyShowcase\System\ModeratorPermissions;
use MyShowcase\System\UserPermissions;

use function MyShowcase\Plugin\Functions\dataHandlerGetObject;
use function MyShowcase\Plugin\Functions\entryGet;
use function MyShowcase\Plugin\Functions\generateUUIDv4;
use function MyShowcase\Plugin\Functions\hooksRun;

use function redirect;

use const MyShowcase\ROOT;
use const MyShowcase\Plugin\Core\ENTRY_STATUS_SOFT_DELETED;
use const MyShowcase\Plugin\Core\HTTP_CODE_PERMANENT_REDIRECT;
use const MyShowcase\Plugin\Core\ENTRY_STATUS_PENDING_APPROVAL;
use const MyShowcase\Plugin\Core\DATA_HANDLER_METHOD_UPDATE;

class Comments extends Base
{
    public const NO_PERMISSION = 1;

    public const INVALID_COMMENT = 2;

    public const HAS_PERMISSION = 3;

    public const STATUS_PENDING_APPROVAL = 0;

    public const STATUS_VISIBLE = 1;

    public const STATUS_SOFT_DELETE = 2;

    public const STATUS_DELETE = 3;

    public int $entryID = 0;

    public array $entryData = [];

    public int $commentID = 0;

    public array $commentData = [];
    private int $entry_id = 0;

    public function verifyPermission(
        string $commentSlug,
        ?string $entrySlug = null,
        ?string $entrySlugCustom = null,
        array $commentFields = []
    ): int {
        global $db;

        if (!$this->showcaseObject->config['comments_allow'] ||
            !(
                $this->showcaseObject->user_permissions[UserPermissions::CanViewEntries] ||
                $this->showcaseObject->user_permissions[ModeratorPermissions::CanManageEntries]
            ) ||
            !(
                $this->showcaseObject->user_permissions[UserPermissions::CanViewComments] ||
                $this->showcaseObject->user_permissions[ModeratorPermissions::CanManageComments]
            )) {
            return self::NO_PERMISSION;
        }

        global $mybb;

        $current_user_id = (int)$mybb->user['uid'];

        $comments_manager = new CommentManager($this->showcaseObject);

        $commentData = $comments_manager->queryComment(
            ["comment_slug='{$db->escape_string($commentSlug)}'"],
            array_merge(['entry_id', 'status', 'user_id'], $commentFields)
        );

        if (empty($commentData)) {
            return self::INVALID_COMMENT;
        }

        $commentStatus = (int)$commentData['status'];

        $commentUserID = (int)$commentData['user_id'];

        if ($commentStatus === self::STATUS_PENDING_APPROVAL && $current_user_id !== $commentUserID ||
            $commentStatus === self::STATUS_SOFT_DELETE && !$this->showcaseObject->user_permissions[ModeratorPermissions::CanManageComments]) {
            return self::INVALID_COMMENT;
        }

        $entryID = (int)$commentData['entry_id'];

        $entryData = entryGet(
            $this->showcaseObject->showcase_id,
            [
                $entrySlug !== null ? "entry_slug='{$db->escape_string($entrySlug)}'" : "entry_id='{$entryID}'",
                $entrySlugCustom !== null ? "entry_slug_custom='{$db->escape_string($entrySlugCustom)}'" : "entry_id='{$entryID}'"
            ],
            ['entry_slug', 'entry_slug_custom', 'status', 'user_id'],
            ['limit' => 1],
        );

        if (empty($entryData)) {
            return self::INVALID_COMMENT;
        }

        $entryStatus = (int)$entryData['status'];

        $entryUserID = (int)$entryData['user_id'];

        if ($entryStatus === ENTRY_STATUS_PENDING_APPROVAL && $current_user_id !== $entryUserID ||
            $entryStatus === ENTRY_STATUS_SOFT_DELETED && !$this->showcaseObject->user_permissions[ModeratorPermissions::CanManageEntries]) {
            return self::INVALID_COMMENT;
        }

        $returnValue = self::HAS_PERMISSION;

        $hookArguments = [
            'commentsController' => &$this,
            'returnValue' => &$returnValue,
        ];

        $this->entry_id = $entryID;

        $this->entryData = $entryData;

        $this->commentID = (int)$commentData['comment_id'];

        $this->commentData = $commentData;

        $hookArguments = hooksRun('controller_comments_verify_permissions_end', $hookArguments);

        return $returnValue;
    }

    #[NoReturn] public function redirect(string $commentSlug): void
    {
        switch ($this->verifyPermission($commentSlug)) {
            case self::NO_PERMISSION:
                error_no_permission();

                break;
            case self::INVALID_COMMENT:
                global $lang;

                error($lang->myShowcaseReportErrorInvalidComment);
        }

        $hookArguments = [
            'commentsController' => &$this,
        ];

        $commentUrl = $this->showcaseObject->urlGetEntryComment(
            $this->entryData['entry_slug'],
            $this->entryData['entry_slug_custom'],
            $commentSlug
        );

        $hookArguments = hooksRun('controller_comments_redirect_end', $hookArguments);

        redirect($commentUrl, HTTP_CODE_PERMANENT_REDIRECT);

        exit;
    }

    #[NoReturn] public function view(string $entrySlug, string $entrySlugCustom, string $commentSlug): void
    {
        switch ($this->verifyPermission($commentSlug, $entrySlug, $entrySlugCustom, ['dateline'])) {
            case self::NO_PERMISSION:
                error_no_permission();

                break;
            case self::INVALID_COMMENT:
                global $lang;

                error($lang->myShowcaseReportErrorInvalidComment);
        }

        global $mybb;

        $current_user_id = (int)$mybb->user['uid'];

        $statusVisible = self::STATUS_VISIBLE;

        $statusPendingApproval = self::STATUS_PENDING_APPROVAL;

        $statusSoftDeleted = self::STATUS_SOFT_DELETE;

        $whereClauses = [
            "entry_id='{$this->entry_id}'",
            "showcase_id='{$this->showcaseObject->showcase_id}'",
            $this->showcaseObject->whereClauseStatusComment()
        ];

        $hookArguments = [
            'commentsController' => &$this,
            'whereClauses' => &$whereClauses,
        ];

        $commentTimeStamp = (int)$this->commentData['dateline'];

        $comments_manager = new CommentManager($this->showcaseObject);

        $totalCommentsBeforeMainComment = $comments_manager->queryCommentCount(
            array_merge($whereClauses, ["dateline<='{$commentTimeStamp}'"])
        );

        if (($totalCommentsBeforeMainComment % $this->showcaseObject->config['comments_per_page']) == 0) {
            $this->renderObject->current_page = $totalCommentsBeforeMainComment / $this->showcaseObject->config['comments_per_page'];
        } else {
            $this->renderObject->current_page = (int)($totalCommentsBeforeMainComment / $this->showcaseObject->config['comments_per_page']) + 1;
        }

        $hookArguments = hooksRun('controller_comments_view_end', $hookArguments);

        require_once ROOT . '/Controllers/Entries.php';

        (new Entries())->viewEntry($entrySlug, $entrySlugCustom, $this->renderObject->current_page);
    }

    public function updateEntryCommentsCount(): void
    {
        $statusVisible = self::STATUS_VISIBLE;

        $whereClauses = ["entry_id='{$this->showcaseObject->entry_id}'", "status='{$statusVisible}'"];

        $hookArguments = [
            'commentsController' => &$this,
            'whereClauses' => &$whereClauses,
        ];

        $hookArguments = hooksRun('controller_comments_update_entry_comments_count_start', $hookArguments);

        $comments_manager = new CommentManager($this->showcaseObject);

        $this->showcaseObject->dataUpdate(['comments' => $comments_manager->queryCommentCount($whereClauses)]);
    }

    #[NoReturn] public function create(
        string $entry_slug,
        string $entry_slug_custom,
        bool $isUpdate = false,
        string $commentSlug = ''
    ): string {
        global $mybb, $lang;

        if ($isUpdate) {
            if (!(
                $this->showcaseObject->user_permissions[UserPermissions::CanUpdateComments] ||
                $this->showcaseObject->user_permissions[ModeratorPermissions::CanManageEntries]
            )) {
                error_no_permission();
            }

            switch ($this->verifyPermission(
                $commentSlug,
                $entry_slug,
                $entry_slug_custom,
                ['comment_slug', 'dateline', 'comment']
            )) {
                case self::NO_PERMISSION:
                    error_no_permission();

                    break;
                case self::INVALID_COMMENT:
                    error($lang->myShowcaseReportErrorInvalidComment);

                    break;
            }
        } elseif (empty($mybb->input['post_hash'])) {
            $mybb->input['post_hash'] = generateUUIDv4();
        } elseif (!$this->showcaseObject->user_permissions[UserPermissions::CanCreateEntries]) {
            error_no_permission();
        }

        $current_user_id = (int)$mybb->user['uid'];

        $mybb->input = array_merge($this->commentData, $mybb->input);

        $hookArguments = [
            'commentsController' => &$this,
            'isUpdate' => $isUpdate,
        ];

        $extractVariables = [];

        $hookArguments['extractVariables'] = &$extractVariables;

        $hookArguments = hooksRun('controller_comments_create_update_start', $hookArguments);

        $this->showcaseObject->setEntry($entry_slug, true);

        $commentPreview = '';

        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));

            $isPreview = isset($mybb->input['preview']);

            if ($isUpdate) {
                $dataHandler = dataHandlerGetObject($this->showcaseObject, DATA_HANDLER_METHOD_UPDATE);
            } else {
                $dataHandler = dataHandlerGetObject($this->showcaseObject);
            }

            $insertData = [
                'comment' => $mybb->get_input('comment'),
                'status' => self::STATUS_VISIBLE,
            ];

            if (!$isUpdate) {
                $insertData['user_id'] = $current_user_id;

                $insertData['post_hash'] = $mybb->get_input('post_hash');

                $insertData['ipaddress'] = $mybb->session->packedip;

                $insertData['dateline'] = TIME_NOW;
            }

            if (!$this->showcaseObject->user_permissions[ModeratorPermissions::CanManageComments]) {
                if ($isUpdate && (
                        $this->showcaseObject->config['moderate_comments_update'] ||
                        $this->showcaseObject->user_permissions[UserPermissions::ModerateCommentsUpdate]
                    )) {
                    $insertData['status'] = ENTRY_STATUS_PENDING_APPROVAL;
                } elseif (!$isUpdate && $this->showcaseObject->config['moderate_comments_create'] && (
                        $this->showcaseObject->config['moderate_comments_create'] ||
                        $this->showcaseObject->user_permissions[UserPermissions::ModerateCommentsCreate]
                    )) {
                    $insertData['status'] = ENTRY_STATUS_PENDING_APPROVAL;
                }
            }

            $dataHandler->dataSet($insertData);

            if (!$dataHandler->commentValidate()) {
                $this->showcaseObject->errorMessages = array_merge(
                    $this->showcaseObject->errorMessages,
                    $dataHandler->get_friendly_errors()
                );
            }

            if (!$isPreview && !$this->showcaseObject->errorMessages) {
                if ($isUpdate) {
                    $dataHandler->commentUpdate($this->commentID);
                } else {
                    $dataHandler->commentInsert();

                    $this->commentID = $dataHandler->returnData['comment_id'];
                }

                $this->updateEntryCommentsCount();

                // we redirect only if soft-deleted (plugin?) because users can see their own unapproved content
                if (isset($dataHandler->returnData['status']) &&
                    $dataHandler->returnData['status'] === self::STATUS_SOFT_DELETE) {
                    $entryUrl = $this->showcaseObject->urlGetEntry(
                        $this->showcaseObject->entry_data['entry_slug'],
                        $this->showcaseObject->entry_data['entry_slug_custom'],
                    );

                    redirect(
                        $entryUrl,
                        $isUpdate ? $lang->myShowcaseCommentUpdatedStatus : $lang->myShowcaseCommentCreatedStatus
                    );
                } else {
                    $commentUrl = $this->showcaseObject->urlGetComment($dataHandler->returnData['comment_slug']);

                    redirect(
                        $commentUrl,
                        $isUpdate ? $lang->myShowcaseCommentUpdated : $lang->myShowcaseCommentCreated
                    );
                }
            }

            if ($isPreview) {
                $this->showcaseObject->entry_data = array_merge($this->showcaseObject->entry_data, $mybb->input);

                $commentPreview = $this->renderObject->buildComment(
                    array_merge($this->commentData, $mybb->input),
                    alt_trow(true),
                    isPreview: true,
                    isCreatePage: true
                );
            }
        }

        global $theme;

        switch ($this->showcaseObject->config['filter_force_field']) {
            /*
                case FILTER_TYPE_USER_ID:
                    $userData = get_user($this->showcaseObject->entryUserID);

                    if (empty($userData['uid']) || empty($mybb->usergroup['canviewprofiles'])) {
                        error_no_permission();
                    }

                    $lang->load('member');

                    $userName = htmlspecialchars_uni($userData['username']);

                    add_breadcrumb(
                        $lang->sprintf($lang->nav_profile, $userName),
                        $mybb->settings['bburl'] . '/' . get_profile_link($userData['uid'])
                    );

                    $mainUrl = str_replace(
                        '/user/',
                        '/user/' . $this->showcaseObject->entryUserID,
                        url(\MyShowcase\Plugin\RouterUrls::MainUser)->getRelativeUrl()
                    );

                    break;
            */
            default:
                $mainUrl = $this->showcaseObject->urlGenerate(
                    RouterUrls::EntryView,
                    $this->showcaseObject->urlParams
                );
        }

        add_breadcrumb($this->showcaseObject->config['name_friendly'], $mainUrl);

        $entrySubject = $this->renderObject->buildEntrySubject();

        $entryUrl = $this->showcaseObject->urlGenerate(
            RouterUrls::EntryView,
            [
                'entry_slug' => $this->showcaseObject->entry_data['entry_slug'],
                'entry_slug_custom' => $this->showcaseObject->entry_data['entry_slug_custom']
            ]
        );

        add_breadcrumb($entrySubject, $entryUrl);

        if ($isUpdate) {
            $createUpdateUrl = $this->showcaseObject->urlGenerate(
                RouterUrls::CommentUpdate,
                ['entry_slug' => $entry_slug, 'entry_slug_custom' => $entry_slug_custom, 'comment_slug' => $commentSlug]
            );
        } else {
            $createUpdateUrl = $this->showcaseObject->urlGenerate(
                RouterUrls::CommentCreate,
                [
                    'entry_slug' => $entry_slug,
                    'entry_slug_custom' => $entry_slug_custom
                ],
            );
        }

        if ($isUpdate) {
            add_breadcrumb($lang->myShowcaseButtonCommentUpdate, $createUpdateUrl);
        } else {
            add_breadcrumb($lang->myShowcaseButtonCommentCreate, $createUpdateUrl);
        }

        $commentLengthLimitNote = $lang->sprintf(
            $lang->myshowcase_comment_text_limit,
            my_number_format($this->showcaseObject->config['comments_minimum_length']),
            my_number_format($this->showcaseObject->config['comments_maximum_length'])
        );

        $alternativeBackground = alt_trow(true);

        $commentMessage = htmlspecialchars_uni($mybb->get_input('comment'));

        $editorCodeButtons = $editorSmilesInserter = '';

        $this->renderObject->buildCommentsFormEditor($editorCodeButtons, $editorSmilesInserter);

        $attachmentsUpload = $this->renderObject->buildAttachmentsUpload(
            $isUpdate,
            $this->renderObject::POST_TYPE_COMMENT
        );

        if ($isUpdate) {
            $buttonText = $lang->myShowcaseCommentCreateUpdateFormButtonUpdate;
        } else {
            $buttonText = $lang->myshowcase_view_entry_button_create_comment;
        }

        $hookArguments = hooksRun('controller_comments_create_update_end', $hookArguments);

        extract($hookArguments['extractVariables']);

        if ($commentPreview) {
            $commentPreview = $this->renderObject->templateGet('pageCommentCreateUpdateContentsPreview', [
                'commentPreview' => $commentPreview,
            ]);
        }

        $postHash = htmlspecialchars_uni($mybb->get_input('post_hash'));

        $this->outputSuccess($this->renderObject->templateGet('pageCommentCreateUpdateContents', [
            'url_base' => $this->showcaseObject->url_base,
            'commentPreview' => $commentPreview,
            'commentMessage' => $commentMessage,
            'editorCodeButtons' => $editorCodeButtons,
            'commentLengthLimitNote' => $commentLengthLimitNote,
            'buttonText' => $buttonText,
            'postHash' => $postHash,
            'createUpdateUrl' => $createUpdateUrl,
            'attachmentsUpload' => $attachmentsUpload,
        ]));
    }

    #[NoReturn] public function update(string $entry_slug, string $entry_slug_custom, string $comment_slug): string
    {
        return $this->create($entry_slug, $entry_slug_custom, true, $comment_slug);
    }

    #[NoReturn] public function approve(
        string $entrySlug,
        string $entrySlugCustom,
        string $commentSlug,
        int $status = self::STATUS_VISIBLE
    ): void {
        switch ($this->verifyPermission($commentSlug, $entrySlug, $entrySlugCustom)) {
            case self::NO_PERMISSION:
                error_no_permission();

                break;
            case self::INVALID_COMMENT:
                global $lang;

                error($lang->myShowcaseReportErrorInvalidComment);
        }

        global $mybb;

        verify_post_check($mybb->get_input('my_post_key'));

        $current_user_id = (int)$mybb->user['uid'];

        if (!$this->showcaseObject->user_permissions[ModeratorPermissions::CanManageComments]) {
            if (!($status === self::STATUS_SOFT_DELETE &&
                (int)$this->commentData['user_id'] === $current_user_id &&
                $this->showcaseObject->user_permissions[UserPermissions::CanDeleteComments])) {
                error_no_permission();
            }
        }

        if ($status === self::STATUS_DELETE) {
            $this->showcaseObject->commentDelete($this->commentID);
        } else {
            $comments_manager = new CommentManager($this->showcaseObject);

            $comments_manager->updateComment(['status' => $status], $this->commentID);
        }

        $this->updateEntryCommentsCount();

        if ($status === self::STATUS_DELETE) {
            require_once ROOT . '/Controllers/Entries.php';

            (new Entries())->redirect($entrySlug);
        } else {
            $this->redirect($commentSlug);
        }
    }

    #[NoReturn] public function unapprove(string $entrySlug, string $entrySlugCustom, string $commentSlug): void
    {
        $this->approve($entrySlug, $entrySlugCustom, $commentSlug, self::STATUS_PENDING_APPROVAL);
    }

    #[NoReturn] public function softDelete(string $entrySlug, string $entrySlugCustom, string $commentSlug): void
    {
        $this->approve($entrySlug, $entrySlugCustom, $commentSlug, self::STATUS_SOFT_DELETE);
    }

    #[NoReturn] public function restore(string $entrySlug, string $entrySlugCustom, string $commentSlug): void
    {
        $this->approve($entrySlug, $entrySlugCustom, $commentSlug);
    }

    #[NoReturn] public function delete(string $entrySlug, string $entrySlugCustom, string $commentSlug): void
    {
        $this->approve($entrySlug, $entrySlugCustom, $commentSlug, self::STATUS_DELETE);
    }
}