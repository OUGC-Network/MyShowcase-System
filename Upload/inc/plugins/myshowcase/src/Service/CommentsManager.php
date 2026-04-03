<?php

declare(strict_types=1);

namespace MyShowcase\Service;

use DB_MySQLi;
use DB_PgSQL;
use DB_SQLite;
use MyShowcase\Collection\Collection;
use MyShowcase\Entity\UserEntity;
use MyShowcase\Enum\ContentStatuses;
use MyShowcase\Enum\SortDirections;
use MyShowcase\Enum\UserPermissions;
use MyShowcase\Event\Event\EntryDeletedEvent;
use MyShowcase\Event\EventDispatcher;
use MyShowcase\Event\Listener\EntryDeletedCommentListener;
use MyShowcase\Entity\CommentEntity;
use MyShowcase\Repository\CommentsRepository;
use MyShowcase\ShowcaseContext;
use MyShowcase\Util\StringUtil;
use MysqlPdoDbDriver;
use PostgresPdoDbDriver;
use Random\RandomException;

use function MyShowcase\Plugin\Functions\castTableFieldValue;
use function MyShowcase\Plugin\Functions\getSetting;
use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\postParser;

use const MyShowcase\Plugin\Core\DATA_TABLE_STRUCTURE;

class CommentsManager
{
    public const  FIRST_PAGE = 1;
    private readonly CommentsRepository $comments_repository;
    private Collection $comments;

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
        'update_dateline',
        'edit_user_id',
    ];

    private array $query_options = [
        'order_by' => 'dateline',
        'order_dir' => SortDirections::Ascending,
        'limit_start' => 0
    ];

    public function __construct(
        public readonly ShowcaseContext $context,
        //private readonly DB_SQLite|DB_PgSQL|PostgresPdoDbDriver|MysqlPdoDbDriver|DB_MySQLi $database,
        //private readonly User $current_user,
        //private readonly EventDispatcher $event_dispatcher,
        //private readonly ConfigManager $config,
    )
    {
        $this->context->event_dispatcher->addListener(
            EntryDeletedEvent::NAME,
            new EntryDeletedCommentListener($this->context->comments_repository)
        );

        $this->comments = new Collection();
    }

    public function getComments(): Collection
    {
        return $this->comments;
    }

    public function addComment(array $comment_data): void
    {
        $comment = $this->generateComment($comment_data);

        if ($this->comments->contains($comment)) {
            return;
        }

        $this->comments[$comment_data['comment_id']] = $comment;
    }

    public function generateComment(array $comment_data = []): CommentEntity
    {
        foreach (DATA_TABLE_STRUCTURE['myshowcase_data'] as $field_name => $field_definition) {
            if (isset($comment_data[$field_name])) {
                $comment_data[$field_name] = castTableFieldValue(
                    $comment_data[$field_name],
                    $field_definition['type']
                );
            }
        }

        return new CommentEntity(...$comment_data);
    }

    /**
     * @return iterable<CommentEntity>
     */
    public function getTopLevelComments(): iterable
    {
        foreach ($this->comments as $comment_id => $comment_data) {
            if (empty($comment_data->parent_comment_id)) {
                yield $comment_data;
            }
        }
    }

    /**
     * Sets the data to be used for the data handler
     *
     * @param CommentEntity $comment
     * @param string $method
     * @return void
     */
    public function validateSet(CommentEntity $comment, string $method = self::VALIDATION_METHOD_INSERT): void
    {
        $this->validation_data = $comment;

        $this->validation_method = $comment;
    }

    /**
     * Add an error to the error array.
     *
     * @param string $error The error name.
     * @param array $data
     */
    public function validateSetError(string $error, array $data = []): void
    {
        $this->validation_errors[$error] = $this->context->language->sprintf($error, ...$data);
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

        if (isset($this->validation_data->message)) {
            if ($this->context->showcase->parser_affects_message_length_in_entries) {
                $message_length = my_strlen($this->validation_data->message);
            } else {
                $message_length = my_strlen((new StringUtil())->stripCodes($this->validation_data->message));
            }

            if ($message_length < $this->context->showcase->comments_message_minimum_length ||
                $message_length > $this->context->showcase->comments_message_maximum_length) {
                $this->validateSetError(
                    $this->context->language->myshowcase_validation_errors_comment_invalid_message_length,
                    [
                        my_number_format($this->context->showcase->comments_message_minimum_length),
                        my_number_format($this->context->showcase->comments_message_maximum_length)
                    ]
                );
            }
        }

        if (isset($this->validation_data->icon_id) && !$this->context->showcase->comments_allow_post_icons) {
            unset($this->validation_data->icon_id);
        }

        if ($this->validation_data->icon_id !== self::POST_ICON_NONE &&
            !isset($this->context->mybb->cache->read('posticons')[$this->validation_data->icon_id])) {
            $this->validateSetError($this->context->language->myshowcase_validation_errors_comment_invalid_icon);
        }

        if (isset($commentData['status']) && !in_array(
                $this->data['status'],
                [
                    ContentStatuses::AwaitingApproval,
                    ContentStatuses::VisibleApproved,
                    ContentStatuses::SoftDeleted,
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
            $this->return_data['comment_slug'] = $this->context->comments_repository->queryComment(
                ["comment_id='{$this->comment_id}'"],
                ['comment_slug'],
            )['comment_slug'];
        } elseif (!$isUpdate) {
            $unique_found = false;

            while (!$unique_found) {
                $generated_slug = (new StringUtil())->generateCommentSlug(getSetting('slugLength'));

                $unique_found = !$this->context->comments_repository->queryComment(
                    ["comment_slug='{$this->context->database->escape_string($generated_slug)}'"]
                );

                if ($unique_found) {
                    $this->return_data['comment_slug'] = $this->data['comment_slug'] = $generated_slug;
                }
            }
        }

        foreach ($this->data as $key => $value) {
            $this->insert_data[$key] = $value;
        }

        $hookArguments = hooksRun('data_handler_comment_insert_update_start', $hookArguments);

        if ($isUpdate) {
            $this->context->comments_repository->updateComment($this->insert_data, $this->comment_id);
        } else {
            $this->comment_id = $this->context->comments_repository->insertComment(
                array_merge($this->insert_data, [
                    'entry_id' => $this->entry_id
                ])
            );
        }

        $this->return_data['comment_id'] = $this->comment_id;

        // Assign any uploaded attachments with the specific post_hash to the newly created comment.
        if (isset($this->data['post_hash'])) {
            foreach (
                $this->context->attachments_repository->queryAttachments(
                    ["post_hash='{$db->escape_string($this->data['post_hash'])}'"]
                ) as $attachmentID => $attachmentData
            ) {
                $this->context->attachments_repository->updateAttachment(
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