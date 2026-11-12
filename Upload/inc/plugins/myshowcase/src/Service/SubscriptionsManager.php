<?php

declare(strict_types=1);

namespace MyShowcase\Service;

use DB_MySQLi;
use DB_PgSQL;
use DB_SQLite;
use MyShowcase\Event\EventDispatcher;
use MyShowcase\Repository\SubscriptionsRepository;
use MysqlPdoDbDriver;
use PostgresPdoDbDriver;

readonly class SubscriptionsManager
{
    private SubscriptionsRepository $subscriptions_repository;

    public function __construct(
        private DB_SQLite|DB_PgSQL|PostgresPdoDbDriver|MysqlPdoDbDriver|DB_MySQLi $database,
        private array $config,
        private EventDispatcher $event_dispatcher,
    ) {
        $this->subscriptions_repository = new SubscriptionsRepository(
            $this->database, $this->config,
        );
    }
}
