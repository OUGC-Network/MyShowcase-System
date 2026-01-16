<?php

declare(strict_types=1);

namespace MyShowcase\Service;

use DB_MySQLi;
use DB_PgSQL;
use DB_SQLite;
use MyShowcase\Entity\User;
use MyShowcase\Enum\SortDirections;
use MyShowcase\Enum\UserPermissions;
use MyShowcase\Event\Event\EntryDeletedEvent;
use MyShowcase\Event\EventDispatcher;
use MyShowcase\Event\Listener\EntryDeletedCommentListener;
use MyShowcase\Enum\ModeratorPermissions;
use MyShowcase\Entity\Comment;
use MyShowcase\Repository\CommentsRepository;
use MysqlPdoDbDriver;
use PostgresPdoDbDriver;
use Random\RandomException;

use function MyShowcase\Plugin\Functions\getSetting;
use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\postParser;

class CommentsManager
{
    public const STATUS_PENDING = 0;

    public const STATUS_VISIBLE = 1;

    public const STATUS_DELETED = 2;

    private const  FIRST_PAGE = 1;

    private int $total_comments = 0;

    private int $total_pages = 1;

    private array $where_clauses = [];

    private array $query_fields = [
        'comment_id',
        'comment_slug',
        'showcase_id',
        'entry_id',
        'user_id',
        'parent_comment_id',
        'post_hash',
        'ipaddress',
        'message',
        'dateline',
        'status',
        'edit_stamp',
        'edit_user_id',
    ];

    private array $query_options = [
        'order_by' => 'dateline',
        'order_dir' => SortDirections::Ascending,
        'limit_start' => 0
    ];

    private array $comments = [];

    public function __construct(
        private readonly DB_SQLite|DB_PgSQL|PostgresPdoDbDriver|MysqlPdoDbDriver|DB_MySQLi $database,
        private readonly User $current_user,
        private readonly CommentsRepository $comments_repository,
        private readonly EventDispatcher $event_dispatcher,
        private readonly array $config,
        private int $current_page,
    ) {
        $this->event_dispatcher->addListener(
            EntryDeletedEvent::NAME,
            new EntryDeletedCommentListener($comments_repository)
        );
    }

    public function setCurrentPage(int $page): void
    {
        if ($page > 0) {
            $this->current_page = $page;
        }
    }

    public function getTotalComments(): int
    {
        return $this->total_comments;
    }

    /**
     * @return iterable<Comment>
     */
    public function getTopLevelComments(): iterable
    {
        foreach ($this->comments as $comment_id => $comment_data) {
            if (empty($comment_data->parent_comment_id)) {
                yield $comment_data;
            }
        }
    }

    public function getCurrentPage(): int
    {
        return $this->current_page;
    }

    /**
     * @throws RandomException
     */
    public function slugGenerate(?int $length = null): string
    {
        if (empty($length)) {
            $length = getSetting('slugLength');
        }

        $generated_slug = '';

        $unique_found = false;

        while (!$unique_found) {
            $generated_slug = my_strtolower(bin2hex(random_bytes($length)));

            $unique_found = !$this->comments_repository->queryComment(
                ["comment_slug='{$this->database->escape_string($generated_slug)}'"]
            );
        }

        return $generated_slug;
    }

    public function fetchComments(int $entry_id): array
    {
        $this->where_clauses = [
            "entry_id='{$entry_id}'",
        ];

        if (!$this->current_user->hasPermission(UserPermissions::EntriesCanView) &&
            !$this->current_user->hasPermission(UserPermissions::CommentsCanView)) {
            $this->where_clauses[] = "(status='" . self::STATUS_VISIBLE . "' OR (user_id='{$this->current_user->user_id}' AND status='" . self::STATUS_PENDING . "'))";
        }

        $this->query_options['limit'] = $this->config['comments_per_page'];

        hooksRun('comment_fetch_start', $this);

        $this->total_comments = $this->comments_repository->queryCommentCount($this->where_clauses);

        $this->total_pages = (int)ceil($this->total_comments / $this->config['comments_per_page']);

        if ($this->current_page > $this->total_pages) {
            $this->setCurrentPage(self::FIRST_PAGE);
        }

        if ($this->current_page) {
            $this->query_options['limit_start'] = ($this->current_page - 1) * $this->config['comments_per_page'];
        } else {
            $this->setCurrentPage(self::FIRST_PAGE);
        }

        hooksRun('comment_fetch_intermediate', $this);

        $entry_objects = [];

        foreach (
            $this->comments_repository->queryComments(
                $this->where_clauses,
                $this->query_fields,
                $this->query_options
            ) as $comment_data
        ) {
            $entry_objects[] = $comment_data;
        }

        hooksRun('comment_fetch_end', $this);

        return $entry_objects;
    }

