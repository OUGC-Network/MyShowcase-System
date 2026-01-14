<?php

declare(strict_types=1);

namespace MyShowcase\Entity;

use MyShowcase\Collection\Collection;

use MyShowcase\Enum\Routes;
use MyShowcase\Fields\FieldsInterface;

use const MyShowcase\Plugin\Core\TABLES_DATA;

class Entry
{
    private Collection $attachments;

    private Collection $fields;

    private Collection $comments;

    public ?string $rendered_entry;

    public function __construct(
        public ?int $entry_id = 0,
        public ?string $entry_slug = null,
        public ?string $entry_slug_custom = null,
        public ?string $post_hash = null,
        public ?int $user_id = null,
        public readonly ?string $username = null,
        public ?string $subject = null,
        public ?string $message = null,
        public ?int $prefix_id = null,
        public ?int $icon_id = null,
        public ?int $dateline = null,
        public ?int $status = null,
        public ?int $is_closed = null,
        public ?int $is_sticky = null,
        public readonly ?int $views = null,
        public readonly ?int $comments_pending = null,
        public readonly ?int $comments_approved = null,
        public readonly ?int $comments_deleted = null,
        public readonly ?int $attachments_pending = null,
        public readonly ?int $attachments_approved = null,
        public readonly ?int $attachments_deleted = null,
        public readonly ?int $edit_stamp = null,
        public readonly ?int $edit_user_id = null,
        public ?string $edit_note = null,
        public ?int $last_comment_id = null,
        public readonly ?int $last_comment_user_id = null,
        public readonly ?string $last_comment_username = null,
        public readonly ?int $allow_signature = null,
        public readonly ?int $disable_smilies = null,
        public ?string $ipaddress = null,
        public ?array $extra_data = [],
    ) {
        if ($this->extra_data === null) {
            $this->extra_data = [];
        }

        $this->fields = new Collection();

        $this->comments = new Collection();

        $this->attachments = new Collection();
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

    public function addAttachment(Attachment $attachment_data): void
    {
        if (!$this->attachments->contains($attachment_data)) {
            if (!empty($attachment_data->attachment_id)) {
                $this->attachments[$attachment_data->attachment_id] = $attachment_data;
            } else {
                $this->attachments[] = $attachment_data;
            }
        }
    }

    public function getFields(): Collection
    {
        return $this->fields;
    }

    public function addField(FieldsInterface $field_data): void
    {
        if (!$this->fields->contains($field_data)) {
            if (!empty($field_data->field_id)) {
                $this->fields[$field_data->field_id] = $field_data;
            } else {
                $this->fields[] = $field_data;
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