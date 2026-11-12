<?php

declare(strict_types=1);

namespace MyShowcase\Util;

use MyShowcase\Entity\CustomFieldEntity;
use MyShowcase\Enum\FieldDefaultTypes;
use Random\RandomException;

class FieldDefaultUtil
{
    /**
     * The value a field takes when nothing was given for it, as configured on the field (default_type and
     * default_value). Null means the field has no default and keeps the column default.
     *
     * @param array|CustomFieldEntity $field_definition A row of myshowcase_fields or the field entity.
     * @throws RandomException
     */
    public static function valueFor(array|CustomFieldEntity $field_definition): mixed
    {
        if (!is_array($field_definition)) {
            $field_definition = get_object_vars($field_definition);
        }

        return match ($field_definition['default_type']) {
            FieldDefaultTypes::AsDefined => $field_definition['default_value'],
            FieldDefaultTypes::CurrentTimestamp => date('Y-m-d H:i:s', TIME_NOW),
            FieldDefaultTypes::UUID => (new UuidUtil())->generateUuidV4(),
            default => null,
        };
    }
}
