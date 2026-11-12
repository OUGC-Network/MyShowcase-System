<?php

declare(strict_types=1);

namespace MyShowcase\Repository;

use DB_MySQLi;
use DB_PgSQL;
use DB_SQLite;
use MyShowcase\Entity\ShowcaseEntity;
use MysqlPdoDbDriver;
use PostgresPdoDbDriver;

use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\omitEmptyAutoIncrement;
use function MyShowcase\Plugin\Functions\newRowDefault;
use function MyShowcase\Plugin\Functions\sanitizeTableFieldValue;

use const MyShowcase\Plugin\Core\TABLES_DATA;

class AttachmentsRepository
{
    private ?int $attachment_id;

    private array $insert_data = [];

    private array $attachment_data = [];

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
    public function queryAttachments(
        array $where_clauses = [],
        array|string $query_fields = [],
        array $query_options = []
    ): iterable {
        $where_clauses[] = "showcase_id='{$this->showcase->showcase_id}'";

        if (empty($query_fields)) {
            $query_fields = 'attachment_id';
        }

        if (is_array($query_fields)) {
            $query_fields = implode(', ', $query_fields);
        }

        $query = $this->database->simple_select(
            'myshowcase_attachments',
            $query_fields,
            implode(' AND ', $where_clauses),
            $query_options
        );

        while ($attachment_data = $this->database->fetch_array($query)) {
            yield $attachment_data;
        }
    }

    public function queryAttachment(
        array $where_clauses = [],
        array|string $query_fields = [],
        array $query_options = []
    ): array {
        $query_options['limit'] = 1;

        $attachment_object = [];

        foreach (
            $this->queryAttachments(
                $where_clauses,
                $query_fields,
                $query_options
            ) as $attachment_data
        ) {
            $attachment_object = $attachment_data;

            break;
        }

        return $attachment_object;
    }

    public function queryAttachmentCount(
        array $where_clauses = []
    ): int {
        return (int)$this->queryAttachment(
            $where_clauses,
            'COUNT(attachment_id) AS total_attachments'
        )['total_attachments'];
    }

    public function queryAttachmentSum(
        array $where_clauses = []
    ): int {
        return (int)$this->queryAttachment(
            $where_clauses,
            'SUM(file_size) AS total_attachments_size'
        )['total_attachments_size'];
    }

    public function insertAttachment(array $attachment_data, bool $is_update = false, ?int $attachment_id = null): int
    {
        $this->attachment_data = $attachment_data;

        $this->attachment_id = $attachment_id;

        $this->insert_data = [
            'showcase_id' => $this->showcase->showcase_id,
        ];

        $this->is_update = $is_update;

        foreach (TABLES_DATA['myshowcase_attachments'] as $field_name => $field_definition) {
            if (isset($this->attachment_data[$field_name])) {
                $this->insert_data[$field_name] = sanitizeTableFieldValue(
                    $this->attachment_data[$field_name],
                    $field_definition['type']
                );
            } elseif (!$this->is_update) {
                $this->insert_data[$field_name] = sanitizeTableFieldValue(
                    newRowDefault($field_definition),
                    $field_definition['type']
                );
            }
        }

        hooksRun('attachment_insert_update', $this);

        if ($this->is_update) {
            $this->database->update_query(
                'myshowcase_attachments',
                $this->insert_data,
                "attachment_id='{$this->attachment_id}'"
            );

            return $this->attachment_id;
        } else {
            return (int)$this->database->insert_query('myshowcase_attachments', omitEmptyAutoIncrement($this->insert_data, TABLES_DATA['myshowcase_attachments']));
        }
    }

    public function updateAttachment(array $attachment_data, int $attachment_id): int
    {
        return $this->insertAttachment($attachment_data, true, $attachment_id);
    }

    public function deleteAttachment(int $attachment_id): void
    {
        $this->attachment_id = $attachment_id;

        hooksRun('attachment_delete', $this);

        $attachment_data = $this->queryAttachment(
            ["attachmen_id='{$this->attachment_id}'"],
            ['attachment_name', 'thumbnail_name', 'status']
        );

        $this->database->delete_query(
            'myshowcase_attachments',
            "showcase_id='{$this->showcase->showcase_id}' AND attachment_id='{$this->attachment_id}'"
        );

        if (!empty($attachment_data)) {
            if (file_exists($this->attachments_uploads_path . '/' . $attachment_data['attachment_name'])) {
                unlink($this->attachments_uploads_path . '/' . $attachment_data['attachment_name']);
            }

            if (!empty($attachment_data['thumbnail_name']) &&
                file_exists($this->attachments_uploads_path . '/' . $attachment_data['thumbnail_name'])) {
                unlink($this->attachments_uploads_path . '/' . $attachment_data['thumbnail_name']);
            }

            $date_directory = explode('/', $attachment_data['attachment_name']);

            if (!empty($date_directory[0]) &&
                is_dir($this->attachments_uploads_path . '/' . $date_directory[0])) {
                rmdir($this->attachments_uploads_path . '/' . $date_directory[0]);
            }
        }
    }
}