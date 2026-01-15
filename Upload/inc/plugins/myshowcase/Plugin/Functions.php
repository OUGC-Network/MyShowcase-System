<?php
/**
 * MyShowcase Plugin for MyBB - Main Plugin
 * Copyright 2012 CommunityPlugins.com, All Rights Reserved
 *
 * Website: https://github.com/Sama34/MyShowcase-System
 * Version 2.5.2
 * License: Creative Commons Attribution-NonCommerical ShareAlike 3.0
 * http://creativecommons.org/licenses/by-nc-sa/3.0/legalcode
 * File: \inc\plugins\myshowcase.php
 *
 */

declare(strict_types=1);

namespace MyShowcase\Plugin\Functions;

use MyShowcase\Enum\FieldDefaultTypes;
use MyShowcase\Enum\FieldHtmlTypes;
use MyShowcase\Enum\FieldTypes;
use MyShowcase\Enum\FilterTypes;
use MyShowcase\Enum\FormatTypes;
use MyShowcase\Enum\ModeratorPermissions;
use MyShowcase\Enum\UserPermissions;
use MyShowcase\Repository\PermissionsRepository;
use MyShowcase\Showcase;
use Postparser;
use ReflectionClass;

use const MyShowcase\Plugin\Core\DATA_TABLE_STRUCTURE;
use const MyShowcase\Plugin\Core\TABLES_DATA;
use const MyShowcase\SETTINGS;

function loadLanguage(
    string $languageFileName = 'myshowcase',
    bool $forceUserArea = false,
    bool $suppressError = false
): bool {
    global $lang;

    $lang->load(
        $languageFileName,
        $forceUserArea,
        $suppressError
    );

    return true;
}

function hooksAdd(string $namespace): bool
{
    global $plugins;

    $namespaceLowercase = strtolower($namespace);
    $definedUserFunctions = get_defined_functions()['user'];

    foreach ($definedUserFunctions as $callable) {
        $namespaceWithPrefixLength = strlen($namespaceLowercase) + 1;

        if (substr($callable, 0, $namespaceWithPrefixLength) === $namespaceLowercase . '\\') {
            $hookName = substr_replace($callable, '', 0, $namespaceWithPrefixLength);

            $priority = substr($callable, -2);

            if (is_numeric(substr($hookName, -2))) {
                $hookName = substr($hookName, 0, -2);
            } else {
                $priority = 10;
            }

            $plugins->add_hook($hookName, $callable, (int)$priority);
        }
    }

    return true;
}

function hooksRun(
    string $hookName,
    mixed &$hookArguments = []
): mixed {
    global $plugins;

    return $plugins->run_hooks('myshowcase_system_' . $hookName, $hookArguments);
}

function getSetting(string $settingKey = '')
{
    global $mybb;

    return SETTINGS[$settingKey] ?? $mybb->settings['myshowcase_' . $settingKey] ?? false;
}

function templateGetName(string $templateName = '', string $showcasePrefix = '', bool $addPrefix = true): string
{
    $templatePrefix = $showcasePrefix !== '' ? $showcasePrefix . '_' : '';

    if ($templateName && $addPrefix) {
        $templatePrefix = '_';
    }

    if ($addPrefix) {
        $templatePrefix = 'myShowcase' . $templatePrefix;
    }

    return $templatePrefix . $templateName;
}

//set default permissions for all groups in all myshowcases
//if you edit or reorder these, you need to also edit
//the summary.php file (starting line 156) so the fields match this order
function showcaseDefaultPermissions(): array
{
    return [
        UserPermissions::EntriesCanPost => false,
        UserPermissions::EntriesCanUpdate => false,
        UserPermissions::AttachmentsCanUpload => false,
        UserPermissions::MainCanView => true,
        UserPermissions::CommentsCanView => true,
        UserPermissions::AttachmentsCanView => true,
        UserPermissions::CommentsCanPost => false,
        UserPermissions::CommentsCanDelete => false,
        UserPermissions::CanSearchEntries => true,
    ];
}

