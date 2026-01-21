<?php

declare(strict_types=1);

namespace MyShowcase\Repository;

use DB_MySQLi;
use DB_PgSQL;
use DB_SQLite;
use MyShowcase\Entity\ShowcaseEntity;
use MyShowcase\Enum\ContentStatuses;
use MyShowcase\Enum\FieldHtmlTypes;
use MyShowcase\Enum\UserPermissions;
use MysqlPdoDbDriver;
use PostgresPdoDbDriver;

use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\sanitizeTableFieldValue;

use const MyShowcase\Plugin\Core\DATA_TABLE_STRUCTURE;
use const MyShowcase\Plugin\Core\TABLES_DATA;

class CommentsRepository
{
    private ?int $comment_id;

    private array $insert_data = [];

    private array $comment_data = [];

    private readonly bool $is_update;

    public function __construct(
        private readonly DB_SQLite|DB_PgSQL|PostgresPdoDbDriver|MysqlPdoDbDriver|DB_MySQLi $database,
        private ?ShowcaseEntity $showcase = null,
    ) {
    }

    public function setShowcase(ShowcaseEntity $showcase): void
    {
        $this->showcase = $showcase;
    }

    /**
     * @return iterable<array>
     */
    public function queryComments(
        array $where_clauses = [],
        array|string $query_fields = [],
        array $query_options = []
    ): iterable {
        $where_clauses[] = "comment_data.showcase_id='{$this->showcase->showcase_id}'";

        if (empty($query_fields)) {
            $query_fields = 'comment_data.comment_id';
        }

        if (is_array($query_fields)) {
            $query_fields = implode(', ', $query_fields);
        }

        $query = $this->database->simple_select(
            'myshowcase_comments comment_data',
            $query_fields,
            implode(' AND ', $where_clauses),
            $query_options
        );

        while ($comment_data = $this->database->fetch_array($query)) {
            yield $comment_data;
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
        return $this->queryComment(
            $where_clauses,
            'COUNT(comment_data.comment_id) AS total_comments'
        )['total_comments'];
    }

    public function insertComment(array $comment_data, bool $is_update = false, ?int $comment_id = null): int
    {
        $this->comment_data = $comment_data;

        $this->comment_id = $comment_id;

        $this->insert_data = [
            'showcase_id' => $this->showcase->showcase_id,
        ];

        $this->is_update = $is_update;

        foreach (TABLES_DATA['myshowcase_comments'] as $field_name => $field_definition) {
            if (isset($this->comment_data[$field_name])) {
                $this->insert_data[$field_name] = sanitizeTableFieldValue(
                    $this->comment_data[$field_name],
                    $field_definition['type']
                );
            } elseif (!$this->is_update) {
                $this->insert_data[$field_name] = sanitizeTableFieldValue(
                    $field_definition['default'] ?? '',
                    $field_definition['type']
                );
            }
        }

        hooksRun('comment_insert_update', $this);

        if ($this->is_update) {
            $this->database->update_query(
                'myshowcase_comments',
                $this->insert_data,
                "comment_id='{$this->comment_id}'"
            );

            return $this->comment_id;
        } else {
            $this->comment_id = (int)$this->database->insert_query('myshowcase_comments', $this->insert_data);
        }

        return $this->comment_id;
    }

    public function updateComment(array $comment_data, int $comment_id): int
    {
        return $this->insertComment($comment_data, true, $comment_id);
    }

    public function deleteComment(int $comment_id): void
    {
        $this->comment_id = $comment_id;

        hooksRun('comment_delete', $this);

        $this->database->delete_query(
            'myshowcase_comments',
            "showcase_id='{$this->showcase->showcase_id}' AND comment_id='{$comment_id}'"
        );
    }

    public function fetchComments(
        array $where_clauses = [],
        array $query_options = [],
    ): array {
        hooksRun('comment_fetch_start', $this);

        $query_fields = array_merge(
            array_map(function (string $field_name): string {
                return str_starts_with($field_name, 'comment_data.') ? $field_name : 'comment_data.' . $field_name;
            }, array_keys(TABLES_DATA['myshowcase_comments'])),
            [
                //'user_data.username',
                //'user_data.usergroup',
                //'user_data.displaygroup'
            ]
        );

        hooksRun('comment_fetch_intermediate', $this);

        $entry_objects = [];

        foreach (
            $this->queryComments(
                $where_clauses,
                $query_fields,
                $query_options
            ) as $comment_data
        ) {
            $entry_objects[] = $comment_data;
        }

        hooksRun('comment_fetch_end', $this);

        return $entry_objects;
    }
}