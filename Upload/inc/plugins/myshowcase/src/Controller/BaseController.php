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
use MyShowcase\Entity\AttachmentEntity;
use MyShowcase\Entity\CommentEntity;
use MyShowcase\Entity\EntryEntity;
use MyShowcase\Enum\CacheDataTypes;
use MyShowcase\Enum\ContentStatuses;
use MyShowcase\Enum\FieldHtmlTypes;
use MyShowcase\Enum\FilterFields;
use MyShowcase\Enum\Routes;
use MyShowcase\Enum\UserPermissions;
use MyShowcase\Fields\FieldFactory;
use MyShowcase\Fields\FieldsInterface;
use MyShowcase\Repository\AccessLogsRepository;
use MyShowcase\Repository\CommentsRepository;
use MyShowcase\Repository\EntriesRepository;
use MyShowcase\Service\CacheManager;
use MyShowcase\ShowcaseContext;
use MyShowcase\ValueObjects\EntryCustomField;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;

use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\loadLanguage;

use const MyShowcase\VERSION_CODE;

abstract class BaseController
{
    public const HTTP_CODE_PERMANENT_REDIRECT = 301;
    public const PASSWORD_IS_INVALID = 0;
    public const PASSWORD_IS_VALID = 1;
    public const NO_PERMISSION = 0;
    public const HAS_PERMISSION = 1;
    public const INVALID_ENTRY = 3;
    public const INVALID_COMMENT = 4;
    public const INVALID_ATTACHMENT = 5;

    protected readonly EntriesRepository $entries_repository;
    protected readonly FormFactoryInterface $form_factory;

    protected int $entry_id = 0;
    protected int $comment_id = 0;

    public function __construct(
        public readonly ShowcaseContext $context,
        protected readonly bool $is_api_request = false,
    ) {
        if (SHOWCASE_FILE_VERSION_CODE < VERSION_CODE) {
            $this->error(
                'This file is not the same version as the MyShowcase System. Please be sure to upload and configure ALL files.'
            );
        }

        loadLanguage();

        loadLanguage('myshowcase' . $this->context->showcase->showcase_id, false, true);

        $this->form_factory = Forms::createFormFactory();
    }

    /**
     * @throws SyntaxError
     * @throws RuntimeError
     * @throws LoaderError
     */
    protected function verifyPassword(): void
    {
        if ($this->context->showcase->password === '') {
            return;
        }

        $repo = new AccessLogsRepository(
            $this->context->database,
            $this->context->showcase,
            $this->context->event_dispatcher
        );

        if ($this->isPasswordAccessGranted($repo)) {
            return;
        }

        $display_error = false;

        if ($this->context->mybb->request_method === 'post') {
            if (!hash_equals($this->context->showcase->password, $this->context->mybb->get_input('password'))) {
                $display_error = true;
            } else {
                $this->recordPasswordAccess($repo);
                return;
            }
        }

        output_page(
            $this->context->render_manager->templateGet(
                'pageAccess',
                [
                    'display_error' => $display_error,
                    'http_host' => $_SERVER['HTTP_HOST'],
                    'request_uri' => $_SERVER['REQUEST_URI'],
                ]
            )
        );

        exit;
    }

    private function isPasswordAccessGranted(AccessLogsRepository $repo): bool
    {
        $where_clauses = [
            "user_id='{$this->context->current_user->uid}'",
            "is_valid='" . self::PASSWORD_IS_VALID . "'",
            "dateline>'" . (TIME_NOW - $this->context->showcase->password_minutes_interval * 60) . "'",
        ];

        if ($this->context->showcase->password_minutes_interval) {
            $where_clauses[] = "1=1 AND ipaddress={$this->context->database->escape_binary($this->context->mybb->session->packedip)}";
        }

        return (bool)$repo->queryAccessLogsCount($where_clauses);
    }

    private function recordPasswordAccess(AccessLogsRepository $repo): void
    {
        $repo->insertAccessLog([
            'user_id' => $this->context->current_user->uid,
            'dateline' => TIME_NOW,
            'ipaddress' => $this->context->mybb->session->packedip,
        ]);

        $this->context->mybb->request_method = 'get';
    }

