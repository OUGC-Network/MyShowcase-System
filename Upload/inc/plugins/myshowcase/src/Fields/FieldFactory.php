<?php

namespace MyShowcase\Fields;

use InvalidArgumentException;
use MyShowcase\Entity\Entry;
use MyShowcase\Enum\FieldHtmlTypes;
use MyShowcase\Repository\AttachmentsRepository;
use MyShowcase\Service\AttachmentsManager;
use MyShowcase\Service\EntriesManager;

class FieldFactory
{
    private static array $field_class_map = [
        FieldHtmlTypes::CheckBox => CheckBoxField::class,
        FieldHtmlTypes::Color => ColorField::class,
        FieldHtmlTypes::Date => DateField::class,
        FieldHtmlTypes::DateTimeLocal => DateTimeLocalField::class,
        FieldHtmlTypes::Email => EmailField::class,
        FieldHtmlTypes::File => FileField::class,
        FieldHtmlTypes::Month => MonthField::class,
        FieldHtmlTypes::Number => NumberField::class,
        FieldHtmlTypes::Password => PasswordField::class,
        FieldHtmlTypes::Radio => RadioField::class,
        FieldHtmlTypes::Range => RangeField::class,
        FieldHtmlTypes::Search => SearchField::class,
        FieldHtmlTypes::Telephone => TelephoneField::class,
        FieldHtmlTypes::Text => TextField::class,
        FieldHtmlTypes::Time => TimeField::class,
        FieldHtmlTypes::Url => UrlField::class,
        FieldHtmlTypes::Week => WeekField::class,
        FieldHtmlTypes::TextArea => TextAreaField::class,
        FieldHtmlTypes::Select => SelectField::class,
        FieldHtmlTypes::SelectUsers => SelectUsersField::class,
        FieldHtmlTypes::SelectEntries => SelectEntriesField::class,
        FieldHtmlTypes::SelectThreads => SelectThreadsField::class,
        FieldHtmlTypes::Poll => PollField::class,
        FieldHtmlTypes::Rating => RatingField::class,
    ];

    private array $field_objects_cache = [];

    public function __construct(
        private readonly AttachmentsRepository $attachments_repository,
        private readonly EntriesManager $entries_manager,
        private readonly AttachmentsManager $attachments_manager,
        private readonly array $config,
        private readonly array $parser_options,
        private readonly string $search_highlight,
    ) {
    }

    public function create(
        array $field_definition,
        Entry $entry,
    ): FieldsInterface {
        if (!isset($this->field_objects_cache[$field_definition['field_key']])) {
            if (!isset(self::$field_class_map[$field_definition['html_type']])) {
                throw new InvalidArgumentException("Unknown field type: {$field_definition['html_type']}");
            }

            $field_class = self::$field_class_map[$field_definition['html_type']];

            $this->field_objects_cache[$field_definition['field_key']] = new $field_class(
                $this->attachments_repository,
                $this->entries_manager,
                $this->attachments_manager,
                $this->config['field_set_id'],
                $this->parser_options,
                $this->search_highlight,
                $field_definition,
                $entry
            );
        }

        return $this->field_objects_cache[$field_definition['field_key']];
    }
}