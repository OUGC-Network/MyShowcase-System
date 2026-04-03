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
use MyShowcase\Enum\ContentTypes;
use MyShowcase\Enum\Routes;
use MyShowcase\Enum\UserPermissions;
use MyShowcase\Fields\FieldsInterface;
use MyShowcase\Util\UuidUtil;
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

        $whereClauses = [
            "entry_id='{$entry_id}'",
            "status='" . ContentStatuses::VisibleApproved . "'",
        ];

        $hookArguments = hooksRun(
            'controller_comments_update_entry_comments_count_start',
            ['commentsController' => &$this, 'whereClauses' => &$whereClauses]
        );

        $this->context->entries_repository->updateEntry(
            $this->context->entries_manager->generateEntry(
                ['entry_id' => $entry_id, 'is_closed' => 0]
            )
        );
    }

    /**
     * @throws RandomException
     * @throws RuntimeError
     * @throws LoaderError
     * @throws SyntaxError
     */
    #[Route('/view/{entry_slug}/{entry_slug_custom}/comment', name: 'comment_create', methods: ['GET', 'POST'])]
    public function create(
        string $entry_slug,
        string $entry_slug_custom,
        ?string $comment_slug = null,
        bool $is_update = false
    ): string {
        $this->verifyPassword();

        global $mybb, $lang;

        switch ($this->verifyPermission($entry_slug, $comment_slug)) {
            case self::NO_PERMISSION:
                $this->errorNoPermission();
                break;
            case self::INVALID_ENTRY:
                $this->error($lang->myShowcaseReportErrorInvalidEntry);
        }

        if ($is_update && !$this->context->current_user->hasPermission(UserPermissions::CommentsCanUpdate)) {
            $this->errorNoPermission();
        }

        if (!$is_update && !$this->context->current_user->hasPermission(UserPermissions::CommentsCanPost)) {
            $this->errorNoPermission();
        }

        /** @var EntryEntity $entry */
        $entry = $this->context->entries_manager->getEntryByID($this->entry_id);

        $comment_data = $is_update
            ? $this->context->comments_manager->getComments()->offsetGet($this->comment_id)
            : $this->context->comments_manager->generateComment();

        $this->forcedFieldBreadcrumb($entry->user_id);
        $this->buildCommentBreadcrumbs($entry, $comment_slug, $is_update, $lang);

        if (!$is_update && empty($comment_data->post_hash)) {
            $entry->post_hash = $mybb->get_input('post_hash') ?: (new UuidUtil())->generateUuidV4();
        }

        $commentPreview = '';

        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));

            $isPreview = isset($mybb->input['preview']);
            $commentPreview = $this->handlePostSubmission($entry, $comment_slug, $is_update, $isPreview, $lang);
        }

        $createUpdateUrl = $this->buildFormActionUrl($entry, $comment_slug, $is_update);

        $commentMessage = htmlspecialchars_uni($mybb->get_input('message'));

        $editorCodeButtons = $editorSmilesInserter = '';

        if ($this->context->showcase->comments_message_build_editor) {
            $this->context->render_manager->editorBuild($editorCodeButtons, $editorSmilesInserter);
        }

        $attachmentsUpload = $this->context->render_manager->buildAttachmentsUpload($is_update, ContentTypes::Comment);

        $buttonText = $is_update
            ? $lang->myShowcaseCommentCreateUpdateFormButtonUpdate
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
        ?string $comment_slug,
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
                ['entry_slug' => $entry->entry_slug, 'entry_slug_custom' => $entry->entry_slug_custom]
            )
        );

        if ($is_update) {
            add_breadcrumb(
                $lang->myShowcaseButtonCommentUpdate,
                $this->context->showcase->showcase_url . $this->context->routing->generateUrl(
                    Routes::CommentUpdate,
                    ['entry_slug' => $entry->entry_slug, 'comment_slug' => $comment_slug]
                )
            );

            return;
        }

        add_breadcrumb(
            $lang->myShowcaseButtonCommentCreate,
            $this->context->showcase->showcase_url . $this->context->routing->generateUrl(
                Routes::CommentCreate,
                ['entry_slug' => $entry->entry_slug]
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
        ?string $comment_slug,
        bool $is_update,
        bool $isPreview,
        object $lang
    ): string {
        global $mybb;

        $insertData = $this->buildInsertData($is_update);

        if ($is_update) {
            $this->context->comments_manager->validateSet(
                $this->context->comments_manager->generateComment($insertData),
                $this->showcase::DATA_HANDLER_METHOD_UPDATE
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

    private function determineCommentStatus(bool $is_update): int
    {
        if ($this->context->current_user->hasPermission(UserPermissions::CommentsCanView)) {
            return ContentStatuses::VisibleApproved;
        }

        if ($is_update && $this->context->current_user->hasPermission(UserPermissions::CommentsModerateUpdates)) {
            return ContentStatuses::AwaitingApproval;
        }

        if (!$is_update && $this->context->current_user->hasPermission(UserPermissions::CommentsModerateNew)) {
            return ContentStatuses::AwaitingApproval;
        }

        return ContentStatuses::VisibleApproved;
    }

    private function persistCommentAndRedirect(EntryEntity $entry, bool $is_update, object $lang): void
    {
        $comment_data = $is_update
            ? $this->context->comments_manager->validationCommentUpdate($this->comment_id)
            : $this->context->comments_manager->validationCommentInsert();

        $this->updateEntryCommentsCount($entry->entry_id);

        // Soft-deleted comments redirect to entry page; users cannot deep-link deleted content
        if (isset($comment_data->status) && $comment_data->status === ContentStatuses::SoftDeleted) {
            redirect(
                $this->showcase->urlGetEntry($entry->entry_slug, $entry->entry_slug_custom),
                $is_update ? $lang->myShowcaseCommentUpdatedStatus : $lang->myShowcaseCommentCreatedStatus
            );
        }

        redirect(
            $this->showcase->urlGetComment($comment_data->comment_slug),
            $is_update ? $lang->myShowcaseCommentUpdated : $lang->myShowcaseCommentCreated
        );
    }

    private function buildFormActionUrl(EntryEntity $entry, ?string $comment_slug, bool $is_update): string
    {
        if ($is_update) {
            return $this->context->routing->generateUrl(
                Routes::CommentUpdate,
                ['entry_slug' => $entry->entry_slug, 'comment_slug' => $comment_slug]
            );
        }

        return $this->context->routing->generateUrl(
            Routes::CommentCreate,
            ['entry_slug' => $entry->entry_slug]
        );
    }

    /**
     * @throws SyntaxError
     * @throws RandomException
     * @throws RuntimeError
     * @throws LoaderError
     */
    #[Route('/{showcase}/view/{entry_slug}/update/comment/{comment_slug}', name: 'comment_update', methods: [
        'GET',
        'POST',
    ])]
    public function update(string $entry_slug, string $entry_slug_custom, string $comment_slug): string
    {
        return $this->create($entry_slug, $entry_slug_custom, $comment_slug, true);
    }
}