    public function verifyPermission(?string $entry_slug = null, ?string $comment_slug = null): int
    {
        if (!$this->context->current_user->hasPermission(UserPermissions::MainCanView)) {
            return self::NO_PERMISSION;
        }

        if (!(
            $this->context->current_user->hasPermission(UserPermissions::EntriesCanView) ||
            $this->context->current_user->hasPermission(UserPermissions::EntriesCanModerate)
        )) {
            return self::NO_PERMISSION;
        }

        $entry = null;

        if ($entry_slug !== null) {
            $entry = $this->resolveEntryBySlug($entry_slug);
        }

        if ($comment_slug !== null) {
            if (!$this->context->showcase->comments_enable ||
                !$this->context->current_user->hasPermission(UserPermissions::CommentsCanView)
            ) {
                return self::NO_PERMISSION;
            }

            $comment_result = $this->resolveComment($comment_slug, $entry);

            if (is_int($comment_result)) {
                return $comment_result;
            }

            [$entry, $comment_data] = $comment_result;
        }

        if (empty($entry->entry_id)) {
            return self::INVALID_ENTRY;
        }

        if (!$this->isEntryAccessible($entry)) {
            return self::INVALID_ENTRY;
        }

        if ($comment_slug !== null && !$this->isCommentAccessible($comment_data)) {
            return self::INVALID_COMMENT;
        }

        hooksRun('controller_comments_verify_permissions_end', $this);

        return self::HAS_PERMISSION;
    }

    private function buildEntryStatusFilter(): string
    {
        return "(entry_data.status='" . ContentStatuses::VisibleApproved . "' OR ("
            . "entry_data.user_id='{$this->context->current_user->uid}' AND "
            . "entry_data.status='" . ContentStatuses::AwaitingApproval . "'))";
    }

    private function resolveEntryBySlug(string $entry_slug): ?EntryEntity
    {
        $where_clauses = ["entry_data.entry_slug='{$this->context->database->escape_string($entry_slug)}'"];

        if (!$this->context->current_user->hasPermission(UserPermissions::EntriesCanView)) {
            $where_clauses[] = $this->buildEntryStatusFilter();
        }

        return $this->loadEntryFromClauses($where_clauses);
    }

    private function resolveEntryById(int $entry_id): ?EntryEntity
    {
        $where_clauses = ["entry_data.entry_id='{$entry_id}'"];

        if (!$this->context->current_user->hasPermission(UserPermissions::EntriesCanView)) {
            $where_clauses[] = $this->buildEntryStatusFilter();
        }

        return $this->loadEntryFromClauses($where_clauses);
    }

    private function loadEntryFromClauses(array $where_clauses): ?EntryEntity
    {
        $raw = $this->context->entries_repository->fetchEntry(
            $this->context->custom_fields_collection,
            $where_clauses
        );

        $this->context->entries_manager->addEntry($raw);

        /** @var EntryEntity $entry */
        $entry = $this->context->entries_manager->getEntryByID($raw['entry_id']);

        $this->entry_id = $entry->entry_id;

        return $entry;
    }

    /**
     * Returns [EntryEntity, CommentEntity] on success, or an int error code.
     */
    private function resolveComment(string $comment_slug, ?EntryEntity $entry): array|int
    {
        $comments_repository = new CommentsRepository($this->context->database, $this->context->showcase);

        $comment_data = $comments_repository->queryComment(
            ["comment_slug='{$this->context->database->escape_string($comment_slug)}'"],
            ['comment_id', 'entry_id', 'status', 'user_id', 'dateline'],
        );

        if (empty($comment_data)) {
            return self::INVALID_COMMENT;
        }

        $this->comment_id = (int)$comment_data['comment_id'];

        if ($entry === null) {
            $entry = $this->resolveEntryById((int)$comment_data['entry_id']);
        }

        $this->context->comments_manager->addComment($comment_data);

        /** @var CommentEntity $comment */
        $comment = $this->context->comments_manager->getComments()->offsetGet($this->comment_id);

        return [$entry, $comment];
    }

