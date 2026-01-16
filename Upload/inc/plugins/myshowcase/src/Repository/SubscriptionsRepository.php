<?php

declare(strict_types=1);

namespace MyShowcase\Repository;

use DB_MySQLi;
use DB_PgSQL;
use DB_SQLite;
use MysqlPdoDbDriver;
use PostgresPdoDbDriver;

use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\sanitizeTableFieldValue;

use const MyShowcase\Plugin\Core\TABLES_DATA;

class SubscriptionsRepository
{
    public const SCOPE_TYPE_SHOWCASE = 1;

    public const SCOPE_TYPE_ENTRY = 2;

    private ?int $subscription_id;

    private array $insert_data = [];

    private array $subscription_data = [];

    private readonly bool $is_update;

    public function __construct(
        private readonly DB_SQLite|DB_PgSQL|PostgresPdoDbDriver|MysqlPdoDbDriver|DB_MySQLi $database,
        private readonly array $config,
    ) {
    }

    /**
     * @return iterable<array>
     */
    public function querySubscriptions(
        array $where_clauses = [],
        array|string $query_fields = [],
        array $query_options = [],
    ): iterable {
        if (is_array($query_fields)) {
            $query_fields = implode(', ', $query_fields);
        }

        $query = $this->database->simple_select(
            'myshowcase_subscriptions',
            $query_fields,
            implode(' AND ', $where_clauses),
            $query_options
        );

        while ($entry = $this->database->fetch_array($query)) {
            yield $entry;
        }
    }

    public function querySubscription(
        array $where_clauses = [],
        array|string $query_fields = [],
        array $query_options = [],
    ): array {
        $query_options['limit'] = 1;

        $entry_object = [];

        foreach (
            $this->querySubscriptions(
                $where_clauses,
                $query_fields,
                $query_options,
            ) as $entry
        ) {
            $entry_object = $entry;

            break;
        }

        return $entry_object;
    }

    public function queryEntryCount(
        array $where_clauses = [],
    ): int {
        return $this->querySubscription(
            $where_clauses,
            'COUNT(subscription_id) AS total_subscriptions',
        )['total_subscriptions'];
    }

    public function insertSubscription(
        array $subscription_data,
        bool $is_update = false,
        ?int $subscription_id = null
    ): int {
        $this->subscription_data = $subscription_data;

        $this->subscription_id = $subscription_id;

        $this->insert_data = [];

        $this->is_update = $is_update;

        foreach (TABLES_DATA['myshowcase_subscriptions'] as $field_name => $field_definition) {
            if (isset($this->comment_data[$field_name])) {
                $this->insert_data[$field_name] = sanitizeTableFieldValue(
                    $this->subscription_data[$field_name],
                    $field_definition['type']
                );
            } elseif (!$this->is_update) {
                $this->insert_data[$field_name] = sanitizeTableFieldValue(
                    $field_definition['default'] ?? '',
                    $field_definition['type']
                );
            }
        }

        hooksRun('subscription_insert_update', $this);

        if ($this->is_update) {
            $this->database->update_query(
                'myshowcase_subscriptions',
                $this->insert_data,
                "subscription_id='{$this->subscription_id}'"
            );

            return $this->subscription_id;
        } else {
            $this->subscription_id = (int)$this->database->insert_query(
                'myshowcase_subscriptions',
                $this->insert_data
            );
        }

        return $this->subscription_id;
    }

    public function updateSubscription(array $subscription_data, int $subscription_id): int
    {
        return $this->insertSubscription($subscription_data, true, $subscription_id);
    }

    public function deleteSubscription(int $subscription_id, int $scope_type = self::SCOPE_TYPE_ENTRY): void
    {
        $this->subscription_id = $subscription_id;

        hooksRun('subscription_delete', $this);

        $this->database->delete_query(
            'myshowcase_subscriptions',
            "showcase_id='{$this->config['showcase_id']}' AND subscription_id='{$this->subscription_id}' AND scope_type='{$scope_type}'"
        );
    }
}