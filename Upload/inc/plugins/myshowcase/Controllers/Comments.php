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

use function MyShowcase\Plugin\Functions\generateUUIDv4;
use function MyShowcase\Plugin\Functions\hooksRun;

use function redirect;

use const MyShowcase\ROOT;
use const MyShowcase\Plugin\Core\FILTER_TYPE_USER_ID;
use const MyShowcase\Plugin\Core\HTTP_CODE_PERMANENT_REDIRECT;

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

        if (!$this->showcase->config['comments_allow'] ||
            !(
                $this->showcase->user_permissions[UserPermissions::CanViewEntries] ||
                $this->showcase->user_permissions[ModeratorPermissions::CanManageEntries]
            ) ||
            !(
                $this->showcase->user_permissions[UserPermissions::CanViewComments] ||
                $this->showcase->user_permissions[ModeratorPermissions::CanManageComments]
            )) {
            return self::NO_PERMISSION;
        }

        $commentData = $this->showcase->commentManager->queryComment(
            ["comment_slug='{$db->escape_string($commentSlug)}'"],
            array_merge(['entry_id', 'status', 'user_id'], $commentFields)
        );

        if (empty($commentData)) {
            return self::INVALID_COMMENT;
        }

        $commentStatus = (int)$commentData['status'];

        $commentUserID = (int)$commentData['user_id'];

        if ($commentStatus === self::STATUS_PENDING_APPROVAL && $this->showcase->current_user_id !== $commentUserID ||
            $commentStatus === self::STATUS_SOFT_DELETE && !$this->showcase->user_permissions[ModeratorPermissions::CanManageComments]) {
            return self::INVALID_COMMENT;
        }

        $entryID = (int)$commentData['entry_id'];

        $entryData = $this->showcase->entryManager->queryEntry(
            [
                $entrySlug !== null ? "entry_slug='{$db->escape_string($entrySlug)}'" : "entry_id='{$entryID}'",
                $entrySlugCustom !== null ? "entry_slug_custom='{$db->escape_string($entrySlugCustom)}'" : "entry_id='{$entryID}'"
            ],
            ['entry_id', 'entry_slug', 'entry_slug_custom', 'status', 'user_id'],
        );

        if (empty($entryData)) {
            return self::INVALID_COMMENT;
        }

        $entryStatus = (int)$entryData['status'];

        $entryUserID = (int)$entryData['user_id'];

        if ($entryStatus === $this->showcase->entryManager::STATUS_PENDING && $this->showcase->current_user_id !== $entryUserID ||
            $entryStatus === $this->showcase->entryManager::STATUS_DELETED && !$this->showcase->user_permissions[ModeratorPermissions::CanManageEntries]) {
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

        $commentUrl = $this->showcase->urlGetEntryComment(
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

        $statusVisible = self::STATUS_VISIBLE;

        $statusPendingApproval = self::STATUS_PENDING_APPROVAL;

        $statusSoftDeleted = self::STATUS_SOFT_DELETE;

        $whereClauses = [
            "entry_id='{$this->entry_id}'",
            "showcase_id='{$this->showcase->showcase_id}'",
        ];

        if (!$this->showcase->user_permissions[ModeratorPermissions::CanManageEntries] &&
            !$this->showcase->user_permissions[ModeratorPermissions::CanManageComments]) {
            $whereClauses[] = "(status='" . $this->showcase->commentManager::STATUS_VISIBLE . "' OR (user_id='{$this->showcase->current_user_id}' AND status='" . $this->showcase->commentManager::STATUS_PENDING . "'))";
        }

        $hookArguments = [
            'commentsController' => &$this,
            'whereClauses' => &$whereClauses,
        ];

        $commentTimeStamp = (int)$this->commentData['dateline'];

        $totalCommentsBeforeMainComment = $this->showcase->commentManager->queryCommentCount(
            array_merge($whereClauses, ["dateline<='{$commentTimeStamp}'"])
        );

        if (($totalCommentsBeforeMainComment % $this->showcase->config['comments_per_page']) == 0) {
            $this->renderObject->current_page = $totalCommentsBeforeMainComment / $this->showcase->config['comments_per_page'];
        } else {
            $this->renderObject->current_page = (int)($totalCommentsBeforeMainComment / $this->showcase->config['comments_per_page']) + 1;
        }

        $hookArguments = hooksRun('controller_comments_view_end', $hookArguments);

        require_once ROOT . '/Controllers/Entries.php';

        (new Entries())->viewEntry($entrySlug, $entrySlugCustom, $this->renderObject->current_page);
    }

    public function updateEntryCommentsCount(): void
    {
        $statusVisible = self::STATUS_VISIBLE;

        $whereClauses = ["entry_id='{$this->showcase->entry_id}'", "status='{$statusVisible}'"];

        $hookArguments = [
            'commentsController' => &$this,
            'whereClauses' => &$whereClauses,
        ];

        $hookArguments = hooksRun('controller_comments_update_entry_comments_count_start', $hookArguments);

        $this->showcase->entryManager->updateEntry(
            ['comments' => $this->showcase->commentManager->queryCommentCount($whereClauses)],
            $this->showcase->entry_id
        );
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
                $this->showcase->user_permissions[UserPermissions::CanUpdateComments] ||
                $this->showcase->user_permissions[ModeratorPermissions::CanManageEntries]
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
        } elseif (!$this->showcase->user_permissions[UserPermissions::CanCreateEntries]) {
            error_no_permission();
        }

        $mybb->input = array_merge($this->commentData, $mybb->input);

        $hookArguments = [
            'commentsController' => &$this,
            'isUpdate' => $isUpdate,
        ];

        $extractVariables = [];

        $hookArguments['extractVariables'] = &$extractVariables;

        $hookArguments = hooksRun('controller_comments_create_update_start', $hookArguments);

        $this->showcase->setEntry($entry_slug, true);

        $commentPreview = '';

        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));

            $isPreview = isset($mybb->input['preview']);

            if ($isUpdate) {
                $dataHandler = $this->showcase->dataHandler($this->showcase::DATA_HANDLER_METHOD_UPDATE);
            } else {
                $dataHandler = $this->showcase->dataHandler();
            }

            $insertData = [
                'comment' => $mybb->get_input('comment'),
                'status' => self::STATUS_VISIBLE,
            ];

            if (!$isUpdate) {
                $insertData['user_id'] = $this->showcase->current_user_id;

                $insertData['post_hash'] = $mybb->get_input('post_hash');

                $insertData['ipaddress'] = $mybb->session->packedip;

                $insertData['dateline'] = TIME_NOW;
            }

            if (!$this->showcase->user_permissions[ModeratorPermissions::CanManageComments]) {
                if ($isUpdate && (
                        $this->showcase->config['moderate_comments_update'] ||
                        $this->showcase->user_permissions[UserPermissions::ModerateCommentsUpdate]
                    )) {
                    $insertData['status'] = $this->showcase->entryManager::STATUS_PENDING;
                } elseif (!$isUpdate && $this->showcase->config['moderate_comments_create'] && (
                        $this->showcase->config['moderate_comments_create'] ||
                        $this->showcase->user_permissions[UserPermissions::ModerateCommentsCreate]
                    )) {
                    $insertData['status'] = $this->showcase->entryManager::STATUS_PENDING;
                }
            }

            $dataHandler->dataSet($insertData);

            if (!$dataHandler->commentValidate()) {
                $this->showcase->errorMessages = array_merge(
                    $this->showcase->errorMessages,
                    $dataHandler->get_friendly_errors()
                );
            }

            if (!$isPreview && !$this->showcase->errorMessages) {
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
                    $entryUrl = $this->showcase->urlGetEntry(
                        $this->showcase->entry_data['entry_slug'],
                        $this->showcase->entry_data['entry_slug_custom'],
                    );

                    redirect(
                        $entryUrl,
                        $isUpdate ? $lang->myShowcaseCommentUpdatedStatus : $lang->myShowcaseCommentCreatedStatus
                    );
                } else {
                    $commentUrl = $this->showcase->urlGetComment($dataHandler->returnData['comment_slug']);

                    redirect(
                        $commentUrl,
                        $isUpdate ? $lang->myShowcaseCommentUpdated : $lang->myShowcaseCommentCreated
                    );
                }
            }

            if ($isPreview) {
                $this->showcase->entry_data = array_merge($this->showcase->entry_data, $mybb->input);

                $commentPreview = $this->renderObject->buildComment(
                    array_merge($this->commentData, $mybb->input),
                    isPreview: true,
                    isCreatePage: true
                );
            }
        }

        switch ($this->showcase->config['filter_force_field']) {
            case FILTER_TYPE_USER_ID:
                $this->showcase->urlParamsAppend(
                    ['user_id' => $userID ?? $this->showcase->current_user_id]
                );

                $userData = get_user($this->showcase->url_params['user_id']);

                if (empty($userData['uid']) || (empty($mybb->usergroup['canviewprofiles']) && (int)$userData['uid'] !== $this->showcase->current_user_id)) {
                    error_no_permission();
                }

                $lang->load('member');

                $userName = htmlspecialchars_uni($userData['username']);

                add_breadcrumb(
                    $lang->sprintf($lang->nav_profile, $userName),
                    $mybb->settings['bburl'] . '/' . get_profile_link($userData['uid'])
                );

                break;
        }

        $mainUrl = $this->showcase->urlGenerate(
            RouterUrls::EntryView,
            $this->showcase->url_params
        );

        add_breadcrumb($this->showcase->config['name_friendly'], $mainUrl);

        $entrySubject = $this->renderObject->buildEntrySubject();

        $entryUrl = $this->showcase->urlGenerate(
            RouterUrls::EntryView,
            [
                'entry_slug' => $this->showcase->entry_data['entry_slug'],
                'entry_slug_custom' => $this->showcase->entry_data['entry_slug_custom']
            ]
        );

        add_breadcrumb($entrySubject, $entryUrl);

        if ($isUpdate) {
            $createUpdateUrl = $this->showcase->urlGenerate(
                RouterUrls::CommentUpdate,
                ['entry_slug' => $entry_slug, 'entry_slug_custom' => $entry_slug_custom, 'comment_slug' => $commentSlug]
            );
        } else {
            $createUpdateUrl = $this->showcase->urlGenerate(
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
            my_number_format($this->showcase->config['comments_minimum_length']),
            my_number_format($this->showcase->config['comments_maximum_length'])
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
            'url_base' => $this->showcase->url_base,
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

        if (!$this->showcase->user_permissions[ModeratorPermissions::CanManageComments]) {
            if (!($status === self::STATUS_SOFT_DELETE &&
                (int)$this->commentData['user_id'] === $this->showcase->current_user_id &&
                $this->showcase->user_permissions[UserPermissions::CanDeleteComments])) {
                error_no_permission();
            }
        }

        if ($status === self::STATUS_DELETE) {
            $this->showcase->commentManager->deleteComment($this->commentID);
        } else {
            $this->showcase->commentManager->updateComment(['status' => $status], $this->commentID);
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