function showcaseDefaultModeratorPermissions(): array
{
    return [
        ModeratorPermissions::EntriesCanView => false,
        ModeratorPermissions::EntriesCanView => false,
        ModeratorPermissions::EntriesCanView => false,
        ModeratorPermissions::CommentsCanView => false
    ];
}

function showcaseDelete(int $showcaseID): bool
{
    global $db, $mybb, $lang;

    $hookArguments = [
        'showcaseID' => &$showcaseID,
    ];

    $showcase = new Showcase($db, $mybb, $lang, showcase_id: $showcaseID);

    $hookArguments = hooksRun('showcase_delete_start', $hookArguments);

    $permissions_repository = new PermissionsRepository($db, ['showcase_id' => $showcaseID]);

    foreach ($permissions_repository->queryPermissions() as $permissionID => $permissionData) {
        $permissions_repository->deletePermission($permissionID);
    }

    foreach ($showcase->moderators_repository->queryModerators() as $moderatorID => $moderatorData) {
        $showcase->moderators_repository->deleteModerator($moderatorID);
    }

    foreach (
        $showcase->comments_repository->queryComments(query_fields: ['comment_id']) as $commentID => $commentData
    ) {
        $showcase->comments_repository->deleteComment($commentID);
    }

    foreach ($showcase->attachments_repository->queryAttachments() as $attachmentID => $attachmentData) {
        $showcase->attachments_repository->deleteAttachment($attachmentID);
    }

    if (showcaseDataTableExists($showcaseID)) {
        showcaseDataTableDrop($showcaseID);
    }

    $db->delete_query('myshowcase_config', "showcase_id='{$showcaseID}'");

    return true;
}

function showcaseGet(array $whereClauses = [], array $queryFields = [], array $queryOptions = []): array
{
    global $db;

    $query = $db->simple_select(
        'myshowcase_config',
        implode(',', array_merge(['showcase_id'], $queryFields)),
        implode(' AND ', $whereClauses),
        $queryOptions
    );

    if (isset($queryOptions['limit']) && $queryOptions['limit'] === 1) {
        return (array)$db->fetch_array($query);
    }

    $showcaseObjects = [];

    while ($showcaseData = $db->fetch_array($query)) {
        $showcaseObjects[(int)$showcaseData['showcase_id']] = $showcaseData;
    }

    return $showcaseObjects;
}

function showcaseDataTableExists(int $showcaseID): bool
{
    static $dataTableExists = [];

    if (!isset($dataTableExists[$showcaseID])) {
        global $db;

        $dataTableExists[$showcaseID] = (bool)$db->table_exists('myshowcase_data' . $showcaseID);
    }

    return $dataTableExists[$showcaseID];
}

function showcaseDataTableFieldExists(int $showcaseID, string $fieldName): bool
{
    global $db;

    return $db->field_exists($fieldName, 'myshowcase_data' . $showcaseID);
}

function showcaseDataTableFieldRename(
    int $showcaseID,
    string $oldFieldName,
    string $newFieldName,
    string $newDefinition
): bool {
    global $db;
    return $db->rename_column('myshowcase_data' . $showcaseID, $oldFieldName, $newFieldName, $newDefinition);
}

function showcaseDataTableDrop(int $showcaseID): bool
{
    global $db;

    $hookArguments = [
        'showcaseID' => &$showcaseID,
    ];

    $hookArguments = hooksRun('showcase_data_table_drop_start', $hookArguments);

    $db->drop_table('myshowcase_data' . $showcaseID);

    return true;
}

function showcaseDataTableFieldDrop(int $showcaseID, string $fieldName): bool
{
    global $db, $mybb, $lang;

    $hookArguments = [
        'showcaseID' => &$showcaseID,
        'fieldName' => &$fieldName,
    ];

    $showcase = new Showcase($db, $mybb, $lang, showcase_id: $showcaseID);

    $hookArguments = hooksRun('showcase_data_table_field_drop_start', $hookArguments);

    foreach ($showcase->entries_repository->queryEntries(query_fields: ['entry_id']) as $entryID => $entryData) {
        $showcase->entries_repository->deleteEntry($entryID);
    }

    $db->drop_column('myshowcase_data' . $showcaseID, $fieldName);

    return true;
}

