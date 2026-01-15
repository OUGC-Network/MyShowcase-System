<?php

declare(strict_types=1);

namespace MyShowcase\Event\Event;

use Symfony\Contracts\EventDispatcher\Event;

use function MyShowcase\Plugin\Functions\hooksRun;

class AccessLogInsertedUpdatedEvent extends Event
{
    public const NAME = 'access.log.updated.deleted';

    public function __construct(
        private readonly array $access_log_data,
        public readonly bool $is_update,
        private readonly int $access_log_id,
    ) {
        hooksRun('access_log_inserted_updated', $this);
    }

    public function getAccessLogData(): array
    {
        return $this->access_log_data;
    }

    public function getAccessLogID(): int
    {
        return $this->access_log_id;
    }
}
