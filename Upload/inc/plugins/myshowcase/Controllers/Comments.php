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
use MyShowcase\Enum\ModeratorPermissions;
use MyShowcase\Enum\UserPermissions;
use MyShowcase\Enum\Routes;

use function MyShowcase\Plugin\Functions\generateUUIDv4;
use function MyShowcase\Plugin\Functions\hooksRun;
use function redirect;

use const MyShowcase\ROOT;
use const MyShowcase\Plugin\Core\FILTER_TYPE_USER_ID;
use const MyShowcase\Plugin\Core\HTTP_CODE_PERMANENT_REDIRECT;

class Comments extends Base
{
    public const STATUS_PENDING_APPROVAL = 0;

    public const STATUS_VISIBLE = 1;

    public const STATUS_SOFT_DELETE = 2;

    public const STATUS_DELETE = 3;

    public int $entryID = 0;

    #[NoReturn] public function redirect(string $comment_slug): void
    {
        switch ($this->verifyPermission(comment_slug: $comment_slug)) {
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
            $this->entry_data['entry_slug'],
            $this->entry_data['entry_slug_custom'],
            $comment_slug
        );

        $hookArguments = hooksRun('controller_comments_redirect_end', $hookArguments);

        redirect($commentUrl, HTTP_CODE_PERMANENT_REDIRECT);

        exit;
    }

    #[NoReturn] public function view(string $entry_slug, string $entry_slug_custom, string $comment_slug): void
    {
        switch ($this->verifyPermission($entry_slug, $comment_slug)) {
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
            $whereClauses[] = "(status='" . $this->showcase->comments_manager::STATUS_VISIBLE . "' OR (user_id='{$this->showcase->current_user_id}' AND status='" . $this->showcase->comments_manager::STATUS_PENDING . "'))";
        }

        $hookArguments = [
            'commentsController' => &$this,
            'whereClauses' => &$whereClauses,
        ];

        $commentTimeStamp = (int)$this->comment_data['dateline'];

        $totalCommentsBeforeMainComment = $this->showcase->comments_repository->queryCommentCount(
            array_merge($whereClauses, ["dateline<='{$commentTimeStamp}'"])
        );

        if (($totalCommentsBeforeMainComment % $this->showcase->config['comments_per_page']) == 0) {
            $this->showcase->current_page = $totalCommentsBeforeMainComment / $this->showcase->config['comments_per_page'];
        } else {
            $this->showcase->current_page = (int)($totalCommentsBeforeMainComment / $this->showcase->config['comments_per_page']) + 1;
        }

        $hookArguments = hooksRun('controller_comments_view_end', $hookArguments);

        require_once ROOT . '/Controllers/Entries.php';

        (new Entries())->view($entry_slug, $entry_slug_custom, $this->showcase->current_page);
    }

    public function updateEntryCommentsCount(int $entry_id): void
    {
        $statusVisible = self::STATUS_VISIBLE;

        $whereClauses = ["entry_id='{$entry_id}'", "status='{$statusVisible}'"];

        $hookArguments = [
            'commentsController' => &$this,
            'whereClauses' => &$whereClauses,
        ];

        $hookArguments = hooksRun('controller_comments_update_entry_comments_count_start', $hookArguments);

        $this->showcase->entries_repository->updateEntry(
            ['comments_approved' => $this->showcase->comments_repository->queryCommentCount($whereClauses)],
            $entry_id
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

            switch ($this->verifyPermission($entry_slug, $commentSlug)) {
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

        global $db;

        $mybb->input = array_merge($this->comment_data, $mybb->input);

        $hookArguments = [
            'commentsController' => &$this,
            'isUpdate' => $isUpdate,
        ];

        $extractVariables = [];

        $hookArguments['extractVariables'] = &$extractVariables;

        $hookArguments = hooksRun('controller_comments_create_update_start', $hookArguments);

        $entry_data = $this->showcase->entries_manager->fetchEntry(
            ["entry_slug='{$db->escape_string($entry_slug)}'"],
        );

        $this->showcase->addEntry($entry_data);

        $entry_data = $this->showcase->getEntryByID($entry_data['entry_id']);

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
                    $insertData['status'] = $this->showcase->entries_manager::STATUS_PENDING;
                } elseif (!$isUpdate && $this->showcase->config['moderate_comments_create'] && (
                        $this->showcase->config['moderate_comments_create'] ||
                        $this->showcase->user_permissions[UserPermissions::ModerateCommentsCreate]
                    )) {
                    $insertData['status'] = $this->showcase->entries_manager::STATUS_PENDING;
                }
            }

            $dataHandler->dataSet($insertData);

            if (!$dataHandler->commentValidate()) {
                $this->showcase->error_messages = array_merge(
                    $this->showcase->error_messages,
                    $dataHandler->get_friendly_errors()
                );
            }

            if (!$isPreview && !$this->showcase->error_messages) {
                if ($isUpdate) {
                    $dataHandler->commentUpdate($this->comment_id);
                } else {
                    $dataHandler->commentInsert();

                    $this->comment_id = $dataHandler->return_data['comment_id'];
                }

                $this->updateEntryCommentsCount($entry_data->entry_id);

                // we redirect only if soft-deleted (plugin?) because users can see their own unapproved content
                if (isset($dataHandler->return_data['status']) &&
                    $dataHandler->return_data['status'] === self::STATUS_SOFT_DELETE) {
                    $entryUrl = $this->showcase->urlGetEntry(
                        $this->showcase->entry_data['entry_slug'],
                        $this->showcase->entry_data['entry_slug_custom'],
                    );

                    redirect(
                        $entryUrl,
                        $isUpdate ? $lang->myShowcaseCommentUpdatedStatus : $lang->myShowcaseCommentCreatedStatus
                    );
                } else {
                    $commentUrl = $this->showcase->urlGetComment($dataHandler->return_data['comment_slug']);

                    redirect(
                        $commentUrl,
                        $isUpdate ? $lang->myShowcaseCommentUpdated : $lang->myShowcaseCommentCreated
                    );
                }
            }

            if ($isPreview) {
                $this->showcase->entry_data = array_merge($this->showcase->entry_data, $mybb->input);

                $commentPreview = $this->showcase->render_manager->buildComment(
                    $entry_data,
                    $this->comment_data,
                    $this->showcase->parser_options,
                    true,
                );
            }
        }