function fieldsetInsert(array $fieldsetData, bool $isUpdate = false, $fieldsetID = 0): int
{
    global $db;

    $tablesData = TABLES_DATA['myshowcase_fieldsets'];

    $insertData = [];

    $hookArguments = [
        'insertData' => &$insertData,
        'fieldsetData' => &$fieldsetData,
        'isUpdate' => $isUpdate,
        'fieldsetID' => &$fieldsetID,
        'tableData' => &$tablesData,
    ];

    foreach ($tablesData as $fieldName => $fieldDefinition) {
        if (isset($fieldsetData[$fieldName])) {
            $insertData[$fieldName] = sanitizeTableFieldValue($fieldsetData[$fieldName], $fieldDefinition['type']);
        }
    }

    $hookArguments = hooksRun('fieldset_insert_update_end', $hookArguments);

    if ($isUpdate) {
        $db->update_query('myshowcase_fieldsets', $insertData, "set_id='{$fieldsetID}'");
    } else {
        $db->insert_query('myshowcase_fieldsets', $insertData);

        $fieldsetID = (int)$db->insert_id();
    }

    return $fieldsetID;
}

function fieldsetUpdate(array $fieldsetData, int $fieldsetID): int
{
    return fieldsetInsert($fieldsetData, true, $fieldsetID);
}

function fieldsetGet(array $whereClauses = [], array $queryFields = [], array $queryOptions = []): array
{
    global $db;

    $query = $db->simple_select(
        'myshowcase_fieldsets',
        implode(',', array_merge(['set_id'], $queryFields)),
        implode(' AND ', $whereClauses),
        $queryOptions
    );

    if (isset($queryOptions['limit']) && $queryOptions['limit'] === 1) {
        return (array)$db->fetch_array($query);
    }

    $fieldsetData = [];

    while ($fieldset = $db->fetch_array($query)) {
        $fieldsetData[(int)$fieldset['set_id']] = $fieldset;
    }

    return $fieldsetData;
}

function fieldsetDelete(int $fieldsetID): bool
{
    global $db;

    $hookArguments = [
        'fieldsetID' => &$fieldsetID,
    ];

    $hookArguments = hooksRun('fieldset_delete_start', $hookArguments);

    foreach (fieldDataGet(["set_id='{$fieldsetID}'"]) as $fieldDataID => $fieldDataData) {
        fieldDataDelete($fieldDataID);
    }

    $db->delete_query('myshowcase_fieldsets', "set_id='{$fieldsetID}'");

    return true;
}

function fieldsInsert(array $fieldData, bool $isUpdate = false, int $fieldID = 0): int
{
    global $db;

    $tablesData = TABLES_DATA['myshowcase_fields'];

    $insertData = [];

    $hookArguments = [
        'insertData' => &$insertData,
        'fieldData' => &$fieldData,
        'isUpdate' => $isUpdate,
        'fieldID' => &$fieldID,
        'tableData' => &$tablesData,
    ];

    foreach ($tablesData as $fieldName => $fieldDefinition) {
        if (isset($fieldData[$fieldName])) {
            if (is_array($fieldData[$fieldName])) {
                $fieldData[$fieldName] = implode(',', $fieldData[$fieldName]);
            }

            $insertData[$fieldName] = sanitizeTableFieldValue($fieldData[$fieldName], $fieldDefinition['type']);
        }
    }

    $hookArguments = hooksRun('field_insert_update_end', $hookArguments);

    if ($isUpdate) {
        $db->update_query('myshowcase_fields', $insertData, "field_id='{$fieldID}'");
    } else {
        $db->insert_query('myshowcase_fields', $insertData);
    }

    return $fieldID;
}

