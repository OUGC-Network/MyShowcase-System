<?php

declare(strict_types=1);

namespace MyShowcase\Event\Event;

use Symfony\Contracts\EventDispatcher\Event;

class EntryDeletedEvent extends Event
{
    public const NAME = 'entry.deleted';

    public function __construct(
        private readonly int $entry_id
    ) {
    }

    public function getEntryID(): int
    {
        return $this->entry_id;
    }
}
