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
    /** Tables whose rows are about one attachment. */
    private const DEPENDENT_TABLES = [
        'myshowcase_attachments_history',
        'myshowcase_attachments_cdn',
        'myshowcase_attachments_shared',
        'myshowcase_attachments_download_logs',
    ];

    private ?int $attachment_id;

    private array $insert_data = [];

    private array $attachment_data = [];

    /** Set on every insert or update: a readonly property would refuse the second insert of one repository instance. */
    private bool $is_update = false;

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

    /**
     * Attaches the files a user uploaded under a post hash (while the entry or comment did not exist yet) to the entry or
     * comment that was just created: every attachment of this showcase that carries the hash, belongs to the user and
     * is not attached to anything yet gets $link_data (for example ['entry_id' => 12]).
     *
     * @param array<string, mixed> $link_data
     * @return int How many attachments were linked.
     */
    public function linkAttachmentsByPostHash(string $post_hash, int $user_id, array $link_data): int
    {
        if ($post_hash === '') {
            return 0;
        }

        $attachment_ids = [];

        foreach (
            $this->queryAttachments([
                "post_hash='{$this->database->escape_string($post_hash)}'",
                "user_id='{$user_id}'",
                "entry_id='0'",
                "comment_id='0'",
            ], ['attachment_id']) as $attachment_data
        ) {
            $attachment_ids[] = (int)$attachment_data['attachment_id'];
        }

        foreach ($attachment_ids as $attachment_id) {
            $this->updateAttachment($link_data, $attachment_id);
        }

        return count($attachment_ids);
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
            ["attachment_id='{$this->attachment_id}'"],
            ['file_name', 'thumbnail_name']
        );

        if (empty($attachment_data)) {
            return;
        }

        $this->database->delete_query(
            'myshowcase_attachments',
            "showcase_id='{$this->showcase->showcase_id}' AND attachment_id='{$this->attachment_id}'"
        );

        // Attachment ids are unique across showcases: the rows below belong to this attachment only.
        foreach (self::DEPENDENT_TABLES as $table) {
            $this->database->delete_query($table, "attachment_id='{$this->attachment_id}'");
        }

        $uploads_path = rtrim(mk_path_abs($this->showcase->attachments_uploads_path), '/');

        // The month folder (YYYYMM/) holds the files of other attachments too: it stays.
        foreach ([$attachment_data['file_name'], $attachment_data['thumbnail_name']] as $file_name) {
            if ((string)$file_name !== '' && is_file($uploads_path . '/' . $file_name)) {
                unlink($uploads_path . '/' . $file_name);
            }
        }
    }
}