function fieldsUpdate(array $fieldDefinition, int $fieldID): int
{
    return fieldsInsert($fieldDefinition, true, $fieldID);
}

function fieldsGet(array $whereClauses = [], array $queryFields = [], array $queryOptions = []): array
{
    global $db;

    $query = $db->simple_select(
        'myshowcase_fields',
        implode(',', array_merge(['field_id'], $queryFields)),
        implode(' AND ', $whereClauses),
        $queryOptions
    );

    if (isset($queryOptions['limit']) && $queryOptions['limit'] === 1) {
        return (array)$db->fetch_array($query);
    }

    $fieldDefinition = [];

    while ($field = $db->fetch_array($query)) {
        $fieldDefinition[(int)$field['field_id']] = $field;
    }

    return $fieldDefinition;
}

function fieldsDelete(int $fieldID): bool
{
    global $db;

    $hookArguments = [
        'fieldID' => &$fieldID,
    ];

    $hookArguments = hooksRun('field_delete_start', $hookArguments);

    foreach (fieldDataGet(["field_id='{$fieldID}'"]) as $fieldDataID => $fieldDataData) {
        fieldDataDelete($fieldDataID);
    }

    $db->delete_query('myshowcase_fields', "field_id='{$fieldID}'");

    return true;
}

function fieldDataInsert(array $fieldData, bool $isUpdate = false, int $fieldDataID = 0): int
{
    global $db;

    $tablesData = TABLES_DATA['myshowcase_field_data'];

    $insertData = [];

    $hookArguments = [
        'insertData' => &$insertData,
        'fieldData' => &$fieldData,
        'isUpdate' => $isUpdate,
        'fieldID' => &$fieldID,
        'tableData' => &$tablesData,
    ];

    foreach ($tablesData as $fieldName => $fieldDefinition) {
        if (isset($fieldData[$fieldName])) {
            $insertData[$fieldName] = sanitizeTableFieldValue($fieldData[$fieldName], $fieldDefinition['type']);
        }
    }

    $hookArguments = hooksRun('field_data_insert_update_end', $hookArguments);

    if ($isUpdate) {
        $db->update_query('myshowcase_field_data', $insertData, "field_data_id='{$fieldDataID}'");
    } else {
        $db->insert_query('myshowcase_field_data', $insertData);

        $fieldDataID = (int)$db->insert_id();
    }

    return $fieldDataID;
}

function fieldDataUpdate(array $fieldData, int $fieldDataID): int
{
    return fieldDataInsert($fieldData, true, $fieldDataID);
}

function fieldDataGet(array $whereClauses = [], array $queryFields = [], array $queryOptions = []): array
{
    global $db;

    $query = $db->simple_select(
        'myshowcase_field_data',
        implode(',', array_merge(['field_data_id'], $queryFields)),
        implode(' AND ', $whereClauses),
        $queryOptions
    );

    if (isset($queryOptions['limit']) && $queryOptions['limit'] === 1) {
        return (array)$db->fetch_array($query);
    }

    $fieldDefinition = [];

    while ($field = $db->fetch_array($query)) {
        $fieldDefinition[(int)$field['field_data_id']] = $field;
    }

    return $fieldDefinition;
}

function fieldDataDelete(int $fieldDataID): bool
{
    global $db;

    $hookArguments = [
        'fieldDataID' => &$fieldDataID,
    ];

    $hookArguments = hooksRun('field_data_delete_start', $hookArguments);

    $db->delete_query('myshowcase_field_data', "field_data_id='{$fieldDataID}'");

    return true;
}

