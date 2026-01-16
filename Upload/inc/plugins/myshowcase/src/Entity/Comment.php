<?php

declare(strict_types=1);

namespace MyShowcase\Entity;

use MyShowcase\Collection\Collection;

use const MyShowcase\Plugin\Core\TABLES_DATA;

class Comment
{
    private Collection $attachments;

    public ?string $rendered_comment;

    public function __construct(
        public readonly ?int $comment_id = null,
        public readonly ?string $comment_slug = null,
        public readonly ?int $showcase_id = null,
        public readonly ?int $entry_id = null,
        public readonly ?string $post_hash = null,
        public readonly ?int $user_id = null,
        public readonly ?string $username = null,
        public readonly ?int $parent_comment_id = null,
        public ?string $message = null,
        public readonly ?int $icon_id = null,
        public readonly ?int $dateline = null,
        public readonly ?int $status = null,
        public readonly ?int $attachments_pending = null,
        public readonly ?int $attachments_approved = null,
        public readonly ?int $attachments_deleted = null,
        public readonly ?int $edit_stamp = null,
        public readonly ?int $edit_user_id = null,
        public ?string $edit_note = null,
        public readonly ?int $allow_signature = null,
        public readonly ?int $disable_smilies = null,
        public readonly ?string $ipaddress = null,
        public ?array $extra_data = [],
    ) {
    }

    public function setRenderComment(string $rendered_comment): void
    {
        $this->rendered_comment = $rendered_comment;
    }

    public function setExtraData(string $name, string|bool|int|array|null $value): self
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

    public function getAttachments(): Collection
    {
        return $this->attachments;
    }

    public function addAttachment(array $attachment_data): void
    {
        $extra_data = [];

        foreach ($attachment_data as $field_key => $field_value) {
            if (!in_array($field_key, array_keys(TABLES_DATA['myshowcase_attachments']))) {
                $extra_data[$field_key] = $field_value;

                unset($attachment_data[$field_key]);
            }
        }

        if (!empty($extra_data)) {
            $attachment_data['extra_data'] = $extra_data;
        }

        if (!$this->attachments->contains($attachment_data)) {
            if (!empty($attachment_data['attachment_id'])) {
                $this->attachments[$attachment_data['attachment_id']] = new Attachment(...$attachment_data);
            } else {
                $this->attachments[] = new Attachment(...$attachment_data);
            }
        }
    }
}