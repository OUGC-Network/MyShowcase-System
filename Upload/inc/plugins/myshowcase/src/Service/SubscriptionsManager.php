<?php

declare(strict_types=1);

namespace MyShowcase\Service;

use MyShowcase\Event\Event\EntryDeletedEvent;
use MyShowcase\Event\EventDispatcher;
use MyShowcase\Event\Listener\EntryDeletedSubscriptonListener;
use MyShowcase\Repository\SubscriptionsRepository;

readonly class SubscriptionsManager
{
    public function __construct(
        private SubscriptionsRepository $subscriptions_repository,
        private EventDispatcher $event_dispatcher,
    ) {
        $this->event_dispatcher->addListener(
            EntryDeletedEvent::NAME,
            new EntryDeletedSubscriptonListener($subscriptions_repository)
        );
    }
}