function dataTableStructureGet(int $showcaseID = 0): array
{
    global $db;

    $dataTableStructure = DATA_TABLE_STRUCTURE['myshowcase_data'];

    if ($showcaseID &&
        ($showcaseData = showcaseGet(["showcase_id='{$showcaseID}'"], ['field_set_id'], ['limit' => 1]))) {
        $fieldsetID = (int)$showcaseData['field_set_id'];

        foreach (
            fieldsGet(
                ["set_id='{$fieldsetID}'"],
                ['field_key', 'field_type', 'maximum_length', 'is_required', 'default_value', 'default_type']
            ) as $fieldID => $fieldDefinition
        ) {
            $dataTableStructure[$fieldDefinition['field_key']] = [];

            if (fieldTypeMatchInt($fieldDefinition['field_type'])) {
                $dataTableStructure[$fieldDefinition['field_key']]['type'] = $fieldDefinition['field_type'];

                $dataTableStructure[$fieldDefinition['field_key']]['size'] = (int)$fieldDefinition['maximum_length'];

                if ($fieldDefinition['default_value'] !== '') {
                    $defaultValue = (int)$fieldDefinition['default_value'];
                } else {
                    $defaultValue = 0;
                }
            } elseif (fieldTypeMatchFloat($fieldDefinition['field_type'])) {
                $dataTableStructure[$fieldDefinition['field_key']]['type'] = $fieldDefinition['field_type'];

                $dataTableStructure[$fieldDefinition['field_key']]['size'] = (float)$fieldDefinition['maximum_length'];

                if ($fieldDefinition['default_value'] !== '') {
                    $defaultValue = (float)$fieldDefinition['default_value'];
                } else {
                    $defaultValue = 0;
                }
            } elseif (fieldTypeMatchChar($fieldDefinition['field_type'])) {
                $dataTableStructure[$fieldDefinition['field_key']]['type'] = $fieldDefinition['field_type'];

                $dataTableStructure[$fieldDefinition['field_key']]['size'] = (int)$fieldDefinition['maximum_length'];

                if ($fieldDefinition['default_value'] !== '') {
                    $defaultValue = $db->escape_string($fieldDefinition['default_value']);
                } else {
                    $defaultValue = '';
                }
            } elseif (fieldTypeMatchText($fieldDefinition['field_type'])) {
                $dataTableStructure[$fieldDefinition['field_key']]['type'] = $fieldDefinition['field_type'];

                // todo, TEXT fields cannot have default values, should validate in front end
                $fieldDefinition['default_type'] = FieldDefaultTypes::IsNull;
            } elseif (fieldTypeMatchDateTime($fieldDefinition['field_type'])) {
                $dataTableStructure[$fieldDefinition['field_key']]['type'] = $fieldDefinition['field_type'];

                if ($fieldDefinition['default_value'] !== '') {
                    $defaultValue = $db->escape_string($fieldDefinition['default_value']);
                } else {
                    $defaultValue = '';
                }
            } elseif (fieldTypeMatchBinary($fieldDefinition['field_type'])) {
                $dataTableStructure[$fieldDefinition['field_key']]['type'] = $fieldDefinition['field_type'];

                $dataTableStructure[$fieldDefinition['field_key']]['size'] = (int)$fieldDefinition['maximum_length'];

                if ($fieldDefinition['default_value'] !== '') {
                    $defaultValue = $db->escape_string($fieldDefinition['default_value']);
                } else {
                    $defaultValue = '';
                }
            }

            switch ($fieldDefinition['default_type']) {
                case FieldDefaultTypes::AsDefined:
                    if ($fieldDefinition['default_value'] !== '' && isset($defaultValue)) {
                        $dataTableStructure[$fieldDefinition['field_key']]['default'] = $defaultValue;
                    }
                    break;
                case FieldDefaultTypes::IsNull:
                    unset($dataTableStructure[$fieldDefinition['field_key']]['default']);

                    $dataTableStructure[$fieldDefinition['field_key']]['null'] = true;
                    break;
                case FieldDefaultTypes::CurrentTimestamp:
                    unset($dataTableStructure[$fieldDefinition['field_key']]['default']);

                    $dataTableStructure[$fieldDefinition['field_key']]['default'] = 'TIMESTAMP';
                    break;
                case FieldDefaultTypes::UUID:
                    unset($dataTableStructure[$fieldDefinition['field_key']]['default']);

                    $dataTableStructure[$fieldDefinition['field_key']]['default'] = 'UUID';
                    break;
            }

            if (!empty($fieldDefinition['is_required'])) {
                global $mybb;

                if (fieldTypeSupportsFullText($fieldDefinition['field_type']) &&
                    $mybb->settings['searchtype'] === 'fulltext') {
                    isset($dataTableStructure['full_keys']) || $dataTableStructure['full_keys'] = [];

                    $dataTableStructure['full_keys'][$fieldDefinition['field_key']] = $fieldDefinition['field_key'];
                } else {
                    isset($dataTableStructure['keys']) || $dataTableStructure['keys'] = [];

                    $dataTableStructure['keys'][$fieldDefinition['field_key']] = $fieldDefinition['field_key'];
                }
                // todo: add key for uid & approved
            }
        }
    }

    return hooksRun('data_table_structure_end', $dataTableStructure);
}