        $this->forcedFieldBreadcrumb();

        add_breadcrumb(
            $this->showcase->config['name_friendly'],
            $this->showcase->config['url_showcase'] . '/' . $this->routing->generateUrl(
                Routes::EntryView,
                $this->showcase->url_params
            )
        );

        $entrySubject = $this->showcase->entries_manager->buildSubject($entry_data);

        add_breadcrumb(
            $entrySubject,
            $this->showcase->config['url_showcase'] . '/' . $this->routing->generateUrl(
                Routes::EntryView,
                [
                    'entry_slug' => $this->showcase->entry_data['entry_slug'],
                    'entry_slug_custom' => $this->showcase->entry_data['entry_slug_custom']
                ]
            )
        );

        if ($isUpdate) {
            $createUpdateUrl = $this->routing->generateUrl(
                Routes::CommentUpdate,
                [
                    'comment_slug' => $commentSlug
                ]
            );
        } else {
            $createUpdateUrl = $this->routing->generateUrl(
                Routes::CommentCreate,
                [
                    'entry_slug' => $entry_slug,
                    'entry_slug_custom' => $entry_slug_custom
                ],
            );
        }

        if ($isUpdate) {
            add_breadcrumb(
                $lang->myShowcaseButtonCommentUpdate,
                $this->showcase->config['url_showcase'] . '/' . $this->routing->generateUrl(
                    Routes::CommentUpdate,
                    [
                        'comment_slug' => $commentSlug
                    ]
                )
            );
        } else {
            add_breadcrumb(
                $lang->myShowcaseButtonCommentCreate,
                $this->showcase->config['url_showcase'] . '/' . $this->routing->generateUrl(
                    Routes::CommentCreate,
                    [
                        'entry_slug' => $entry_slug,
                        'entry_slug_custom' => $entry_slug_custom
                    ],
                )
            );
        }

        $commentLengthLimitNote = $lang->sprintf(
            $lang->myshowcase_comment_text_limit,
            my_number_format($this->showcase->config['comments_minimum_length']),
            my_number_format($this->showcase->config['comments_maximum_length'])
        );

        $alternativeBackground = alt_trow(true);

        $commentMessage = htmlspecialchars_uni($mybb->get_input('comment'));

        $editorCodeButtons = $editorSmilesInserter = '';

        if ($this->showcase->config['comments_build_editor']) {
            $this->showcase->editorBuild($editorCodeButtons, $editorSmilesInserter);
        }

        $attachmentsUpload = $this->showcase->render_manager->buildAttachmentsUpload(
            $isUpdate,
            $this->showcase->render_manager::POST_TYPE_COMMENT
        );

        if ($isUpdate) {
            $buttonText = $lang->myShowcaseCommentCreateUpdateFormButtonUpdate;
        } else {
            $buttonText = $lang->myshowcase_view_entry_button_create_comment;
        }

        $hookArguments = hooksRun('controller_comments_create_update_end', $hookArguments);

        extract($hookArguments['extractVariables']);

        if ($commentPreview) {
            $commentPreview = $this->showcase->render_manager->templateGet('pageCommentCreateUpdateContentsPreview', [
                'commentPreview' => $commentPreview,
            ]);
        }

        $this->outputSuccess($this->showcase->render_manager->templateGet('pageCommentCreateUpdateContents', [
            'url_base' => $this->showcase->url_base,
            'commentPreview' => $commentPreview,
            'formatted_message' => $commentMessage,
            'code_buttons' => $editorCodeButtons,
            'commentLengthLimitNote' => $commentLengthLimitNote,
            'buttonText' => $buttonText,
            'createUpdateUrl' => $createUpdateUrl,
            'attachmentsUpload' => $attachmentsUpload,
        ]));
    }

    #[NoReturn] public function update(string $entry_slug, string $entry_slug_custom, string $comment_slug): string
    {
        return $this->create($entry_slug, $entry_slug_custom, true, $comment_slug);
    }
}