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

use MyShowcase\Entity\CommentEntity;
use MyShowcase\Entity\EntryEntity;
use MyShowcase\Enum\ContentStatuses;
use MyShowcase\Service\CommentsManager;
use MyShowcase\Enum\ContentTypes;
use MyShowcase\Enum\Routes;
use MyShowcase\Enum\UserPermissions;
use MyShowcase\Fields\FieldsInterface;
use MyShowcase\Util\StringUtil;
use Random\RandomException;
use Symfony\Component\Routing\Annotation\Route;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

use function MyShowcase\Plugin\Functions\hooksRun;
use function redirect;

class CommentsController extends BaseController
{
    public const STATUS_PENDING_APPROVAL = 0;
    public const STATUS_DELETE = 3;

    /**
     * @throws RandomException
     */
    public function updateEntryCommentsCount(int $entry_id): void
    {
        $this->verifyPassword();

        $db = $this->context->database;

        $counts = [ContentStatuses::AwaitingApproval => 0, ContentStatuses::VisibleApproved => 0, ContentStatuses::SoftDeleted => 0];

        $query = $db->simple_select(
            'myshowcase_comments',
            'status, COUNT(*) AS total',
            "showcase_id='{$this->context->showcase->showcase_id}' AND entry_id='{$entry_id}'",
            ['group_by' => 'status']
        );

        while ($row = $db->fetch_array($query)) {
            $counts[(int)$row['status']] = (int)$row['total'];
        }

        $last_comment = $this->context->comments_repository->queryComment(
            ["comment_data.entry_id='{$entry_id}'", "comment_data.status='" . ContentStatuses::VisibleApproved . "'"],
            ['comment_data.comment_id', 'comment_data.user_id', 'comment_data.username'],
            ['order_by' => 'comment_data.comment_id', 'order_dir' => 'DESC']
        );

        $hookArguments = ['commentsController' => &$this, 'entryID' => $entry_id, 'counts' => &$counts];

        hooksRun('controller_comments_update_entry_comments_count_start', $hookArguments);

        $db->update_query(
            'myshowcase_data' . $this->context->showcase->showcase_id,
            [
                'comments_awaiting' => $counts[ContentStatuses::AwaitingApproval],
                'comments_approved' => $counts[ContentStatuses::VisibleApproved],
                'comments_deleted' => $counts[ContentStatuses::SoftDeleted],
                'last_comment_id' => (int)($last_comment['comment_id'] ?? 0),
                'last_comment_user_id' => (int)($last_comment['user_id'] ?? 0),
                'last_comment_username' => $db->escape_string((string)($last_comment['username'] ?? '')),
            ],
            "entry_id='{$entry_id}'"
        );
    }