function postParser(): postParser
{
    global $parser;

    if (!($parser instanceof postParser)) {
        require_once MYBB_ROOT . 'inc/class_parser.php';

        $parser = new Postparser();
    }

    return $parser;
}

function formatTypes(): array
{
    return [
        FormatTypes::noFormat => '',
        FormatTypes::numberFormat => 'my_number_format(#,###)',
        FormatTypes::numberFormat1 => 'my_number_format(#,###.#)',
        FormatTypes::numberFormat2 => 'my_number_format(#,###.##)',
        FormatTypes::htmlSpecialCharactersUni => 'htmlspecialchars_uni',
        FormatTypes::stripTags => 'strip_tags',
    ];
}

function FilterTypes(): array
{
    return [
        FilterTypes::NoFilter => '',
        FilterTypes::UUID => 'UUID',
    ];
}

function formatField(int $formatType, mixed &$fieldValue): string
{
    $fieldValue = match ($formatType) {
        FormatTypes::numberFormat => $fieldValue = my_number_format((int)$fieldValue),
        FormatTypes::numberFormat1 => $fieldValue = number_format((float)$fieldValue, 1),
        FormatTypes::numberFormat2 => $fieldValue = number_format((float)$fieldValue, 2),
        FormatTypes::htmlSpecialCharactersUni => $fieldValue = htmlspecialchars_uni((string)$fieldValue),
        FormatTypes::stripTags => $fieldValue = strip_tags((string)$fieldValue),
        default => (string)$fieldValue
    };

    return $fieldValue;
}

function fieldTypesGet(): array
{
    return array_flip((new ReflectionClass(FieldTypes::class))->getConstants());
}

function fieldTypeMatchInt(string $fieldType): bool
{
    return in_array(my_strtolower($fieldType), [
        FieldTypes::TinyInteger => FieldTypes::TinyInteger,
        FieldTypes::SmallInteger => FieldTypes::SmallInteger,
        FieldTypes::MediumInteger => FieldTypes::MediumInteger,
        FieldTypes::BigInteger => FieldTypes::BigInteger,
        FieldTypes::Integer => FieldTypes::Integer,
    ], true);
}

function fieldTypeMatchFloat(string $fieldType): bool
{
    return in_array(my_strtolower($fieldType), [
        FieldTypes::Decimal => FieldTypes::Decimal,
        FieldTypes::Float => FieldTypes::Float,
        FieldTypes::Double => FieldTypes::Double,
    ], true);
}

function fieldTypeMatchChar(string $fieldType): bool
{
    return in_array(my_strtolower($fieldType), [
        FieldTypes::Char => FieldTypes::Char,
        FieldTypes::VarChar => FieldTypes::VarChar,
    ], true);
}

function fieldTypeMatchText(string $fieldType): bool
{
    return in_array(my_strtolower($fieldType), [
        FieldTypes::TinyText => FieldTypes::TinyText,
        FieldTypes::Text => FieldTypes::Text,
        FieldTypes::MediumText => FieldTypes::MediumText,
    ], true);
}

