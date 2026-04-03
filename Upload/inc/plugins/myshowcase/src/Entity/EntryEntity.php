<?php

declare(strict_types=1);

namespace MyShowcase\Entity;

use DateTime;
use JetBrains\PhpStorm\NoReturn;
use MyShowcase\Collection\Collection;
use MyShowcase\Fields\FieldsInterface;
use MyShowcase\ValueObjects\EntryCustomField;

final class EntryEntity
{
    private Collection $attachments;
    private Collection $fields;
    private Collection $custom_fields;
    public array $extra_data = [];
    public ?string $rendered_entry;

    public function __construct(
        public int $entry_id = 0,
        public ?string $entry_slug = null,
        public ?string $entry_slug_custom = null,
        public ?string $post_hash = null,
        public ?int $user_id = null,
        public ?string $username = null,
        public ?string $subject = null,
        public ?string $message = null,
        public ?int $icon_id = null,
        public ?DateTime $dateline = null,
        public ?int $status = null,
        public ?int $is_closed = null,
        public ?int $is_sticky = null,
        public ?int $views = null,
        public ?int $comments_awaiting = null,
        public ?int $comments_approved = null,
        public ?int $comments_deleted = null,
        public ?int $attachments_awaiting = null,
        public ?int $attachments_approved = null,
        public ?int $attachments_deleted = null,
        public ?DateTime $update_dateline = null,
        public ?int $edit_user_id = null,
        public ?string $update_note = null,
        public ?int $last_comment_id = null,
        public ?int $last_comment_user_id = null,
        public ?string $last_comment_username = null,
        public ?int $allow_signature = null,
        public ?int $disable_smilies = null,
        public ?string $ipaddress = null,
    ) {
        $this->attachments = new Collection();

        $this->custom_fields = new Collection();

        $this->fields = new Collection();
    }

    public function setRenderEntry(string $rendered_entry): void
    {
        $this->rendered_entry = $rendered_entry;
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

    public function getAttachments(): Collection
    {
        return $this->attachments;
    }

    public function getAttachmentsByFieldID(int $field_id): Collection
    {
        return $this->attachments->filter(function (AttachmentEntity $attachment) use ($field_id): bool {
            return $attachment->field_id === $field_id;
        });
    }

    public function addAttachment(AttachmentEntity $attachment_data): void
    {
        if (!$this->attachments->contains($attachment_data)) {
            $this->attachments[$attachment_data->attachment_id] = $attachment_data;
        }
    }

    public function getFields(): Collection
    {
        return $this->fields;
    }

    public function addField(FieldsInterface $field_data): void
    {
        if (!$this->fields->contains($field_data)) {
            $this->fields[$field_data->field_definition['field_key']] = $field_data;
        }
    }

    public function getCustomFields(): Collection
    {
        return $this->custom_fields;
    }

    #[NoReturn] public function addCustomField(EntryCustomField $custom_field): void
    {
        if (!$this->custom_fields->contains($custom_field)) {
            $this->custom_fields[$custom_field->getKey()] = $custom_field;
        }
    }

    public function getCustomField(string $field_key): EntryCustomField|false
    {
        if (isset($this->custom_fields[$field_key])) {
            return $this->custom_fields[$field_key];
        }

        return false;
    }

    public function getCustomFieldParsedValue(string $field_key): string|int|null
    {
        if ($this->getCustomField($field_key) !== false) {
            return $this->getCustomField($field_key)->getParsedValue();
        }

        return null;
    }
}