    /**
     * @throws RandomException
     * @throws RuntimeError
     * @throws LoaderError
     * @throws SyntaxError
     */
    #[Route('/view/{entry_public_id}/{entry_slug_custom}/comment', name: 'comment_create', methods: ['GET', 'POST'])]
    public function create(
        string $entry_public_id,
        ?string $comment_public_id = null,
        bool $is_update = false
    ): string {
        $this->verifyPassword();

        global $mybb, $lang;

        switch ($this->verifyPermission($entry_public_id, $comment_public_id)) {
            case self::NO_PERMISSION:
                $this->errorNoPermission();
                break;
            case self::INVALID_ENTRY:
                $this->error($lang->myshowcase_report_error_invalid_entry);
        }

        if ($is_update && !$this->context->current_user->hasPermission(UserPermissions::CommentsCanUpdate)) {
            $this->errorNoPermission();
        }

        // Only the author, or a moderator, may edit a comment.
        if ($is_update && ($edited_comment = $this->context->comments_manager->getComments()->offsetGet($this->comment_id)) &&
            $edited_comment->user_id !== $this->context->current_user->uid &&
            !$this->isCommentModerator()) {
            $this->errorNoPermission();
        }

        if (!$is_update && !$this->context->current_user->hasPermission(UserPermissions::CommentsCanPost)) {
            $this->errorNoPermission();
        }

        /** @var EntryEntity $entry */
        $entry = $this->context->entries_manager->getEntryByID($this->entry_id);

        if (!$this->context->showcase->comments_enable ||
            ($entry->is_closed && !$this->context->current_user->hasPermission(UserPermissions::CommentsCanPostInClosedEntries))) {
            $this->errorNoPermission();
        }

        $comment_data = $is_update
            ? $this->context->comments_manager->getComments()->offsetGet($this->comment_id)
            : $this->context->comments_manager->generateComment();

        $this->forcedFieldBreadcrumb($entry->user_id);
        $this->buildCommentBreadcrumbs($entry, $comment_public_id, $is_update, $lang);

        if (!$is_update && empty($comment_data->post_hash)) {
            $entry->post_hash = $mybb->get_input('post_hash') ?: (new StringUtil())->generatePublicId();
        }

        $commentPreview = '';

        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));

            $isPreview = isset($mybb->input['preview']);
            $commentPreview = $this->handlePostSubmission($entry, $comment_public_id, $is_update, $isPreview, $lang);
        }

        $createUpdateUrl = $this->buildFormActionUrl($entry, $comment_public_id, $is_update);

        $commentMessage = htmlspecialchars_uni($mybb->get_input('message'));

        $editorCodeButtons = $editorSmilesInserter = '';

        if ($this->context->showcase->comments_message_build_editor) {
            $this->context->render_manager->editorBuild($editorCodeButtons, $editorSmilesInserter);
        }

        $attachmentsUpload = $this->context->render_manager->buildAttachmentsUpload($is_update, ContentTypes::Comment);

        $buttonText = $is_update
            ? $lang->myshowcase_comment_create_update_form_button_update
            : $lang->myshowcase_view_entry_button_create_comment;

        hooksRun('controller_comments_create_update_end', $this);

        if ($commentPreview) {
            $commentPreview = $this->context->render_manager->templateGet(
                'pageCommentCreateUpdateContentsPreview',
                ['commentPreview' => $commentPreview]
            );
        }

        return $this->context->render_manager->templateGet('pageCommentCreateUpdateContents', [
            'commentPreview' => $commentPreview,
            'code_buttons' => $editorCodeButtons,
            'buttonText' => $buttonText,
            'createUpdateUrl' => $createUpdateUrl,
            'attachmentsUpload' => $attachmentsUpload,
        ]);
    }

    private function buildCommentBreadcrumbs(
        EntryEntity $entry,
        ?string $comment_public_id,
        bool $is_update,
        object $lang
    ): void {
        add_breadcrumb(
            $this->context->showcase->name,
            $this->context->showcase->showcase_url . $this->context->routing->generateUrl(Routes::Main)
        );

        $entry_subject = $this->buildEntrySubject($entry);

        add_breadcrumb(
            $entry_subject . $entry->subject,
            $this->context->showcase->showcase_url . $this->context->routing->generateUrl(
                Routes::EntryView,
                ['entry_public_id' => $entry->public_id, 'entry_slug_custom' => $entry->entry_slug_custom]
            )
        );

        if ($is_update) {
            add_breadcrumb(
                $lang->myshowcase_button_comment_update,
                $this->context->showcase->showcase_url . $this->context->routing->generateUrl(
                    Routes::CommentUpdate,
                    ['entry_public_id' => $entry->public_id, 'comment_public_id' => $comment_public_id]
                )
            );

            return;
        }

        add_breadcrumb(
            $lang->myshowcase_button_comment_create,
            $this->context->showcase->showcase_url . $this->context->routing->generateUrl(
                Routes::CommentCreate,
                ['entry_public_id' => $entry->public_id]
            )
        );
    }

    private function buildEntrySubject(EntryEntity $entry): string
    {
        $extras = [];

        /** @var FieldsInterface $field */
        foreach ($entry->getFields() as $field) {
            if ($field->field_definition['enable_subject']) {
                $extras[] = $field->getUserValue();
            }
        }

        return implode(' ', $extras);
    }

    private function handlePostSubmission(
        EntryEntity $entry,
        ?string $comment_public_id,
        bool $is_update,
        bool $isPreview,
        object $lang
    ): string {
        global $mybb;

        $insertData = $this->buildInsertData($is_update);

        if ($is_update) {
            $this->context->comments_manager->validateSet(
                $this->context->comments_manager->generateComment($insertData),
                CommentsManager::VALIDATION_METHOD_UPDATE
            );
        } else {
            $this->context->comments_manager->validateSet(
                $this->context->comments_manager->generateComment($insertData)
            );
        }

        if (!$this->context->comments_manager->validateComment()) {
            $this->context->render_manager->error_messages = array_merge(
                $this->context->render_manager->error_messages,
                $this->context->comments_manager->validateGetError()
            );
        }

        if (!$isPreview && !$this->context->render_manager->error_messages) {
            $this->persistCommentAndRedirect($entry, $is_update, $lang);
        }

        if (!$isPreview) {
            return '';
        }

        return $this->context->render_manager->buildComment(
            $entry,
            $this->context->comments_manager->getComments()->offsetGet($this->comment_id),
            $this->parser_options,
            true,
        );
    }

    private function buildInsertData(bool $is_update): array
    {
        global $mybb;

        $insertData = [
            'entry_id' => $this->entry_id,
            'message' => $mybb->get_input('message'),
            'status' => $this->determineCommentStatus($is_update),
        ];

        if ($is_update) {
            return $insertData;
        }

        return array_merge($insertData, [
            'user_id' => $this->context->current_user->uid,
            'post_hash' => $mybb->get_input('post_hash'),
            'ipaddress' => $mybb->session->packedip,
            'dateline' => TIME_NOW,
        ]);
    }

    /** A new comment waits for approval when the user's comments are moderated; an edit keeps the stored status. */
    private function determineCommentStatus(bool $is_update): int
    {
        $moderated = !$this->context->current_user->hasPermission(UserPermissions::CanBypassModeration) &&
            $this->context->current_user->hasPermission(
                $is_update ? UserPermissions::CommentsModerateUpdates : UserPermissions::CommentsModerateNew
            );

        if ($moderated) {
            return ContentStatuses::AwaitingApproval;
        }

        if ($is_update) {
            return (int)$this->context->comments_manager->getComments()->offsetGet($this->comment_id)->status;
        }

        return ContentStatuses::VisibleApproved;
    }

    private function persistCommentAndRedirect(EntryEntity $entry, bool $is_update, object $lang): void
    {
        $comment_data = $is_update
            ? $this->context->comments_manager->validationCommentUpdate($this->comment_id)
            : $this->context->comments_manager->validationCommentInsert();

        $comment_data = (object)$comment_data;

        $this->updateEntryCommentsCount($entry->entry_id);

        // Soft-deleted comments redirect to entry page; users cannot deep-link deleted content
        if (isset($comment_data->status) && $comment_data->status === ContentStatuses::SoftDeleted) {
            redirect(
                $this->context->showcase->showcase_url . $this->context->routing->generateUrl(
                    Routes::EntryView,
                    ['entry_public_id' => $entry->public_id, 'entry_slug_custom' => $entry->entry_slug_custom]
                ),
                $is_update ? $lang->myshowcase_comment_updated_status : $lang->myshowcase_comment_created_status
            );
        }

        redirect(
            $this->context->showcase->showcase_url . $this->context->routing->generateUrl(
                Routes::CommentView,
                ['comment_public_id' => $comment_data->public_id]
            ),
            $is_update ? $lang->myshowcase_comment_updated : $lang->myshowcase_comment_created
        );
    }

    private function buildFormActionUrl(EntryEntity $entry, ?string $comment_public_id, bool $is_update): string
    {
        if ($is_update) {
            return $this->context->routing->generateUrl(
                Routes::CommentUpdate,
                ['entry_public_id' => $entry->public_id, 'comment_public_id' => $comment_public_id]
            );
        }

        return $this->context->routing->generateUrl(
            Routes::CommentCreate,
            ['entry_public_id' => $entry->public_id]
        );
    }

    /**
     * @throws SyntaxError
     * @throws RandomException
     * @throws RuntimeError
     * @throws LoaderError
     */
    #[Route('/{showcase}/view/{entry_public_id}/update/comment/{comment_public_id}', name: 'comment_update', methods: [
        'GET',
        'POST',
    ])]
    public function update(string $entry_public_id, string $comment_public_id): string
    {
        $this->verifyPassword();

        global $lang;

        switch ($this->verifyPermission($entry_slug, $comment_slug)) {
            case self::NO_PERMISSION:
                $this->errorNoPermission();
                break;
            case self::INVALID_ENTRY:
                $this->error($lang->myshowcase_report_error_invalid_entry);
                break;
            case self::INVALID_COMMENT:
                $this->error($lang->myshowcase_report_error_invalid_comment ?? $lang->myshowcase_report_error_invalid_entry);
        }

        /** @var CommentEntity $comment */
        $comment = $this->context->comments_manager->getComments()->offsetGet($this->comment_id);

        /** @var EntryEntity $entry */
        $entry = $this->context->entries_manager->getEntryByID($this->entry_id);

        $this->commentPageModeration($entry, $comment);

        return $this->create($entry_public_id, $comment_public_id, true);
    }

    /**
     * Soft delete (delete=1) and hard delete (delete=2) of a comment: the group permission for one's own,
     * plus comment moderator rights for another user's. Hard delete removes the row and its attachments.
     */
    private function commentPageModeration(EntryEntity $entry, CommentEntity $comment): void
    {
        $delete = $this->context->mybb->get_input('delete', \MyBB::INPUT_INT);

        $restore = $this->context->mybb->get_input('action') === 'restore';

        if (!$restore && !in_array($delete, [1, 2], true)) {
            return;
        }

        verify_post_check($this->context->mybb->get_input('my_post_key'));

        $permission = match (true) {
            $restore => UserPermissions::CommentsCanRestore,
            $delete === 1 => UserPermissions::CommentsCanSoftDelete,
            default => UserPermissions::CommentsCanDelete,
        };

        if (!$this->mayModerateComment($comment, $permission)) {
            $this->errorNoPermission();
        }

        $lang = $this->context->language;

        if ($restore) {
            $this->context->comments_repository->updateComment(
                ['status' => ContentStatuses::VisibleApproved],
                (int)$comment->comment_id
            );
            $this->updateEntryCommentsCount((int)$entry->entry_id);

            redirect(
                $this->context->showcase->showcase_url . $this->context->routing->generateUrl(
                    Routes::EntryView,
                    ['entry_slug' => $entry->entry_slug, 'entry_slug_custom' => $entry->entry_slug_custom]
                ),
                $lang->myshowcase_main_inline_moderation_redirect_restored ?? 'The comment was restored.'
            );
        }

        if ($delete === 2) {
            foreach (
                $this->context->attachments_repository->queryAttachments(
                    ["comment_id='{$comment->comment_id}'"],
                    ['attachment_id']
                ) as $attachment
            ) {
                $this->context->attachments_repository->deleteAttachment((int)$attachment['attachment_id']);
            }

            $this->context->comments_repository->deleteComment((int)$comment->comment_id);
            $this->updateEntryCommentsCount((int)$entry->entry_id);

            redirect(
                $this->context->showcase->showcase_url . $this->context->routing->generateUrl(
                    Routes::EntryView,
                    ['entry_slug' => $entry->entry_slug, 'entry_slug_custom' => $entry->entry_slug_custom]
                ),
                $lang->myshowcase_main_inline_moderation_redirect_deleted ?? 'The comment was deleted.'
            );
        }

        $this->context->comments_repository->updateComment(
            ['status' => ContentStatuses::SoftDeleted],
            (int)$comment->comment_id
        );
        $this->updateEntryCommentsCount((int)$entry->entry_id);

        redirect(
            $this->context->showcase->showcase_url . $this->context->routing->generateUrl(
                Routes::EntryView,
                ['entry_slug' => $entry->entry_slug, 'entry_slug_custom' => $entry->entry_slug_custom]
            ),
            $lang->myshowcase_main_inline_moderation_redirect_soft_deleted ?? 'The comment was soft deleted.'
        );
    }
}
