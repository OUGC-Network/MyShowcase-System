<?php

declare(strict_types=1);

namespace MyShowcase\Entity;

class Attachment
{
    public ?string $rendered_attachment;

    public bool $has_thumbnail = false;

    public function __construct(
        public readonly ?int $attachment_id = null,
        public readonly ?int $showcase_id = null,
        public readonly ?int $entry_id = null,
        public readonly ?int $comment_id = null,
        public readonly ?int $field_id = null,
        public readonly ?string $post_hash = null,
        public readonly ?string $attachment_hash = null,
        public readonly ?int $user_id = null,
        public readonly ?string $description = null,
        public readonly ?string $file_name = null,
        public readonly ?string $mime_type = null,
        public readonly ?int $file_size = null,
        public readonly ?string $attachment_name = null,
        public readonly ?int $downloads = null,
        public readonly ?int $dateline = null,
        public readonly ?int $status = null,
        public readonly ?string $thumbnail_name = null,
        public readonly ?string $thumbnail_dimensions = null,
        public readonly ?string $dimensions = null,
        public readonly ?int $edit_stamp = null,
        public ?array $extra_data = [],
    ) {
    }

    public function setRenderAttachment(string $rendered_attachment): void
    {
        $this->rendered_attachment = $rendered_attachment;
    }

    public function setExtraData(string $name, string|bool|int $value): self
    {
        $this->extra_data[$name] = $value;

        return $this;
    }

    public function getExtraData(string $name): string|bool|int|array|null
    {
        if (!isset($this->extra_data[$name])) {
            return false;
        }

        return $this->extra_data[$name];
    }
}