    private function isEntryAccessible(EntryEntity $entry): bool
    {
        if ((int)$entry->status === ContentStatuses::VisibleApproved) {
            return true;
        }

        if ((int)$entry->status === ContentStatuses::AwaitingApproval &&
            $this->context->current_user->uid === (int)$entry->user_id
        ) {
            return true;
        }

        if ((int)$entry->status === ContentStatuses::SoftDeleted &&
            $this->context->current_user->hasPermission(UserPermissions::EntriesCanView)
        ) {
            return true;
        }

        return false;
    }

    private function isCommentAccessible(CommentEntity $comment): bool
    {
        if ($comment->status === ContentStatuses::VisibleApproved) {
            return true;
        }

        if ($comment->status === ContentStatuses::AwaitingApproval &&
            $this->context->current_user->uid === $comment->user_id
        ) {
            return true;
        }

        if ($comment->status === ContentStatuses::SoftDeleted &&
            $this->context->current_user->hasPermission(UserPermissions::CommentsCanView)
        ) {
            return true;
        }

        return false;
    }

    #[NoReturn] public function redirect(?string $entry_slug = null, ?string $comment_slug = null): void
    {
        switch ($this->verifyPermission($entry_slug, $comment_slug)) {
            case self::NO_PERMISSION:
                $this->errorNoPermission();
                break;
            case self::INVALID_ENTRY:
                $this->error($this->context->language->myShowcaseReportErrorInvalidEntry);
        }

        /** @var EntryEntity $entry */
        $entry = $this->context->entries_manager->getEntryByID($this->entry_id);

        /** @var CommentEntity $comment_data */
        $comment_data = $this->context->comments_manager->getComments()->offsetGet($this->comment_id);

        $redirect_url = $comment_slug
            ? $this->buildCommentRedirectUrl($entry, $comment_data, $comment_slug)
            : $this->buildEntryRedirectUrl($entry);

        header("Location: {$redirect_url}", response_code: self::HTTP_CODE_PERMANENT_REDIRECT);

        exit;
    }

    private function buildCommentRedirectUrl(
        EntryEntity $entry,
        CommentEntity $comment_data,
        string $comment_slug
    ): string {
        $where_clauses = [
            "entry_id='{$entry->entry_id}'",
            "showcase_id='{$this->context->showcase->showcase_id}'",
            "dateline<='" . date('Y-m-d H:i:s', $comment_data->dateline->getTimestamp()) . "'",
        ];

        if (!$this->context->current_user->hasPermission(UserPermissions::EntriesCanView) &&
            !$this->context->current_user->hasPermission(UserPermissions::CommentsCanView)
        ) {
            $where_clauses[] = "(status='" . ContentStatuses::VisibleApproved
                . "' OR (user_id='{$this->context->current_user->uid}' AND status='" . ContentStatuses::AwaitingApproval . "'))";
        }

        $comments_repository = new CommentsRepository($this->context->database, $this->context->showcase);
        $total_comments = $comments_repository->queryCommentCount($where_clauses);

        $url_params = [
            'entry_slug' => $entry->entry_slug,
            'entry_slug_custom' => $entry->entry_slug_custom,
            'comment_slug' => $comment_slug,
        ];

        $current_page = $this->calculateCommentPage($total_comments);

        if ($current_page > 1) {
            $url_params['page'] = $current_page;
        }

        return $this->context->showcase->showcase_url
            . $this->context->routing->generateUrl(Routes::EntryView, $url_params)
            . '#' . $comment_slug;
    }

    private function buildEntryRedirectUrl(EntryEntity $entry): string
    {
        return $this->context->showcase->showcase_url
            . $this->context->routing->generateUrl(
                Routes::EntryView,
                array_merge(
                    $this->context->render_manager->url_params,
                    [
                        'entry_slug' => $entry->entry_slug,
                        'entry_slug_custom' => $entry->entry_slug_custom,
                    ]
                )
            )
            . '#' . $entry->entry_slug;
    }

    private function calculateCommentPage(int $total_comments): int
    {
        $per_page = $this->context->showcase->comments_per_page;

        if (($total_comments % $per_page) === 0) {
            return $total_comments / $per_page;
        }

        return (int)($total_comments / $per_page) + 1;
    }

