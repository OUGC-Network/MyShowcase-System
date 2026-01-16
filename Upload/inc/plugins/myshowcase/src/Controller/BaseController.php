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

use DB_MySQLi;
use DB_PgSQL;
use DB_SQLite;
use JetBrains\PhpStorm\NoReturn;
use MyBB;
use MyLanguage;
use MyShowcase\Entity\Attachment;
use MyShowcase\Entity\Comment;
use MyShowcase\Entity\Entry;
use MyShowcase\Entity\User;
use MyShowcase\Enum\FieldHtmlTypes;
use MyShowcase\Enum\Routes;
use MyShowcase\Enum\UserPermissions;
use MyShowcase\Event\EventDispatcher;
use MyShowcase\Fields\FieldFactory;
use MyShowcase\Fields\FieldsInterface;
use MyShowcase\Repository\CommentsRepository;
use MyShowcase\Repository\EntriesRepository;
use MyShowcase\Routing\Routing;
use MyShowcase\Service\CommentsManager;
use MyShowcase\Service\EntriesManager;
use MyShowcase\Service\Render;
use MyShowcase\Enum\FilterFields;
use MyShowcase\Repository\AccessLogsRepository;
use MysqlPdoDbDriver;
use PostgresPdoDbDriver;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

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

    protected int $entry_id = 0;

    protected int $comment_id = 0;

    /**
     */
    public function __construct(
        private readonly Routing $routing,
        private readonly DB_SQLite|DB_PgSQL|PostgresPdoDbDriver|MysqlPdoDbDriver|DB_MySQLi $database,
        private readonly MyBB $mybb,
        private readonly MyLanguage $language,
        private readonly EventDispatcher $event_dispatcher,
        private readonly User $current_user,
        private readonly EntriesRepository $entries_repository,
        private readonly CommentsRepository $comments_repository,
        private readonly EntriesManager $entries_manager,
        private readonly CommentsManager $comments_manager,
        private readonly Render $render_manager,
        private readonly FieldFactory $field_factory,
        private readonly array $config,
        private readonly array $fieldset_fields,
        private readonly array $filter,
    ) {
        //make sure this file is current
        if (SHOWCASE_FILE_VERSION_CODE < VERSION_CODE) {
            error(
                'This file is not the same version as the MyShowcase System. Please be sure to upload and configure ALL files.'
            );
        }

        //try to load showcase specific language file
        loadLanguage();

        loadLanguage('myshowcase' . $config['showcase_id'], false, true);
    }

    /**
     * @throws SyntaxError
     * @throws RuntimeError
     * @throws LoaderError
     */
    protected function verifyPassword(): void
    {
        if ($this->config['password'] === '') {
            return;
        }

        $access_logs_repository = new AccessLogsRepository($this->database, $this->config, $this->event_dispatcher);

        $where_clauses = [
            "user_id='{$this->current_user->user_id}'",
            "is_valid='" . self::PASSWORD_IS_VALID . "'",
            "dateline>'" . (TIME_NOW - $this->config['password_minutes_interval'] * 60) . "'",
        ];

        if ($this->config['password_minutes_interval']) {
            $where_clauses[] = "1=1 AND ipaddress={$this->database->escape_binary($this->mybb->session->packedip)}";
        }

        $existingLogs = $access_logs_repository->queryAccessLogsCount($where_clauses);

        if ($existingLogs) {
            return;
        }

        $display_error = false;

        if ($this->mybb->request_method === 'post') {
            if (!hash_equals($this->config['password'], $this->mybb->get_input('password'))) {
                $display_error = true;
            } else {
                $access_logs_repository->insertAccessLog([
                    'user_id' => $this->current_user->user_id,
                    'dateline' => TIME_NOW,
                    'ipaddress' => $this->mybb->session->packedip,
                ]);

                $this->mybb->request_method = 'get';

                return;
            }
        }

        output_page(
            $this->render_manager->templateGet(
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

    public function verifyPermission(?string $entry_slug = null, ?string $comment_slug = null): int
    {
        if (!$this->current_user->hasPermission(UserPermissions::MainCanView)) {
            return self::NO_PERMISSION;
        }

        global $db;

        if (!(
            $this->current_user->hasPermission(UserPermissions::EntriesCanView) ||
            $this->current_user->hasPermission(UserPermissions::EntriesCanView)
        )) {
            return self::NO_PERMISSION;
        }

        if ($entry_slug !== null) {
            $entry = $this->entries_manager->fetchEntry(
                ["entry_data.entry_slug='{$db->escape_string($entry_slug)}'"],
            );

            $this->entries_manager->addEntry($entry);

            /**
             * @var Entry $entry
             */
            $entry = $this->entries_manager->getEntryByID($entry['entry_id']);

            $this->entry_id = $entry->entry_id;
        }

        if ($comment_slug !== null) {
            if (!$this->config['comments_enable'] ||
                !(
                    $this->current_user->hasPermission(UserPermissions::CommentsCanView) ||
                    $this->current_user->hasPermission(UserPermissions::CommentsCanView)
                )) {
                return self::NO_PERMISSION;
            }

            $comment_data = $this->comments_repository->queryComment(
                ["comment_slug='{$db->escape_string($comment_slug)}'"],
                ['comment_id', 'entry_id', 'status', 'user_id', 'dateline'],
            );

            if (empty($comment_data)) {
                return self::INVALID_COMMENT;
            }

            $this->comment_id = (int)$comment_data['comment_id'];

            if (empty($entry)) {
                $entry = $this->entries_manager->fetchEntry(
                    ["entry_data.entry_id='{$comment_data['entry_id']}'"],
                );

                $this->entries_manager->addEntry($entry);

                /**
                 * @var Entry $entry
                 */
                $entry = $this->entries_manager->getEntryByID($entry['entry_id']);

                $this->entry_id = $entry->entry_id;
            }

            $entry->addComment($comment_data);

            /**
             * @var Comment $comment_data
             */
            $comment_data = $entry->getComments()->offsetGet($this->comment_id);
        }

        if (empty($entry->entry_id)) {
            return self::INVALID_ENTRY;
        }

        if (
            (int)$entry->status !== $this->entries_manager::STATUS_VISIBLE &&
            ((
                    (int)$entry->status === $this->entries_manager::STATUS_PENDING &&
                    $this->current_user->user_id !== (int)$entry->user_id
                ) ||
                (
                    (int)$entry->status === $this->entries_manager::STATUS_DELETED &&
                    !$this->current_user->hasPermission(UserPermissions::EntriesCanView)
                ))) {
            return self::INVALID_ENTRY;
        }

        if ($comment_slug !== null) {
            if (
                $comment_data->status !== $this->comments_manager::STATUS_VISIBLE &&
                ((
                        $comment_data->status === $this->comments_manager::STATUS_PENDING &&
                        $this->current_user->user_id !== $comment_data->user_id
                    ) ||
                    (
                        $comment_data->status === $this->comments_manager::STATUS_DELETED &&
                        !$this->current_user->hasPermission(UserPermissions::CommentsCanView)
                    ))) {
                return self::INVALID_COMMENT;
            }
        }

        hooksRun('controller_comments_verify_permissions_end', $this);

        return self::HAS_PERMISSION;
    }

    #[NoReturn] public function redirect(?string $entry_slug = null, ?string $comment_slug = null): void
    {
        switch ($this->verifyPermission($entry_slug, $comment_slug)) {
            case self::NO_PERMISSION:
                error_no_permission();

                break;
            case self::INVALID_ENTRY:
                global $lang;

                error($this->language->myShowcaseReportErrorInvalidEntry);
        }

        /**
         * @var Entry $entry
         */
        $entry = $this->entries_manager->getEntryByID($this->entry_id);

        /**
         * @var Comment $comment_data
         */
        $comment_data = $entry->getComments()->offsetGet($this->comment_id);

        if ($comment_slug) {
            $where_clauses = [
                "entry_id='{$entry->entry_id}'",
                "showcase_id='{$this->config['showcase_id']}'",
                "dateline<='" . $comment_data->dateline . "'",
            ];

            if (!$this->current_user->hasPermission(UserPermissions::EntriesCanView) &&
                !$this->current_user->hasPermission(UserPermissions::CommentsCanView)) {
                $where_clauses[] = "(status='" . $this->comments_manager::STATUS_VISIBLE . "' OR (user_id='{$this->current_user->user_id}' AND status='" . $this->comments_manager::STATUS_PENDING . "'))";
            }

            $total_comments = $this->comments_repository->queryCommentCount($where_clauses);

            if (($total_comments % $this->config['comments_per_page']) === 0) {
                $current_page = $total_comments / $this->config['comments_per_page'];
            } else {
                $current_page = (int)($total_comments / $this->config['comments_per_page']) + 1;
            }

            $url_params = [
                'entry_slug' => $entry->entry_slug,
                'entry_slug_custom' => $entry->entry_slug_custom,
                'comment_hash' => $comment_slug,
            ];

            if ($current_page > 1) {
                $url_params['page'] = $current_page;
            }

            $redirect_url = $this->config['showcase_url'] . $this->routing->generateUrl(
                    Routes::EntryView,
                    $url_params
                ) . '#' . $comment_slug;
        } else {
            $redirect_url = $this->config['showcase_url'] . $this->routing->generateUrl(
                    Routes::EntryView,
                    array_merge(
                        $this->render_manager->url_params,
                        [
                            'entry_slug' => $entry->entry_slug,
                            'entry_slug_custom' => $entry->entry_slug_custom,
                        ]
                    ),
                ) . '#' . $entry->entry_slug;
        }

        header("Location: {$redirect_url}", response_code: self::HTTP_CODE_PERMANENT_REDIRECT);

        exit;
    }

    public function forcedFieldBreadcrumb(?int $user_id = null): void
    {
        if ($this->config['main_filter_force_field'] === FilterFields::UserID && $user_id !== null) {
            $user_data = get_user($user_id);

            if (empty($user_data['uid']) ||
                (empty($this->mybb->usergroup['canviewprofiles']) && (int)$user_data['uid'] !== $this->current_user->user_id)) {
                error_no_permission();
            }

            $this->language->load('member');

            add_breadcrumb(
                $this->language->sprintf($this->language->nav_profile, htmlspecialchars_uni($user_data['username'])),
                $this->mybb->settings['bburl'] . '/' . get_profile_link($user_data['uid'])
            );
        }
    }

    protected function loadEntryFields(Entry $entry, bool $verify_fill_permission = false): void
    {
        foreach ($this->fieldset_fields as $field_id => $field_definition) {
            $entry->addField(
                $this->field_factory->create(
                    $field_definition,
                    $entry
                )
            );
        }

        /**
         * @var FieldsInterface $field_data
         */
        foreach ($entry->getFields() as $field_data) {
            if (!$field_data->isViewableInEntry()) {
                continue;
            }

            foreach (
                $entry->getAttachments()->filter(
                    function (Attachment $attachment) use ($field_data): bool {
                        return $attachment->field_id === $field_data->field_definition['field_id'];
                    }
                ) as $attachment_data
            ) {
                $field_data->addAttachment($attachment_data);
            }

            $field_data->fetchSelectableValues($verify_fill_permission);

            $field_data
                ->valueSet($entry->getExtraData($field_data->field_definition['field_key']) ?? '')
                ->valuesBuild($entry);

            $field_data->attachmentsBuild($this->render_manager);

            switch ($field_data->field_definition['html_type']) {
                case FieldHtmlTypes::File:
                    $field_data->setExtraData(
                        'accept_element',
                        implode(
                            ',',
                            array_merge(
                                array_column($this->attachment_types, 'mime_type'),
                                array_column($this->attachment_types, 'file_extension')
                            )
                        )
                    );
            }

            if ($field_data->field_definition['enable_editor']) {
                $code_buttons = $smiles_inserter = '';

                $this->render_manager->editorBuild(
                    $code_buttons,
                    $smiles_inserter,
                    $field_data->field_definition['field_key']
                );

                $field_data->setExtraData('code_buttons', $code_buttons);

                $field_data->setExtraData('smiles_inserter', $smiles_inserter);
            }
        }
    }
}