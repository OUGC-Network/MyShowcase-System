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
use MyShowcase\Entity\Comment;
use MyShowcase\Entity\Entry;
use MyShowcase\Entity\User;
use MyShowcase\Enum\ModeratorPermissions;
use MyShowcase\Enum\Routes;
use MyShowcase\Enum\UserPermissions;
use MyShowcase\Fields\FieldFactory;
use MyShowcase\Repository\AttachmentsRepository;
use MyShowcase\Repository\CommentsRepository;
use MyShowcase\Repository\EntriesRepository;
use MyShowcase\Repository\ModeratorsRepository;
use MyShowcase\Repository\SubscriptionsRepository;
use MyShowcase\Routing\Routing;
use MyShowcase\Service\AttachmentsManager;
use MyShowcase\Service\CommentsManager;
use MyShowcase\Service\EntriesManager;
use MyShowcase\Service\Render;
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

    public const STATUS_VISIBLE = 1;

    public const STATUS_SOFT_DELETE = 2;

    public const STATUS_DELETE = 3;

    public function __construct(
        private readonly Routing $routing,
        private readonly User $current_user,
        private readonly EntriesRepository $entries_repository,
        private readonly AttachmentsRepository $attachments_repository,
        private readonly CommentsRepository $comments_repository,
        private readonly SubscriptionsRepository $subscriptions_repository,
        private readonly ModeratorsRepository $moderators_repository,
        private readonly EntriesManager $entries_manager,
        private readonly AttachmentsManager $attachments_manager,
        private readonly CommentsManager $comments_manager,
        private readonly Render $render_manager,
        private readonly FieldFactory $field_factory,
        private readonly array $config,
        private readonly array $fieldset_fields,
        private readonly array $filter,
        protected readonly array $attachment_types,
    ) {
        parent::__construct(
            $routing,
            $this->current_user,
            $this->entries_repository,
            $this->comments_repository,
            $this->entries_manager,
            $this->comments_manager,
            $this->render_manager,
            $this->field_factory,
            $this->config,
            $this->fieldset_fields,
            $this->filter,
        );
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

        $this->entries_repository->updateEntry(
            ['comments_approved' => $this->comments_repository->queryCommentCount($whereClauses)],
            $entry_id
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
        global $db, $mybb, $lang;

        switch ($this->verifyPermission($entry_slug, $comment_slug)) {
            case self::NO_PERMISSION:
                error_no_permission();

                break;
            case self::INVALID_ENTRY:
                global $lang;

                error($lang->myShowcaseReportErrorInvalidEntry);
        }

        /**
         * @var Entry $entry_data
         */
        $entry_data = $this->entries_manager->getEntryByID($this->entry_id);

        if ($is_update) {
            /**
             * @var Comment $comment_data
             */
            $comment_data = $entry_data->getComments()->offsetGet($this->comment_id);
        } else {
            $comment_data = new Comment();
        }

        $this->forcedFieldBreadcrumb();

        add_breadcrumb(
            $this->config['name'],
            $this->config['showcase_url'] . '/' . $this->routing->generateUrl(
                Routes::Main,
            )
        );

        add_breadcrumb(
            (function () use ($entry_data): string {
                $subject_extra = [];

                foreach ($entry_data->getFields() as $field_data) {
                    if ($field_data->fieldDefinition['enable_subject']) {
                        $subject_extra[] = $field_data->getUserValue();
                    }
                }

                return implode(' ', $subject_extra);
            })() . $entry_data->subject,
            $this->config['showcase_url'] . '/' . $this->routing->generateUrl(
                Routes::EntryView,
                [
                    'entry_slug' => $entry_data->entry_slug,
                    'entry_slug_custom' => $entry_data->entry_slug_custom
                ]
            )
        );

        if ($is_update) {
            add_breadcrumb(
                $lang->myShowcaseButtonCommentUpdate,
                $this->config['showcase_url'] . '/' . $this->routing->generateUrl(
                    Routes::CommentUpdate,
                    [
                        'entry_slug' => $entry_data->entry_slug,
                        'entry_slug_custom' => $entry_data->entry_slug_custom,
                        'comment_slug' => $comment_slug
                    ]
                )
            );
        } else {
            add_breadcrumb(
                $lang->myShowcaseButtonCommentCreate,
                $this->config['showcase_url'] . '/' . $this->routing->generateUrl(
                    Routes::CommentCreate,
                    [
                        'entry_slug' => $entry_data->entry_slug,
                        'entry_slug_custom' => $entry_data->entry_slug_custom,
                    ],
                ),
            );
        }

        if (!$is_update && empty($comment_data->post_hash)) {
            if ($mybb->get_input('post_hash')) {
                $entry_data->post_hash = $mybb->get_input('post_hash');
            } else {
                $entry_data->post_hash = (new UuidUtil())->generateUuidV4();
            }
        }

        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));

            $isPreview = isset($mybb->input['preview']);

            $insertData = [
                'message' => $mybb->get_input('message'),
                'status' => self::STATUS_VISIBLE,
            ];

            if (!$is_update) {
                $insertData['user_id'] = $this->current_user->user_id;

                $insertData['post_hash'] = $mybb->get_input('post_hash');

                $insertData['ipaddress'] = $mybb->session->packedip;

                $insertData['dateline'] = TIME_NOW;
            }

            if (!$this->current_user->hasPermission(UserPermissions::CommentsCanView)) {
                if ($is_update &&
                    $this->current_user->hasPermission(UserPermissions::CommentsModerateUpdates)) {
                    $insertData['status'] = $this->entries_manager::STATUS_PENDING;
                } elseif (!$is_update &&
                    $this->current_user->hasPermission(UserPermissions::CommentsModerateNew)) {
                    $insertData['status'] = $this->entries_manager::STATUS_PENDING;
                }
            }

            if ($is_update) {
                $this->comments_manager->validateSet(
                    new Comment(...$insertData),
                    $this->showcase::DATA_HANDLER_METHOD_UPDATE
                );
            } else {
                $this->comments_manager->validateSet(new Comment(...$insertData));
            }

            if (!$this->comments_manager->validateComment()) {
                $this->render_manager->error_messages = array_merge(
                    $this->render_manager->error_messages,
                    $this->comments_manager->validateGetError()
                );
            }

            if (!$isPreview && !$this->render_manager->error_messages) {
                if ($is_update) {
                    $comment_data = $this->comments_manager->validationCommentUpdate($this->comment_id);
                } else {
                    $comment_data = $this->comments_manager->validationCommentInsert();
                }

                $this->updateEntryCommentsCount($entry_data->entry_id);

                // we redirect only if soft-deleted (plugin?) because users can see their own unapproved content
                if (isset($comment_data->status) && $comment_data->status === self::STATUS_SOFT_DELETE) {
                    $entryUrl = $this->showcase->urlGetEntry(
                        $entry_data->entry_slug,
                        $entry_data->entry_slug_custom,
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
                $commentPreview = $this->render_manager->buildComment(
                    $entry_data,
                    $this->comment_data,
                    $this->parser_options,
                    true,
                );
            }
        }

        if ($is_update && !$this->current_user->hasPermission(UserPermissions::CommentsCanUpdate)) {
            error_no_permission();
        } elseif (!$is_update && !$this->current_user->hasPermission(UserPermissions::CommentsCanPost)) {
            error_no_permission();
        }

        $template_context = [
            'commentPreview' => $commentPreview,
            'formatted_message' => $commentMessage,
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
            $createUpdateUrl = $this->routing->generateUrl(
                Routes::CommentUpdate,
                [
                    'entry_slug' => $entry_data->entry_slug,
                    'entry_slug_custom' => $entry_data->entry_slug_custom,
                    'comment_slug' => $comment_slug
                ]
            );
        } else {
            $createUpdateUrl = $this->routing->generateUrl(
                Routes::CommentCreate,
                [
                    'entry_slug' => $entry_data->entry_slug,
                    'entry_slug_custom' => $entry_data->entry_slug_custom,
                ],
            );
        }

        $commentMessage = htmlspecialchars_uni($mybb->get_input('message'));

        $editorCodeButtons = $editorSmilesInserter = '';

        if ($this->config['comments_message_build_editor']) {
            $this->render_manager->editorBuild($editorCodeButtons, $editorSmilesInserter);
        }

        $attachmentsUpload = $this->render_manager->buildAttachmentsUpload(
            $is_update,
            $this->render_manager::POST_TYPE_COMMENT
        );

        if ($is_update) {
            $buttonText = $lang->myShowcaseCommentCreateUpdateFormButtonUpdate;
        } else {
            $buttonText = $lang->myshowcase_view_entry_button_create_comment;
        }

        hooksRun('controller_comments_create_update_end', $this);

        if ($commentPreview) {
            $commentPreview = $this->render_manager->templateGet('pageCommentCreateUpdateContentsPreview', [
                'commentPreview' => $commentPreview,
            ]);
        }

        return $this->render_manager->templateGet('pageCommentCreateUpdateContents', $template_context);
    }

    /**
     * @throws SyntaxError
     * @throws RandomException
     * @throws RuntimeError
     * @throws LoaderError
     */
    #[Route('/view/{entry_slug}/{entry_slug_custom}/comment/{comment_slug}/update', name: 'comment_update', methods: [
        'GET',
        'POST'
    ])]
    #[NoReturn] public function update(string $entry_slug, string $entry_slug_custom, string $comment_slug): string
    {
        return $this->create($entry_slug, $entry_slug_custom, $comment_slug, true);
    }
}