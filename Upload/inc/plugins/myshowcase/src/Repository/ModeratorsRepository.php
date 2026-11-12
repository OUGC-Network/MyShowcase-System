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
use function MyShowcase\Plugin\Functions\omitEmptyAutoIncrement;
use function MyShowcase\Plugin\Functions\sanitizeTableFieldValue;

use const MyShowcase\Plugin\Core\MODERATOR_TYPE_GROUP;
use const MyShowcase\Plugin\Core\TABLES_DATA;

class ModeratorsRepository
{
    private ?int $moderator_id;

    private array $insert_data = [];

    private array $moderator_data = [];

    private bool $is_update = false;

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

    /**
     * Whether a row of myshowcase_moderators stands for this user. The ACP writes the table so: `group_id` is the TYPE of
     * the row (MODERATOR_TYPE_USER 0, MODERATOR_TYPE_GROUP 1) and `user_id` holds the id it refers to, a user id or a
     * usergroup id. This is the one place that reads that convention; it is shared by Showcase::moderatorPermissionsGet()
     * and EntriesManager::isModerator().
     *
     * @param array{user_id: int|string, group_id: int|string} $moderator_row
     * @param int[] $user_group_ids the user's primary and additional usergroup ids
     */
    public static function matchesUser(array $moderator_row, array $user_group_ids, int $user_id): bool
    {
        if ((int)$moderator_row['group_id'] === MODERATOR_TYPE_GROUP) {
            return in_array((int)$moderator_row['user_id'], $user_group_ids, true);
        }

        return $user_id > 0 && (int)$moderator_row['user_id'] === $user_id;
    }

    public function queryModerators(
        array $where_clauses = [],
        array $query_fields = [],
        array $query_options = []
    ): array {
        $where_clauses[] = "showcase_id='{$this->showcase->showcase_id}'";

        $query = $this->database->simple_select(
            'myshowcase_moderators',
            implode(',', array_merge(['moderator_id'], $query_fields)),
            implode(' AND ', $where_clauses),
            $query_options
        );

        if (isset($query_options['limit']) && $query_options['limit'] === 1) {
            return (array)$this->database->fetch_array($query);
        }

        $moderatorData = [];

        while ($moderator = $this->database->fetch_array($query)) {
            $moderatorData[(int)$moderator['moderator_id']] = $moderator;
        }

        return $moderatorData;
    }

    public function insertModerator(array $moderator_data): int
    {
        $this->insert_data = [];

        foreach (TABLES_DATA['myshowcase_moderators'] as $field_name => $field_definition) {
            if (isset($moderator_data[$field_name])) {
                $this->insert_data[$field_name] = sanitizeTableFieldValue(
                    $moderator_data[$field_name],
                    $field_definition['type']
                );
            }
        }

        hooksRun('moderator_insert_update_end', $this);

        if ($this->is_update) {
            $this->database->update_query(
                'myshowcase_moderators',
                $this->insert_data,
                "moderator_id='{$this->moderator_id}'"
            );
        } else {
            $this->moderator_id = (int)$this->database->insert_query('myshowcase_moderators', omitEmptyAutoIncrement($this->insert_data, TABLES_DATA['myshowcase_moderators']));
        }

        return $this->moderator_id;
    }

    public function updateModerator(array $moderator_data, int $moderator_id): int
    {
        $this->moderator_id = $moderator_id;

        $this->is_update = true;

        return $this->insertModerator($moderator_data);
    }

    public function deleteModerator(int $moderator_id): void
    {
        $this->moderator_id = $moderator_id;

        hooksRun('moderator_delete_start', $this);

        $this->database->delete_query(
            'myshowcase_moderators',
            "moderator_id='{$this->moderator_id}' AND showcase_id='{$this->showcase->showcase_id}'"
        );
    }
}