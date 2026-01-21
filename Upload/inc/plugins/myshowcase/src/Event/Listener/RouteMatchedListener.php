<?php

declare(strict_types=1);

namespace MyShowcase\Event\Listener;

use MyShowcase\Event\Event\EntryDeletedEvent;
use MyShowcase\Repository\AttachmentsRepository;
use MyShowcase\Event\Event\RouteMatchedEvent;

readonly class RouteMatchedListener
{
    public function __construct(RouteMatchedEvent $event)
    {
    }

    public function __invoke(RouteMatchedEvent $event): void
    {
        _dump('event', $event);
    }
}
