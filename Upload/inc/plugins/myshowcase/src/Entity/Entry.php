<?php

declare(strict_types=1);

namespace MyShowcase\Entity;

class Entry
{
    public ?string $rendered_entry;

    public function __construct(
        public readonly int $entry_id,
        public readonly string $entry_slug,
        public readonly string $entry_slug_custom,
        public readonly string $subject,
        public readonly int $prefix_id,
        public readonly int $icon_id,
        public readonly int $user_id,
        public readonly int $dateline,
        public readonly int $views,
        public readonly int $comments,
        public readonly int $is_closed,
        public readonly int $is_sticky,
        public readonly int $status,
        public readonly int $edit_user_id,
        public readonly string $post_hash,
        public readonly string $ipaddress,
        public array $extra_data = [],
    ) {
    }

    public function setRenderEntry(string $rendered_entry): void
    {
        $this->rendered_entry = $rendered_entry;
    }

    public function setExtraData(string $name, string|bool|int|array|null $value): self
    {
        $this->extra_data[$name] = $value;

        return $this;
    }

    public function getExtraData(string $name): string|bool|int|array|null
    {
        return $this->extra_data[$name];
    }
}