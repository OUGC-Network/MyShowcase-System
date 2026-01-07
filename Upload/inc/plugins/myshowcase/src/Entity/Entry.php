<?php

declare(strict_types=1);

namespace MyShowcase\Entity;

use MyShowcase\Collection\Collection;

use const MyShowcase\Plugin\Core\TABLES_DATA;

class Entry
{
    private Collection $attachments;

    private Collection $fields;

    private Collection $comments;

    public ?string $rendered_entry;

    private array $content_reports = [];

    public function __construct(
        public readonly ?int $entry_id = 0,
        public readonly ?string $entry_slug = null,
        public readonly ?string $entry_slug_custom = null,
        public readonly ?string $subject = null,
        public readonly ?int $prefix_id = null,
        public readonly ?int $icon_id = null,
        public readonly ?int $user_id = null,
        public readonly ?int $dateline = null,
        public readonly ?int $views = null,
        public readonly ?int $comments_approved = null,
        public readonly ?int $is_closed = null,
        public readonly ?int $is_sticky = null,
        public readonly ?int $status = null,
        public readonly ?int $edit_user_id = null,
        public ?string $post_hash = null,
        public readonly ?string $ipaddress = null,
        public ?array $extra_data = [],
    ) {
        if ($this->extra_data === null) {
            $this->extra_data = [];
        }

        $this->comments = new Collection();

        $this->attachments = new Collection();
    }

    public function setPostHash(string $post_hash): void
    {
        $this->post_hash = $post_hash;
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
        if (!isset($this->extra_data[$name])) {
            return false;
        }

        return $this->extra_data[$name];
    }

    public function getAttachments(): Collection
    {
        return $this->attachments;
    }

    public function getAttachmentsByFieldID(int $field_id): Collection
    {
        return $this->attachments->filter(function (Attachment $attachment) use ($field_id): bool {
            return $attachment->field_id === $field_id;
        });
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

    public function getFields(): Collection
    {
        return $this->fields;
    }

    public function addField(array $field_data): void
    {
        if (!$this->fields->contains($field_data)) {
            if (!empty($field_data['field_id'])) {
                $this->fields[$field_data['field_id']] = new Field(...$field_data);
            } else {
                $this->fields[] = new Field(...$field_data);
            }
        }
    }

    public function getComments(): Collection
    {
        return $this->comments;
    }

    public function addComment(array $comment_data): void
    {
        if (!$this->comments->contains($comment_data)) {
            if (!empty($comment_data['comment_id'])) {
                $this->comments[$comment_data['comment_id']] = new Comment(...$comment_data);
            } else {
                $this->comments[] = new Comment(...$comment_data);
            }
        }
    }
}