    public function forcedFieldBreadcrumb(?int $user_id = null): void
    {
        if ($this->context->showcase->main_filter_force_field !== FilterFields::UserID || $user_id === null) {
            return;
        }

        $user_data = get_user($user_id);

        if (empty($user_data['uid']) ||
            (empty($this->context->mybb->usergroup['canviewprofiles']) &&
                (int)$user_data['uid'] !== $this->context->current_user->uid)
        ) {
            $this->errorNoPermission();
        }

        $this->context->language->load('member');

        add_breadcrumb(
            $this->context->language->sprintf(
                $this->context->language->nav_profile,
                htmlspecialchars_uni($user_data['username'])
            ),
            $this->context->mybb->settings['bburl'] . '/' . get_profile_link($user_data['uid'])
        );
    }

    protected function loadEntryFields(EntryEntity $entry, bool $is_create_update_page = false): void
    {
        static $fields_factory = null;

        if ($fields_factory === null) {
            $fields_factory = new FieldFactory();
        }

        /** @var EntryCustomField $field */
        foreach ($entry->getCustomFields() as $field) {
            $field->getParsedValue();
        }

        $attachment_types = (new CacheManager($this->context->database, $this->context->mybb->cache))
            ->get(CacheDataTypes::AttachmentTypes)[$this->context->showcase->showcase_id] ?? [];

        /** @var FieldsInterface $field */
        foreach ($entry->getFields() as $field) {
            $is_viewable = $is_create_update_page
                ? $field->isViewableInForm()
                : $field->isViewableInEntry();

            if (!$is_viewable) {
                continue;
            }

            $this->attachFieldAttachments($field, $entry);

            $field->fetchSelectableValues($field->field_definition['set_id'], $is_create_update_page);

            $field
                ->valueSet($entry->getExtraData($field->field_definition['field_key']) ?? '')
                ->valuesBuild(
                    $this->context->attachments_manager,
                    $this->context->attachments_repository,
                    $entry,
                    $field->field_definition['set_id'],
                    $this->context->mybb->input
                );

            $field->attachmentsBuild($this->context->render_manager, $entry);

            if ($field->field_definition['html_type'] === FieldHtmlTypes::File) {
                $field->setExtraData(
                    'accept_element',
                    implode(',', array_merge(
                        array_column($attachment_types, 'mime_type'),
                        array_column($attachment_types, 'file_extension')
                    ))
                );
            }

            $this->buildFieldEditorWidgets($field, $is_create_update_page);
        }
    }

    private function attachFieldAttachments(FieldsInterface $field, EntryEntity $entry): void
    {
        foreach (
            $entry->getAttachments()->filter(
                fn(AttachmentEntity $attachment): bool =>
                    $attachment->field_id === $field->field_definition['field_id']
            ) as $attachment_data
        ) {
            $field->addAttachment($attachment_data);
        }
    }

    private function buildFieldEditorWidgets(FieldsInterface $field, bool $is_create_update_page): void
    {
        if (!$field->field_definition['enable_editor'] || !$is_create_update_page) {
            $field->setExtraData('code_buttons', '')->setExtraData('smiles_inserter', '');
            return;
        }

        $code_buttons = $smiles_inserter = '';

        $this->context->render_manager->editorBuild(
            $code_buttons,
            $smiles_inserter,
            $field->field_definition['field_key']
        );

        $field->setExtraData('code_buttons', $code_buttons)->setExtraData('smiles_inserter', $smiles_inserter);
    }

    #[NoReturn] public function success(object|array $params = [], int $status_code = 200): void
    {
        header("Content-type: application/json; charset={$this->context->language->settings['charset']}");

        http_response_code($status_code);

        echo json_encode($params);

        exit;
    }

    #[NoReturn] public function error(string $error = '', int $status_code = 500): void
    {
        if ($this->is_api_request) {
            header("Content-type: application/json; charset={$this->context->language->settings['charset']}");

            http_response_code($status_code);

            echo json_encode(['error' => empty($error) ? $this->context->language->unknown_error : $error]);
        } else {
            error($error, status_code: $status_code);
        }

        exit;
    }

    #[NoReturn] public function errorNoPermission(int $status_code = 401): void
    {
        if ($this->is_api_request) {
            $this->error($this->context->language->error_nopermission_guest_1, $status_code);
        } else {
            error_no_permission();
        }
    }
}
