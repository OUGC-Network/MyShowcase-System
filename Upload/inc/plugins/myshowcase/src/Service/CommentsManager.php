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
use MyShowcase\Event\EventDispatcher;
use MyShowcase\Entity\CommentEntity;
use MyShowcase\Repository\CommentsRepository;
use MyShowcase\ShowcaseContext;
use MyShowcase\Util\StringUtil;
use MysqlPdoDbDriver;
use PostgresPdoDbDriver;
use Random\RandomException;

use function MyShowcase\Plugin\Functions\stringKeysOnly;
use function MyShowcase\Plugin\Functions\castTableFieldValue;
use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\postParser;

use const MyShowcase\Plugin\Core\DATA_TABLE_STRUCTURE;

class CommentsManager
{
    public const FIRST_PAGE = 1;
    private readonly CommentsRepository $comments_repository;
    private Collection $comments;

    private int $total_comments = 0;

    private int $total_pages = 1;

    private array $where_clauses = [];

    private array $query_fields = [
        'comment_id',
        'public_id',
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

        return new CommentEntity(...stringKeysOnly($comment_data));
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

    public const VALIDATION_METHOD_INSERT = 'insert';

    public const VALIDATION_METHOD_UPDATE = 'update';

    public const POST_ICON_NONE = 0;

    private ?CommentEntity $validation_data = null;

    private string $validation_method = self::VALIDATION_METHOD_INSERT;

    private array $validation_errors = [];

    private bool $validated = false;

    private int $comment_id = 0;

    /**
     * Sets the comment to validate and to store.
     */
    public function validateSet(CommentEntity $comment, string $method = self::VALIDATION_METHOD_INSERT): void
    {
        $this->validation_data = $comment;

        $this->validation_method = $method;

        $this->validation_errors = [];

        $this->validated = false;
    }

    /**
     * Add an error to the error array.
     *
     * @param string $error The error text (a language string with {1}, {2}, ... placeholders is accepted).
     * @param array $data
     */
    public function validateSetError(string $error, array $data = []): void
    {
        $this->validation_errors[$error] = $data ? $this->context->language->sprintf($error, ...$data) : $error;
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

        $comment = $this->validation_data;

        if ($comment === null) {
            $this->validateSetError('The comment to validate was not set.');

            return false;
        }

        if (isset($comment->message)) {
            if ($this->context->showcase->parser_affects_message_length_in_comments) {
                $message_length = my_strlen($comment->message);
            } else {
                $message_length = my_strlen((new StringUtil())->stripCodes($comment->message));
            }

            $minimum_length = (int)$this->context->showcase->comments_message_minimum_length;

            // A maximum of 0 means no limit.
            $maximum_length = (int)$this->context->showcase->comments_message_maximum_length;

            if ($message_length < $minimum_length || ($maximum_length > 0 && $message_length > $maximum_length)) {
                $this->validateSetError(
                    $this->context->language->myshowcase_validation_errors_comment_invalid_message_length,
                    [
                        my_number_format($minimum_length),
                        $maximum_length > 0 ? my_number_format($maximum_length) : '∞',
                    ]
                );
            }
        }

        if ($this->context->showcase->comments_allow_post_icons && !empty($comment->icon_id) &&
            $comment->icon_id !== self::POST_ICON_NONE &&
            !isset($this->context->mybb->cache->read('posticons')[$comment->icon_id])) {
            $this->validateSetError($this->context->language->myshowcase_validation_errors_comment_invalid_icon);
        }

        if (isset($comment->status) && !in_array(
                $comment->status,
                [
                    ContentStatuses::AwaitingApproval,
                    ContentStatuses::VisibleApproved,
                    ContentStatuses::SoftDeleted,
                ],
                true
            )) {
            $this->validateSetError('The comment status is invalid.');
        }

        $this->validated = true;

        $hookArguments = hooksRun('data_handler_comment_validate_end', $hookArguments);

        return !$this->validation_errors;
    }

    /**
     * Stores the validated comment. Returns comment_id, public_id and status.
     *
     * @throws RandomException
     */
    public function validationCommentInsert(bool $isUpdate = false, int $commentID = 0): array
    {
        if (!$this->validated || $this->validation_data === null) {
            throw new \LogicException('The comment needs to be validated before inserting it into the database.');
        }

        if ($this->validation_errors) {
            throw new \LogicException('The comment is not valid.');
        }

        $comment = $this->validation_data;

        $this->comment_id = $commentID;

        $return_data = [];

        $insert_data = [];

        foreach (['message', 'status', 'icon_id', 'post_hash', 'ipaddress', 'user_id', 'entry_id'] as $column) {
            if (isset($comment->$column)) {
                $insert_data[$column] = $comment->$column;
            }
        }

        $hookArguments = [
            'dataHandler' => &$this,
            'isUpdate' => &$isUpdate,
            'insertData' => &$insert_data,
        ];

        if ($isUpdate) {
            $return_data['public_id'] = $this->context->comments_repository->queryComment(
                ["comment_id='{$this->comment_id}'"],
                ['public_id'],
            )['public_id'] ?? '';

            $insert_data['update_dateline'] = new \DateTime('@' . TIME_NOW);

            $insert_data['edit_user_id'] = $this->context->current_user->uid;
        } else {
            do {
                $public_id = (new StringUtil())->generatePublicId();

                $unique_found = !$this->context->comments_repository->queryComment(
                    ["public_id='{$this->context->database->escape_string($public_id)}'"]
                );
            } while (!$unique_found);

            $return_data['public_id'] = $insert_data['public_id'] = $public_id;

            $insert_data['dateline'] = $comment->dateline ?? new \DateTime('@' . TIME_NOW);

            $insert_data['username'] = $this->context->current_user->username ?? '';
        }

        $hookArguments = hooksRun('data_handler_comment_insert_update_start', $hookArguments);

        if ($isUpdate) {
            $this->context->comments_repository->updateComment($insert_data, $this->comment_id);
        } else {
            $this->comment_id = $this->context->comments_repository->insertComment($insert_data);
        }

        $return_data['comment_id'] = $this->comment_id;

        // Assign any uploaded attachments with the specific post_hash to the newly created comment.
        if (!$isUpdate && !empty($comment->post_hash)) {
            $this->context->attachments_repository->linkAttachmentsByPostHash(
                $comment->post_hash,
                (int)$this->context->current_user->uid,
                ['comment_id' => $this->comment_id, 'post_hash' => '']
            );
        }

        if (isset($comment->status)) {
            $return_data['status'] = $comment->status;
        }

        $hookArguments = hooksRun('data_handler_comment_insert_update_end', $hookArguments);

        return $return_data;
    }

    /**
     * @throws RandomException
     */
    public function validationCommentUpdate(int $commentID): array
    {
        return $this->validationCommentInsert(true, $commentID);
    }
}
