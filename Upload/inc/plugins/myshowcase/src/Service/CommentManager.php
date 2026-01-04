<?php

declare(strict_types=1);

namespace myshowcase\src\Service;

use MyShowcase\System\Showcase;
use MyShowcase\System\ModeratorPermissions;
use MyShowcase\Entity\Comment;
use Random\RandomException;

use function MyShowcase\Plugin\Functions\getSetting;
use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\sanitizeTableFieldValue;

use const MyShowcase\Plugin\Core\COMMENT_STATUS_PENDING_APPROVAL;
use const MyShowcase\Plugin\Core\COMMENT_STATUS_VISIBLE;
use const MyShowcase\Plugin\Core\TABLES_DATA;

class CommentManager
{
    private const  FIRST_PAGE = 1;

    private int $total_comments = 0;

    private int $total_pages = 1;

    private array $query_options = ['order_by' => 'dateline', 'order_dir' => 'asc', 'limit_start' => 0, 'limit' => 10];

    private array $comments = [];

    public readonly ?int $comment_id;

    public array $where_clauses = [];

    public array $table_fields = TABLES_DATA['myshowcase_comments'];

    public array $insert_data = [];

    public array $comment_data = [];

    public readonly bool $is_update;

    public function __construct(
        private readonly Showcase $showcase,
        private $current_page = self::FIRST_PAGE,
    ) {
        return $this;
    }

    public function setCurrentPage(int $page): void
    {
        if ($page > 0) {
            $this->current_page = $page;
        }
    }

    private function setComment(array $comment_data): void
    {
        $this->comments[$comment_data['comment_id']] = new Comment(...$comment_data);
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
        foreach ($this->comments as $comment_i => $comment_data) {
            yield $comment_data;
        }
    }

    public function getCurrentPage(): int
    {
        return $this->current_page;
    }

    /**
     * @throws RandomException
     */
    public function generateSlug(?int $length = null): string
    {
        if (empty($length)) {
            $length = getSetting('slugLength');
        }

        global $db;

        $generatedSlug = '';

        $uniqueFound = false;

        while (!$uniqueFound) {
            $generatedSlug = my_strtolower(bin2hex(random_bytes($length)));

            $uniqueFound = !$this->queryComment(["comment_slug='{$db->escape_string($generatedSlug)}'"]);
        }

        return $generatedSlug;
    }

    /**
     * @return iterable<array>
     */
    public function queryComments(
        array $where_clauses = [],
        array|string $query_fields = [],
        array $query_options = []
    ): iterable {
        $where_clauses[] = "showcase_id='{$this->showcase->showcase_id}'";

        global $db;

        if (is_array($query_fields)) {
            $query_fields = implode(', ', $query_fields);
        }

        $query = $db->simple_select(
            'myshowcase_comments',
            $query_fields,
            implode(' AND ', $where_clauses),
            $query_options
        );

        while ($comment = $db->fetch_array($query)) {
            yield $comment;
        }
    }

    public function queryComment(
        array $where_clauses = [],
        array|string $query_fields = [],
        array $query_options = []
    ): array {
        $query_options['limit'] = 1;

        $comment_object = [];

        foreach (
            $this->queryComments(
                $where_clauses,
                $query_fields,
                $query_options
            ) as $comment_data
        ) {
            $comment_object = $comment_data;

            break;
        }

        return $comment_object;
    }

    public function queryCommentCount(
        array $where_clauses = []
    ): int {
        return $this->queryComment($where_clauses, 'COUNT(comment_id) AS total_comments')['total_comments'];
    }

    public function fetchComments(): self
    {
        $this->where_clauses = [
            "entry_id='{$this->showcase->entry_id}'",
        ];

        if (!$this->showcase->user_permissions[ModeratorPermissions::CanManageEntries]) {
            $this->where_clauses[] = "(status='" . COMMENT_STATUS_VISIBLE . "' OR (user_id='{$this->showcase->current_user_id}' AND status='" . COMMENT_STATUS_PENDING_APPROVAL . "}'))";
        }

        $this->query_options['limit'] = $this->showcase->config['comments_per_page'];

        hooksRun('comment_fetch_start', $this);

        $this->total_comments = $this->queryCommentCount($this->where_clauses);

        $this->total_pages = (int)ceil($this->total_comments / $this->showcase->config['comments_per_page']);

        if ($this->current_page > $this->total_pages) {
            $this->setCurrentPage(self::FIRST_PAGE);
        }

        if ($this->current_page) {
            $this->query_options['limit_start'] = ($this->current_page - 1) * $this->showcase->config['comments_per_page'];
        } else {
            $this->setCurrentPage(self::FIRST_PAGE);
        }

        hooksRun('comment_fetch_intermediate', $this);

        foreach (
            $this->queryComments(
                $this->where_clauses,
                [
                    'comment_id',
                    'comment_slug',
                    'showcase_id',
                    'entry_id',
                    'user_id',
                    'post_hash',
                    'ipaddress',
                    'comment',
                    'dateline',
                    'status',
                    'edit_user_id'
                ],
                $this->query_options
            ) as $comment_data
        ) {
            $this->setComment($comment_data);
        }

        return $this;
    }

    public function insertComment(array $comment_data, bool $is_update = false, ?int $comment_id = null): int
    {
        global $db;

        $this->comment_data = $comment_data;

        $this->comment_id = $comment_id;

        $this->insert_data = [
            'showcase_id' => $this->showcase->showcase_id,
        ];

        foreach ($this->table_fields as $fieldName => $fieldDefinition) {
            if (isset($this->comment_data[$fieldName])) {
                $this->insert_data[$fieldName] = sanitizeTableFieldValue(
                    $this->comment_data[$fieldName],
                    $fieldDefinition['type']
                );
            }
        }

        hooksRun('comment_insert_update', $this);

        if ($this->is_update) {
            $db->update_query('myshowcase_comments', $this->insert_data, "comment_id='{$this->comment_id}'");

            return $this->comment_id;
        } else {
            return (int)$db->insert_query('myshowcase_comments', $this->insert_data);
        }
    }

    public function updateComment(array $comment_data, int $comment_id = 0): int
    {
        return $this->insertComment($comment_data, true, $comment_id);
    }

    public function deleteeComment(int $comment_id): bool
    {
        global $db;

        $this->comment_id = $comment_id;

        hooksRun('comment_delete', $this);

        $db->delete_query(
            'myshowcase_comments',
            "showcase_id='{$this->showcase->showcase_id}' AND comment_id='{$comment_id}'"
        );

        return true;
    }
}