function fieldTypeMatchDateTime(string $fieldType): bool
{
    return in_array(my_strtolower($fieldType), [
        FieldTypes::Date => FieldTypes::Date,
        FieldTypes::Time => FieldTypes::Time,
        FieldTypes::DateTime => FieldTypes::DateTime,
        FieldTypes::TimeStamp => FieldTypes::TimeStamp,
    ], true);
}

function fieldTypeMatchBinary(string $fieldType): bool
{
    return in_array(my_strtolower($fieldType), [
        FieldTypes::Binary => FieldTypes::Binary,
        FieldTypes::VarBinary => FieldTypes::VarBinary,
    ], true);
}

function fieldTypeSupportsFullText(string $fieldType): bool
{
    return in_array(my_strtolower($fieldType), [
        FieldTypes::Char => FieldTypes::Char,
        FieldTypes::VarChar => FieldTypes::VarChar,

        FieldTypes::Text => FieldTypes::Text,
    ], true);
}

function fileCaptureTypes(): array
{
    global $lang;

    return [
        0 => $lang->none,
        1 => 'user',
        2 => 'environment',
    ];
}

function fieldHtmlTypes(): array
{
    return array_flip((new ReflectionClass(FieldHtmlTypes::class))->getConstants());
}

function fieldDefaultTypes(): array
{
    global $lang;

    loadLanguage();

    return [
        FieldDefaultTypes::AsDefined => $lang->myShowcaseAdminFieldsCreateUpdateFormDefaultTypeAsDefined,
        FieldDefaultTypes::IsNull => $lang->myShowcaseAdminFieldsCreateUpdateFormDefaultTypeNull,
        FieldDefaultTypes::CurrentTimestamp => $lang->myShowcaseAdminFieldsCreateUpdateFormDefaultTypeTimeStamp,
        FieldDefaultTypes::UUID => $lang->myShowcaseAdminFieldsCreateUpdateFormDefaultTypeUUID,
    ];
}

function castTableFieldValue(mixed $value, string $fieldType): mixed
{
    if (fieldTypeMatchInt($fieldType)) {
        $value = (int)$value;
    } elseif (fieldTypeMatchFloat($fieldType)) {
        $value = (float)$value;
    } elseif (fieldTypeMatchChar($fieldType) ||
        fieldTypeMatchText($fieldType) ||
        fieldTypeMatchDateTime($fieldType)) {
        $value = (string)$value;
    }

    return $value;
}

function sanitizeTableFieldValue(mixed $value, string $fieldType)
{
    global $db;

    if (fieldTypeMatchInt($fieldType)) {
        $value = (int)$value;
    } elseif (fieldTypeMatchFloat($fieldType)) {
        $value = (float)$value;
    } elseif (fieldTypeMatchChar($fieldType) ||
        fieldTypeMatchText($fieldType) ||
        fieldTypeMatchDateTime($fieldType)) {
        $value = $db->escape_string($value);
    } elseif (fieldTypeMatchBinary($fieldType)) {
        $value = $db->escape_binary($value);
    }

    return $value;
}

function attachmentLogInsert(array $logData, bool $isUpdate = false, int $logID = 0)
{
    global $db;

    $tablesData = TABLES_DATA['myshowcase_attachments_download_logs'];

    $insertData = [];

    $hookArguments = [
        'insertData' => &$insertData,
        'logData' => &$logData,
        'isUpdate' => $isUpdate,
        'logID' => &$logID,
        'tableData' => &$tablesData,
    ];

    foreach ($tablesData as $fieldName => $fieldDefinition) {
        if (isset($logData[$fieldName])) {
            $insertData[$fieldName] = sanitizeTableFieldValue($logData[$fieldName], $fieldDefinition['type']);
        }
    }

    $hookArguments = hooksRun('attachment_log_insert_update_end', $hookArguments);

    if ($isUpdate) {
        $db->update_query('myshowcase_attachments_download_logs', $insertData, "log_id='{$logID}'");
    } else {
        $logID = (int)$db->insert_query('myshowcase_attachments_download_logs', $insertData);
    }

    return $logID;
}