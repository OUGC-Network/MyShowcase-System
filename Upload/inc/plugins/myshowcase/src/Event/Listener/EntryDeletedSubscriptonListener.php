<?php

declare(strict_types=1);

namespace MyShowcase\Event\Listener;

use MyShowcase\Event\Event\EntryDeletedEvent;
use MyShowcase\Repository\SubscriptionsRepository;

readonly class EntryDeletedSubscriptonListener
{
    public function __construct(
        private readonly SubscriptionsRepository $subscriptions_repository,
    ) {
    }

    public function __invoke(EntryDeletedEvent $event): void
    {
        $entry_id = $event->getEntryID();

        foreach (
            $this->subscriptions_repository->querySubscription(["entry_id='{$entry_id}'"]
            ) as $subscription_id => $subscription_data
        ) {
            $this->subscriptions_repository->deleteSubscription($subscription_id);
        }
    }
}
