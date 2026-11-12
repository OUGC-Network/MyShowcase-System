<?php

namespace MyShowcase\Fields;

use InvalidArgumentException;
use MyBB;
use MyShowcase\Entity\EntryEntity;
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

    /** A new object every time: a field object holds the value of one entry. */
    public function create(array $field_definition): FieldsInterface
    {
        if (!isset(self::$field_class_map[$field_definition['html_type']])) {
            throw new InvalidArgumentException("Unknown field type: {$field_definition['html_type']}");
        }

        $field_class = self::$field_class_map[$field_definition['html_type']];

        return new $field_class($field_definition);
    }

    public static function supports(string $html_type): bool
    {
        return isset(self::$field_class_map[$html_type]);
    }
}