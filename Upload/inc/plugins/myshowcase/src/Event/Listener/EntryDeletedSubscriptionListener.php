<?php

declare(strict_types=1);

namespace MyShowcase\Event\Listener;

use MyShowcase\Enum\ContentTypes;
use MyShowcase\Event\Event\EntryDeletedEvent;
use MyShowcase\ShowcaseContext;

/** Deletes the subscriptions to a deleted entry. */
readonly class EntryDeletedSubscriptionListener
{
    public function __construct(
        private ShowcaseContext $context,
    ) {
    }

    public function __invoke(EntryDeletedEvent $event): void
    {
        $subscriptions = iterator_to_array(
            $this->context->subscriptions_repository->querySubscriptions(
                ["entry_id='{$event->getEntryID()}'"],
                ['subscription_id', 'content_type']
            ),
            false
        );

        foreach ($subscriptions as $subscription_data) {
            $this->context->subscriptions_repository->deleteSubscription(
                (int)$subscription_data['subscription_id'],
                (int)($subscription_data['content_type'] ?? ContentTypes::Entry)
            );
        }
    }
}
