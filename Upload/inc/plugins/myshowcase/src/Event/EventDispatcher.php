<?php

declare(strict_types=1);

namespace MyShowcase\Event;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventDispatcher as SymfonyEventDispatcher;

class EventDispatcher
{
    private EventDispatcherInterface $dispatcher;

    public function __construct()
    {
        $this->dispatcher = new SymfonyEventDispatcher();
    }

    public function addListener(string $event_name, callable $listener): void
    {
        $this->dispatcher->addListener($event_name, $listener);
    }

    public function dispatch(object $event, ?string $event_name = null): object
    {
        return $this->dispatcher->dispatch($event, $event_name);
    }
}