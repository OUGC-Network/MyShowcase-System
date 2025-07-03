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
use MyShowcase\System\ModeratorPermissions;
use MyShowcase\System\UserPermissions;

use function MyShowcase\Plugin\Functions\commentsGet;
use function MyShowcase\Plugin\Functions\commentUpdate;
use function MyShowcase\Plugin\Functions\dataHandlerGetObject;
use function MyShowcase\Plugin\Functions\entryGet;
use function MyShowcase\Plugin\Functions\generateUUIDv4;
use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\SimpleRouter\url;

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

    public function verifyPermission(
        string $commentSlug,
        ?string $entrySlug = null,
        ?string $entrySlugCustom = null,
        array $commentFields = []
    ): int {
        global $db;

        if (!$this->showcaseObject->config['comments_allow'] ||
            !(
                $this->showcaseObject->userPermissions[UserPermissions::CanViewEntries] ||
                $this->showcaseObject->userPermissions[ModeratorPermissions::CanManageEntries]
            ) ||
            !(
                $this->showcaseObject->userPermissions[UserPermissions::CanViewComments] ||
                $this->showcaseObject->userPermissions[ModeratorPermissions::CanManageComments]
            )) {
            return self::NO_PERMISSION;
        }

        global $mybb;

        $currentUserID = (int)$mybb->user['uid'];

        $commentData = commentsGet(
            ["comment_slug='{$db->escape_string($commentSlug)}'", "showcase_id='{$this->showcaseObject->showcase_id}'"],
            array_merge(['entry_id', 'status', 'user_id'], $commentFields),
            ['limit' => 1]
        );

        if (empty($commentData)) {
            return self::INVALID_COMMENT;
        }

        $commentStatus = (int)$commentData['status'];

        $commentUserID = (int)$commentData['user_id'];

        if ($commentStatus === self::STATUS_PENDING_APPROVAL && $currentUserID !== $commentUserID ||
            $commentStatus === self::STATUS_SOFT_DELETE && !$this->showcaseObject->userPermissions[ModeratorPermissions::CanManageComments]) {
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

        if ($entryStatus === ENTRY_STATUS_PENDING_APPROVAL && $currentUserID !== $entryUserID ||
            $entryStatus === ENTRY_STATUS_SOFT_DELETED && !$this->showcaseObject->userPermissions[ModeratorPermissions::CanManageEntries]) {
            return self::INVALID_COMMENT;
        }

        $returnValue = self::HAS_PERMISSION;

        $hookArguments = [
            'commentsController' => &$this,
            'returnValue' => &$returnValue,
        ];

        $this->entryID = $entryID;

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

        \MyShowcase\SimpleRouter\redirect($commentUrl, HTTP_CODE_PERMANENT_REDIRECT);

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

        $currentUserID = (int)$mybb->user['uid'];

        $statusVisible = self::STATUS_VISIBLE;

        $statusPendingApproval = self::STATUS_PENDING_APPROVAL;

        $statusSoftDeleted = self::STATUS_SOFT_DELETE;

        $whereClauses = [
            "entry_id='{$this->entryID}'",
            "showcase_id='{$this->showcaseObject->showcase_id}'",
            $this->showcaseObject->whereClauseStatusComment()
        ];

        $hookArguments = [
            'commentsController' => &$this,
            'whereClauses' => &$whereClauses,
        ];

        $commentTimeStamp = (int)$this->commentData['dateline'];

        $totalCommentsBeforeMainComment = (int)(commentsGet(
            array_merge($whereClauses, ["dateline<='{$commentTimeStamp}'"]),
            ['COUNT(comment_id) AS total_comments'],
            ['limit' => 1]
        )['total_comments'] ?? 0);

        if (($totalCommentsBeforeMainComment % $this->showcaseObject->config['comments_per_page']) == 0) {
            $currentPage = $totalCommentsBeforeMainComment / $this->showcaseObject->config['comments_per_page'];
        } else {
            $currentPage = (int)($totalCommentsBeforeMainComment / $this->showcaseObject->config['comments_per_page']) + 1;
        }

        $hookArguments = hooksRun('controller_comments_view_end', $hookArguments);

        require_once ROOT . '/Controllers/Entries.php';

        (new Entries())->viewEntry($entrySlug, $entrySlugCustom, $currentPage);
    }

    public function updateEntryCommentsCount(): void
    {
        $statusVisible = self::STATUS_VISIBLE;

        $whereClauses = ["entry_id='{$this->showcaseObject->entryID}'", "status='{$statusVisible}'"];

        $hookArguments = [
            'commentsController' => &$this,
            'whereClauses' => &$whereClauses,
        ];

        $hookArguments = hooksRun('controller_comments_update_entry_comments_count_start', $hookArguments);

        $totalComments = $this->showcaseObject->commentGet(
            $whereClauses,
            ['COUNT(comment_id) AS total_comments'],
            ['group_by' => 'entry_id', 'limit' => 1]
        )['total_comments'] ?? 0;

        $this->showcaseObject->dataUpdate(['comments' => $totalComments]);
    }

    #[NoReturn] public function create(
        string $entrySlug,
        string $entrySlugCustom,
        bool $isUpdate = false,
        string $commentSlug = ''
    ): void {
        global $mybb, $lang;

        if ($isUpdate) {
            if (!(
                $this->showcaseObject->userPermissions[UserPermissions::CanUpdateComments] ||
                $this->showcaseObject->userPermissions[ModeratorPermissions::CanManageEntries]
            )) {
                error_no_permission();
            }

            switch ($this->verifyPermission(
                $commentSlug,
                $entrySlug,
                $entrySlugCustom,
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
        } elseif (!$this->showcaseObject->userPermissions[UserPermissions::CanCreateEntries]) {
            error_no_permission();
        }

        $currentUserID = (int)$mybb->user['uid'];

        $mybb->input = array_merge($this->commentData, $mybb->input);

        $hookArguments = [
            'commentsController' => &$this,
            'isUpdate' => $isUpdate,
        ];

        $extractVariables = [];

        $hookArguments['extractVariables'] = &$extractVariables;

        $hookArguments = hooksRun('controller_comments_create_update_start', $hookArguments);

        $this->showcaseObject->setEntry($entrySlug, true);

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
                $insertData['user_id'] = $currentUserID;

                $insertData['post_hash'] = $mybb->get_input('post_hash');

                $insertData['ipaddress'] = $mybb->session->packedip;

                $insertData['dateline'] = TIME_NOW;
            }

            if (!$this->showcaseObject->userPermissions[ModeratorPermissions::CanManageComments]) {
                if ($isUpdate && (
                        $this->showcaseObject->config['moderate_comments_update'] ||
                        $this->showcaseObject->userPermissions[UserPermissions::ModerateCommentsUpdate]
                    )) {
                    $insertData['status'] = ENTRY_STATUS_PENDING_APPROVAL;
                } elseif (!$isUpdate && $this->showcaseObject->config['moderate_comments_create'] && (
                        $this->showcaseObject->config['moderate_comments_create'] ||
                        $this->showcaseObject->userPermissions[UserPermissions::ModerateCommentsCreate]
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
                        $this->showcaseObject->entryData['entry_slug'],
                        $this->showcaseObject->entryData['entry_slug_custom'],
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
                $this->showcaseObject->entryData = array_merge($this->showcaseObject->entryData, $mybb->input);

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
                $mainUrl = $this->showcaseObject->urlGetMain();
        }

        add_breadcrumb($this->showcaseObject->config['name_friendly'], $mainUrl);

        $entrySubject = $this->renderObject->buildEntrySubject();

        $entryUrl = url(
            RouterUrls::EntryView,
            [
                'entry_slug' => $this->showcaseObject->entryData['entry_slug'],
                'entry_slug_custom' => $this->showcaseObject->entryData['entry_slug_custom']
            ]
        )->getRelativeUrl();

        add_breadcrumb($entrySubject, $entryUrl);

        if ($isUpdate) {
            $createUpdateUrl = $this->showcaseObject->urlGetCommentUpdate($entrySlug, $entrySlugCustom, $commentSlug);
        } else {
            $createUpdateUrl = $this->showcaseObject->urlGetCommentCreate($entrySlug, $entrySlugCustom);
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

        $attachmentsUpload = $this->renderObject->buildAttachmentsUpload($isUpdate);

        if ($isUpdate) {
            $buttonText = $lang->myShowcaseCommentCreateUpdateFormButtonUpdate;
        } else {
            $buttonText = $lang->myShowcaseCommentCreateUpdateFormButtonCreate;
        }

        $hookArguments = hooksRun('controller_comments_create_update_end', $hookArguments);

        extract($hookArguments['extractVariables']);

        if ($commentPreview) {
            $commentPreview = $this->renderObject->templateGetTwig('pageEntryCommentCreateUpdateContentsPreview', [
                'commentPreview' => $commentPreview,
            ]);
        }

        $postHash = htmlspecialchars_uni($mybb->get_input('post_hash'));

        $this->outputSuccess($this->renderObject->templateGetTwig('pageEntryCommentCreateUpdateContents', [
            'baseUrl' => $this->showcaseObject->urlBase,
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

    #[NoReturn] public function update(string $entrySlug, string $entrySlugCustom, string $commentSlug): void
    {
        $this->create($entrySlug, $entrySlugCustom, true, $commentSlug);
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

        $currentUserID = (int)$mybb->user['uid'];

        if (!$this->showcaseObject->userPermissions[ModeratorPermissions::CanManageComments]) {
            if (!($status === self::STATUS_SOFT_DELETE &&
                (int)$this->commentData['user_id'] === $currentUserID &&
                $this->showcaseObject->userPermissions[UserPermissions::CanDeleteComments])) {
                error_no_permission();
            }
        }

        if ($status === self::STATUS_DELETE) {
            $this->showcaseObject->commentDelete($this->commentID);
        } else {
            commentUpdate(['status' => $status], $this->commentID);
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