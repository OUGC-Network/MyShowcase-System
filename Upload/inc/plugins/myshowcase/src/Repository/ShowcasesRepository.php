<?php

declare(strict_types=1);

namespace MyShowcase\Repository;

use DB_MySQLi;
use DB_PgSQL;
use DB_SQLite;
use MyShowcase\Entity\ShowcaseEntity;
use MyShowcase\Event\EventDispatcher;
use MysqlPdoDbDriver;
use PostgresPdoDbDriver;

use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\sanitizeTableFieldValue;

use const MyShowcase\Plugin\Core\TABLES_DATA;

class ShowcasesRepository
{
    private array $insert_data = [];

    private array $showcase_data = [];

    private readonly bool $is_update;

    public function __construct(
        private readonly DB_SQLite|DB_PgSQL|PostgresPdoDbDriver|MysqlPdoDbDriver|DB_MySQLi $database,
        private readonly ?EventDispatcher $event_dispatcher = null,
        private ?ShowcaseEntity $showcase = null,
    ) {
    }

    public function setShowcase(ShowcaseEntity $showcase): void
    {
        $this->showcase = $showcase;
    }

    public function insertShowcase(array $showcase_data, bool $is_update = false): int
    {
        $this->showcase_data = $showcase_data;

        if (!empty($this->showcase->showcase_id)) {
            $this->insert_data['showcase_id'] = $this->showcase->showcase_id;
        }

        $this->is_update = $is_update;

        foreach (TABLES_DATA['myshowcase_config'] as $field_name => $field_definition) {
            if (isset($this->showcase_data[$field_name])) {
                $this->insert_data[$field_name] = sanitizeTableFieldValue(
                    $this->showcase_data[$field_name],
                    $field_definition['type']
                );
            } elseif (!$this->is_update && empty($field_definition['auto_increment'])) {
                // The generated id is left to the database; an explicit 0 becomes row 0 on SQLite and PostgreSQL.
                $this->insert_data[$field_name] = sanitizeTableFieldValue(
                    $field_definition['default'] ?? '',
                    $field_definition['type']
                );
            }
        }

        hooksRun('showcase_insert_update', $this);

        if ($this->is_update) {
            $this->database->update_query(
                'myshowcase_config',
                $this->insert_data,
                "showcase_id='{$this->showcase->showcase_id}'"
            );

            return $this->showcase->showcase_id;
        } else {
            return (int)$this->database->insert_query('myshowcase_config', $this->insert_data);
        }
    }

    public function updateShowcase(array $showcase_data): int
    {
        return $this->insertShowcase($showcase_data, true);
    }
}