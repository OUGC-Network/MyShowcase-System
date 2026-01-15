<?php

declare(strict_types=1);

namespace MyShowcase\Event\Event;

use Symfony\Contracts\EventDispatcher\Event;

use function MyShowcase\Plugin\Functions\hooksRun;

class AccessLogDeletedEvent extends Event
{
    public const NAME = 'access.log.deleted';

    public function __construct(
        private readonly int $access_log_id,
    ) {
        hooksRun('access_log_deleted', $this);
    }

    public function getAccessLogID(): int
    {
        return $this->access_log_id;
    }
}