    /**
     * Sets the data to be used for the data handler
     *
     * @param Comment $entry
     * @param string $method
     * @return void
     */
    public function validateSet(Comment $entry, string $method = self::VALIDATION_METHOD_INSERT): void
    {
        $this->validation_data = $entry;

        $this->validation_method = $method;
    }

    /**
     * Add an error to the error array.
     *
     * @param string $error The error name.
     * @param array $data
     */
    public function validateSetError(string $error, array $data = []): void
    {
        $this->validation_errors[$error] = $this->language->sprintf($error, ...$data);
    }

    /**
     * @return array
     */
    public function validateGetError(): array
    {
        return $this->validation_errors;
    }

    public function validateComment(): bool
    {
        $hookArguments = [
            'dataHandler' => &$this,
        ];

        $hookArguments = hooksRun('data_handler_comment_validate_start', $hookArguments);

        $commentData = $this->data;

        if (isset($commentData['showcase_id']) && (int)$commentData['showcase_id'] !== $this->showcase_id) {
            $this->set_error('invalid showcase identifier');
        }

        if (isset($commentData['entry_id']) && (int)$commentData['entry_id'] !== $this->entry_id) {
            $this->set_error('invalid entry identifier');
        }

        if (!empty($commentData['user_id']) && empty(get_user($this->data['user_id'])['uid'])) {
            $this->set_error('invalid user identifier');
        }

        if (isset($commentData['message']) || $this->method === self::METHOD_INSERT) {
            if (getSetting('parserMyCodeAffectsLength')) {
                $commentLength = my_strlen($this->data['message']);
            } else {
                $commentLength = my_strlen(postParser()->text_parse_message($this->data['message']));
            }

            if ($commentLength < $this->config['comments_message_minimum_length']) {
                $this->set_error('the message is too short');
            }

            if ($commentLength > $this->config['comments_message_maximum_length']) {
                $this->set_error('the message is too large');
            }
        }

        if (isset($commentData['status']) && !in_array(
                $this->data['status'],
                [
                    $this->comments_manager::STATUS_PENDING,
                    $this->comments_manager::STATUS_VISIBLE,
                    $this->comments_manager::STATUS_DELETED,
                ]
            )) {
            $this->set_error('invalid status');
        }

        if (!empty($commentData['edit_user_id']) && empty(get_user($this->data['edit_user_id'])['uid'])) {
            $this->set_error('invalid edit user identifier');
        }

        $this->set_validated();

        $hookArguments = hooksRun('data_handler_comment_validate_end', $hookArguments);

        if ($this->get_errors()) {
            return false;
        }

        return true;
    }

    /**
     * @throws RandomException
     */
    public function validationCommentInsert(bool $isUpdate = false, int $commentID = 0): array
    {
        global $db;

        if (!$this->get_validated()) {
            die('The comment needs to be validated before inserting it into the DB.');
        }

        if (count($this->get_errors()) > 0) {
            die('The comment is not valid.');
        }

        $this->comment_id = $commentID;

        $hookArguments = [
            'dataHandler' => &$this,
            'isUpdate' => &$isUpdate,
        ];

        if ($isUpdate && !isset($this->return_data['comment_slug'])) {
            $this->return_data['comment_slug'] = $this->comments_repository->queryComment(
                ["comment_id='{$this->comment_id}'"],
                ['comment_slug'],
            )['comment_slug'];
        } elseif (!$isUpdate) {
            $this->return_data['comment_slug'] = $this->data['comment_slug'] = $this->comments_manager->slugGenerate();
        }

        foreach ($this->data as $key => $value) {
            $this->insert_data[$key] = $value;
        }

        $hookArguments = hooksRun('data_handler_comment_insert_update_start', $hookArguments);

        if ($isUpdate) {
            $this->comments_repository->updateComment($this->insert_data, $this->comment_id);
        } else {
            $this->comment_id = $this->comments_repository->insertComment(
                array_merge($this->insert_data, [
                    'entry_id' => $this->entry_id
                ])
            );
        }

        $this->return_data['comment_id'] = $this->comment_id;

        // Assign any uploaded attachments with the specific post_hash to the newly created comment.
        if (isset($this->data['post_hash'])) {
            foreach (
                $this->attachments_repository->queryAttachments(
                    ["post_hash='{$db->escape_string($this->data['post_hash'])}'"]
                ) as $attachmentID => $attachmentData
            ) {
                $this->attachments_repository->updateAttachment(
                    ['comment_id' => $this->comment_id, 'post_hash' => ''],
                    $attachmentID
                );
            }
        }

        if (isset($this->data['status'])) {
            $this->return_data['status'] = $this->data['status'];
        }

        $hookArguments = hooksRun('data_handler_comment_insert_update_end', $hookArguments);

        return $this->return_data;
    }

    /**
     * @throws RandomException
     */
    public function validationCommentUpdate(int $commentID): array
    {
        return $this->validationCommentInsert(true, $commentID);
    }
}