<?php

declare(strict_types=1);

namespace MyShowcase\Service;

use DB_MySQLi;
use DB_PgSQL;
use DB_SQLite;
use MyShowcase\Event\Event\EntryDeletedEvent;
use MyShowcase\Event\EventDispatcher;
use MyShowcase\Event\Listener\EntryDeletedSubscriptonListener;
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
        $this->context->subscriptions_repository = new SubscriptionsRepository(
            $this->database, $this->config,
        );

        $this->event_dispatcher->addListener(
            EntryDeletedEvent::NAME,
            new EntryDeletedSubscriptonListener($this->context->subscriptions_repository)
        );
    }
}