<?php

declare(strict_types=1);

namespace MyShowcase\Repository;

use DB_MySQLi;
use DB_PgSQL;
use DB_SQLite;
use MyShowcase\Event\Event\EntryDeletedEvent;
use MyShowcase\Enum\FieldDefaultTypes;
use MyShowcase\Event\EventDispatcher;
use MysqlPdoDbDriver;
use PostgresPdoDbDriver;
use Random\RandomException;

use function MyShowcase\Plugin\Functions\cacheGet;
use function MyShowcase\Plugin\Functions\fieldTypeMatchBinary;
use function MyShowcase\Plugin\Functions\fieldTypeMatchChar;
use function MyShowcase\Plugin\Functions\fieldTypeMatchDateTime;
use function MyShowcase\Plugin\Functions\fieldTypeMatchFloat;
use function MyShowcase\Plugin\Functions\fieldTypeMatchInt;
use function MyShowcase\Plugin\Functions\fieldTypeMatchText;
use function MyShowcase\Plugin\Functions\generateUUIDv4;
use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\sanitizeTableFieldValue;

use const MyShowcase\Plugin\Core\CACHE_TYPE_FIELDS;
use const MyShowcase\Plugin\Core\DATA_TABLE_STRUCTURE;
use const MyShowcase\Plugin\Core\TABLES_DATA;

class AttachmentsRepository
{
    private ?int $attachment_id;

    private array $insert_data = [];

    private array $attachment_data = [];

    private readonly bool $is_update;

    public function __construct(
        private readonly DB_SQLite|DB_PgSQL|PostgresPdoDbDriver|MysqlPdoDbDriver|DB_MySQLi $db,
        private readonly int $showcase_id,
        private readonly EventDispatcher $event_dispatcher,
    ) {
    }

    /**
     * @return iterable<array>
     */
    public function queryAttachments(
        array $where_clauses = [],
        array|string $query_fields = [],
        array $query_options = []
    ): iterable {
        $where_clauses[] = "showcase_id='{$this->showcase_id}'";

        global $db;

        if (is_array($query_fields)) {
            $query_fields = implode(', ', $query_fields);
        }

        $query = $db->simple_select(
            'myshowcase_attachments',
            $query_fields,
            implode(' AND ', $where_clauses),
            $query_options
        );

        while ($attachment_data = $db->fetch_array($query)) {
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
        return $this->queryAttachment($where_clauses, 'COUNT(attachment_id) AS total_attachments')['total_attachments'];
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
        global $db;

        $this->attachment_data = $attachment_data;

        $this->attachment_id = $attachment_id;

        $this->insert_data = [
            'showcase_id' => $this->showcase_id,
        ];

        $this->is_update = $is_update;

        foreach (TABLES_DATA['myshowcase_attachments'] as $field_name => $field_definition) {
            if (isset($attachmentData[$field_name])) {
                $this->insert_data[$field_name] = sanitizeTableFieldValue(
                    $attachmentData[$field_name],
                    $field_definition['type']
                );
            }
        }

        hooksRun('attachment_insert_update', $this);

        if ($this->is_update) {
            $db->update_query('myshowcase_attachments', $this->insert_data, "attachment_id='{$this->attachment_id}'");

            return $this->attachment_id;
        } else {
            return (int)$db->insert_query('myshowcase_attachments', $this->insert_data);
        }
    }

    public function updateAttachment(array $attachment_data, int $attachment_id): int
    {
        return $this->insertAttachment($attachment_data, true, $attachment_id);
    }

    public function deleteAttachment(int $attachment_id): void
    {
        global $db;

        $this->attachment_id = $attachment_id;

        hooksRun('attachment_delete', $this);

        $attachment_data = $this->queryAttachment(
            ["attachmen_id='{$this->attachment_id}'"],
            ['attachment_name', 'thumbnail_name', 'status']
        );

        $db->delete_query(
            'myshowcase_attachments',
            "showcase_id='{$this->showcase_id}' AND attachment_id='{$this->attachment_id}'"
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