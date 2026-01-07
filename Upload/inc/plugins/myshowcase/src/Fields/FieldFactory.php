<?php

namespace MyShowcase\Fields;

use InvalidArgumentException;
use MyShowcase\Enum\FieldHtmlTypes;
use MyShowcase\Fields\ColorField;
use MyShowcase\Fields\DateField;
use MyShowcase\Fields\DateTimeLocalField;
use MyShowcase\Fields\EmailField;
use MyShowcase\Fields\FileField;
use MyShowcase\Fields\MonthField;
use MyShowcase\Fields\NumberField;
use MyShowcase\Fields\PasswordField;
use MyShowcase\Fields\RadioField;
use MyShowcase\Fields\RangeField;
use MyShowcase\Fields\SearchField;
use MyShowcase\Fields\SelectEntriesField;
use MyShowcase\Fields\SelectField;
use MyShowcase\Fields\SelectThreadsField;
use MyShowcase\Fields\SelectUsersField;
use MyShowcase\Fields\TelephoneField;
use MyShowcase\Fields\TextAreaField;
use MyShowcase\Fields\TextField;
use MyShowcase\Fields\TimeField;
use MyShowcase\Fields\UrlField;
use MyShowcase\Fields\WeekField;
use MyShowcase\Repository\AttachmentsRepository;
use MyShowcase\Service\AttachmentsManager;
use myshowcase\Service\EntriesManager;
use MyShowcase\System\Showcase;

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
    ];

    private array $field_objects_cache = [];

    public function __construct(
        private readonly AttachmentsRepository $attachments_repository,
        private readonly EntriesManager $entries_manager,
        private readonly AttachmentsManager $attachments_manager,
        private readonly int $fieldset_id,
        private readonly array $parser_options,
        private readonly string $search_highlight,
    ) {
    }

    public function create(
        array $field_definition,
        ?int $entry_id = null,
        ?string $post_hash = null
    ): FieldsInterface {
        $cacheKey = $field_definition['field_key'];

        if (!isset($this->field_objects_cache[$cacheKey])) {
            $fieldType = $field_definition['html_type'];

            if (!isset(self::$field_class_map[$fieldType])) {
                throw new InvalidArgumentException("Unknown field type: {$fieldType}");
            }

            $fieldClass = self::$field_class_map[$fieldType];

            $this->field_objects_cache[$cacheKey] = new $fieldClass(
                $this->attachments_repository,
                $this->entries_manager,
                $this->attachments_manager,
                $this->fieldset_id,
                $this->parser_options,
                $this->search_highlight,
                $field_definition,
                $entry_id,
                $post_hash
            );
        }

        return $this->field_objects_cache[$cacheKey];
    }
}