<?php

declare(strict_types=1);

namespace MyShowcase\Event\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * An entry row was deleted. Entry ids are unique per showcase only, so listeners clean up by showcase and entry.
 */
class EntryDeletedEvent extends Event
{
    public const NAME = 'entry.deleted';

    public function __construct(
        private readonly int $showcase_id,
        private readonly int $entry_id,
        private readonly int $user_id,
    ) {
    }

    public function getShowcaseID(): int
    {
        return $this->showcase_id;
    }

    public function getEntryID(): int
    {
        return $this->entry_id;
    }

    public function getUserID(): int
    {
        return $this->user_id;
    }
}
