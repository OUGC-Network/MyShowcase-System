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

use JetBrains\PhpStorm\NoReturn;
use MyShowcase\Entity\CommentEntity;
use MyShowcase\Entity\EntryEntity;
use MyShowcase\Enum\ContentStatuses;
use MyShowcase\Enum\ContentTypes;
use MyShowcase\Enum\Routes;
use MyShowcase\Enum\UserPermissions;
use MyShowcase\Fields\FieldsInterface;
use MyShowcase\Repository\CommentsRepository;
use MyShowcase\Service\EntriesManager;
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

        $statusVisible = ContentStatuses::VisibleApproved;

        $whereClauses = ["entry_id='{$entry_id}'", "status='{$statusVisible}'"];

        $hookArguments = [
            'commentsController' => &$this,
            'whereClauses' => &$whereClauses,
        ];

        $hookArguments = hooksRun('controller_comments_update_entry_comments_count_start', $hookArguments);

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

        global $db, $mybb, $lang;

        switch ($this->verifyPermission($entry_slug, $comment_slug)) {
            case self::NO_PERMISSION:
                $this->errorNoPermission();

                break;
            case self::INVALID_ENTRY:
                global $lang;

                $this->error($lang->myShowcaseReportErrorInvalidEntry);
        }

        /**
         * @var EntryEntity $entry
         */
        $entry = $this->context->entries_manager->getEntryByID($this->entry_id);

        if ($is_update) {
            /**
             * @var CommentEntity $comment_data
             */
            $comment_data = $this->context->comments_manager->getComments()->offsetGet($this->comment_id);
        } else {
            $comment_data = $this->context->comments_manager->generateComment();
        }

        $this->forcedFieldBreadcrumb($entry->user_id);

        add_breadcrumb(
            $this->context->showcase->name,
            $this->context->showcase->showcase_url . $this->context->routing->generateUrl(
                Routes::Main,
            )
        );

        add_breadcrumb(
            (function () use ($entry): string {
                $subject_extra = [];

                /**
                 * @var FieldsInterface $field
                 */
                foreach ($entry->getFields() as $field) {
                    if ($field->field_definition['enable_subject']) {
                        $subject_extra[] = $field->getUserValue();
                    }
                }

                return implode(' ', $subject_extra);
            })() . $entry->subject,
            $this->context->showcase->showcase_url . $this->context->routing->generateUrl(
                Routes::EntryView,
                [
                    'entry_slug' => $entry->entry_slug,
                    'entry_slug_custom' => $entry->entry_slug_custom
                ]
            )
        );

        if ($is_update) {
            add_breadcrumb(
                $lang->myShowcaseButtonCommentUpdate,
                $this->context->showcase->showcase_url . $this->context->routing->generateUrl(
                    Routes::CommentUpdate,
                    [
                        'entry_slug' => $entry->entry_slug,
                        'comment_slug' => $comment_slug,
                    ]
                )
            );
        } else {
            add_breadcrumb(
                $lang->myShowcaseButtonCommentCreate,
                $this->context->showcase->showcase_url . $this->context->routing->generateUrl(
                    Routes::CommentCreate,
                    [
                        'entry_slug' => $entry->entry_slug,
                    ],
                ),
            );
        }

        if (!$is_update && empty($comment_data->post_hash)) {
            if ($mybb->get_input('post_hash')) {
                $entry->post_hash = $mybb->get_input('post_hash');
            } else {
                $entry->post_hash = (new UuidUtil())->generateUuidV4();
            }
        }

        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));

            $isPreview = isset($mybb->input['preview']);

            $insertData = [
                'message' => $mybb->get_input('message'),
                'status' => ContentStatuses::VisibleApproved,
            ];

            if (!$is_update) {
                $insertData['user_id'] = $this->context->current_user->uid;

                $insertData['post_hash'] = $mybb->get_input('post_hash');

                $insertData['ipaddress'] = $mybb->session->packedip;

                $insertData['dateline'] = TIME_NOW;
            }

            if (!$this->context->current_user->hasPermission(UserPermissions::CommentsCanView)) {
                if ($is_update &&
                    $this->context->current_user->hasPermission(UserPermissions::CommentsModerateUpdates)) {
                    $insertData['status'] = ContentStatuses::AwaitingApproval;
                } elseif (!$is_update &&
                    $this->context->current_user->hasPermission(UserPermissions::CommentsModerateNew)) {
                    $insertData['status'] = ContentStatuses::AwaitingApproval;
                }
            }

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
                if ($is_update) {
                    $comment_data = $this->context->comments_manager->validationCommentUpdate($this->comment_id);
                } else {
                    $comment_data = $this->context->comments_manager->validationCommentInsert();
                }

                $this->updateEntryCommentsCount($entry->entry_id);

                // we redirect only if soft-deleted (plugin?) because users can see their own unapproved content
                if (isset($comment_data->status) && $comment_data->status === ContentStatuses::SoftDeleted) {
                    $entryUrl = $this->showcase->urlGetEntry(
                        $entry->entry_slug,
                        $entry->entry_slug_custom,
                    );

                    redirect(
                        $entryUrl,
                        $is_update ? $lang->myShowcaseCommentUpdatedStatus : $lang->myShowcaseCommentCreatedStatus
                    );
                } else {
                    $commentUrl = $this->showcase->urlGetComment($comment_data->comment_slug);

                    redirect(
                        $commentUrl,
                        $is_update ? $lang->myShowcaseCommentUpdated : $lang->myShowcaseCommentCreated
                    );
                }
            }

            if ($isPreview) {
                $commentPreview = $this->context->render_manager->buildComment(
                    $entry,
                    $this->comment_data,
                    $this->parser_options,
                    true,
                );
            }
        }

        if ($is_update && !$this->context->current_user->hasPermission(UserPermissions::CommentsCanUpdate)) {
            $this->errorNoPermission();
        } elseif (!$is_update && !$this->context->current_user->hasPermission(UserPermissions::CommentsCanPost)) {
            $this->errorNoPermission();
        }

        $template_context = [
            'commentPreview' => $commentPreview,
            'code_buttons' => $editorCodeButtons,
            'buttonText' => $buttonText,
            'createUpdateUrl' => $createUpdateUrl,
            'attachmentsUpload' => $attachmentsUpload,
        ];

        hooksRun('controller_comments_create_update_start', $this);


        ///
        ///
        ///
        ///
        ///
        ///
        ///
        ///
        ///
        ///
        ///
        ///
        ///
        ///
        ///
        ///

        $commentPreview = '';

        if ($is_update) {
            $createUpdateUrl = $this->context->routing->generateUrl(
                Routes::CommentUpdate,
                [
                    'entry_slug' => $entry->entry_slug,
                    'comment_slug' => $comment_slug,
                ]
            );
        } else {
            $createUpdateUrl = $this->context->routing->generateUrl(
                Routes::CommentCreate,
                [
                    'entry_slug' => $entry->entry_slug,
                ],
            );
        }

        $commentMessage = htmlspecialchars_uni($mybb->get_input('message'));

        $editorCodeButtons = $editorSmilesInserter = '';

        if ($this->context->showcase->comments_message_build_editor) {
            $this->context->render_manager->editorBuild($editorCodeButtons, $editorSmilesInserter);
        }

        $attachmentsUpload = $this->context->render_manager->buildAttachmentsUpload(
            $is_update,
            ContentTypes::Comment
        );

        if ($is_update) {
            $buttonText = $lang->myShowcaseCommentCreateUpdateFormButtonUpdate;
        } else {
            $buttonText = $lang->myshowcase_view_entry_button_create_comment;
        }

        hooksRun('controller_comments_create_update_end', $this);

        if ($commentPreview) {
            $commentPreview = $this->context->render_manager->templateGet('pageCommentCreateUpdateContentsPreview', [
                'commentPreview' => $commentPreview,
            ]);
        }

        return $this->context->render_manager->templateGet('pageCommentCreateUpdateContents', $template_context);
    }

    /**
     * @throws SyntaxError
     * @throws RandomException
     * @throws RuntimeError
     * @throws LoaderError
     */
    #[Route('/{showcase}/view/{entry_slug}/update/comment/{comment_slug}', name: 'comment_update', methods: [
        'GET',
        'POST'
    ])]
    #[NoReturn] public function update(string $entry_slug, string $entry_slug_custom, string $comment_slug): string
    {
        return $this->create($entry_slug, $entry_slug_custom, $comment_slug, true);
    }
}