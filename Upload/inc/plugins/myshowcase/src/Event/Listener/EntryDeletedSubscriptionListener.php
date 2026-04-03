<?php

declare(strict_types=1);

namespace MyShowcase\Event\Listener;

use MyShowcase\Event\Event\EntryDeletedEvent;
use MyShowcase\Repository\SubscriptionsRepository;

readonly class EntryDeletedSubscriptionListener
{
    public function __construct(
        private SubscriptionsRepository $subscriptionsRepository,
    ) {
    }

    public function __invoke(EntryDeletedEvent $event): void
    {
        $entryId = $event->getEntryID();

        foreach (
            $this->subscriptionsRepository->querySubscription(["entry_id='{$entryId}'"])
            as $subscriptionId => $subscriptionData
        ) {
            $this->subscriptionsRepository->deleteSubscription($subscriptionId);
        }
    }
}

