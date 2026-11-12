<?php

declare(strict_types=1);

namespace MyShowcase\Entity;

use DateTime;

class AttachmentEntity
{
    public ?string $rendered_attachment;

    public bool $is_image = false;

    public bool $has_thumbnail = false;

    public function __construct(
        public readonly ?int $attachment_id = null,
        public readonly ?string $public_id = null,
        public readonly ?int $showcase_id = null,
        public readonly ?int $entry_id = null,
        public readonly ?int $comment_id = null,
        public readonly ?int $field_id = null,
        public readonly ?string $post_hash = null,
        public readonly ?int $user_id = null,
        public readonly ?string $description = null,
        public readonly ?DateTime $dateline = null,
        public readonly ?int $status = null,
        public readonly ?DateTime $update_dateline = null,
        public readonly ?int $edit_user_id = null,
        public readonly ?string $file_name = null,
        public readonly ?string $mime_type = null,
        public readonly ?int $file_size = null,
        public readonly ?string $attachment_name = null,
        public readonly ?int $downloads = null,
        public readonly ?string $thumbnail_name = null,
        public readonly ?string $thumbnail_dimensions = null,
        public readonly ?string $dimensions = null,
        public ?string $ipaddress = null,
        public ?array $extra_data = [],
    ) {
        $this->is_image = str_starts_with($this->mime_type ?? '', 'image/');

        $this->has_thumbnail = !empty($this->thumbnail_name);
    }

    public function setRenderAttachment(string $rendered_attachment): void
    {
        $this->rendered_attachment = $rendered_attachment;
    }

    public function setExtraData(string $name, mixed $value): self
    {
        $this->extra_data[$name] = $value;

        return $this;
    }

    public function getExtraData(string $name): mixed
    {
        if (!isset($this->extra_data[$name])) {
            return false;
        }

        return $this->extra_data[$name];
    }
}