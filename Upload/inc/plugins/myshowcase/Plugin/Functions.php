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

use DateTime;
use DateTimeZone;
use Exception;
use MyShowcase\Enum\FieldDefaultTypes;
use MyShowcase\Enum\FieldHtmlTypes;
use MyShowcase\Enum\FieldTypes;
use MyShowcase\Enum\FilterTypes;
use MyShowcase\Enum\FormatTypes;
use MyShowcase\Enum\ModeratorPermissions;
use MyShowcase\Enum\UserPermissions;
use MyShowcase\Repository\AttachmentsRepository;
use MyShowcase\Repository\CommentsRepository;
use MyShowcase\Repository\EntriesRepository;
use MyShowcase\Repository\ModeratorsRepository;
use MyShowcase\Repository\PermissionsRepository;
use MyShowcase\Service\SchemaManager;
use MyShowcase\Util\BuildShowcase;
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
        ModeratorPermissions::CommentsCanView => false,
        ModeratorPermissions::CanManageAttachments => false,
        ModeratorPermissions::CanManageReports => false,
        ModeratorPermissions::CanManageLogs => false,
    ];
}

function showcaseDelete(int $showcase_id): bool
{
    global $db;

    $hookArguments = [
        'showcaseID' => &$showcase_id,
    ];

    $showcase = (new BuildShowcase())->getShowcase($showcase_id);

    $hookArguments = hooksRun('showcase_delete_start', $hookArguments);

    foreach ($showcase->context->permissions_repository->queryPermissions() as $permissionID => $permissionData) {
        $showcase->context->permissions_repository->deletePermission($permissionID);
    }

    $moderators_repository = $showcase->context->moderators_repository;

    foreach ($moderators_repository->queryModerators() as $moderatorID => $moderatorData) {
        $moderators_repository->deleteModerator($moderatorID);
    }

    $comments_repository = $showcase->context->comments_repository;

    foreach (
        $comments_repository->queryComments(query_fields: ['comment_id']) as $commentID => $commentData
    ) {
        $comments_repository->deleteComment($commentID);
    }

    $attachments_repository = $showcase->context->attachments_repository;

    foreach ($attachments_repository->queryAttachments() as $attachmentID => $attachmentData) {
        $attachments_repository->deleteAttachment($attachmentID);
    }

    if (showcaseDataTableExists($showcase_id)) {
        showcaseDataTableDrop($showcase_id);
    }

    $db->delete_query('myshowcase_config', "showcase_id='{$showcase_id}'");

    return true;
}

function showcaseGet(
    array $whereClauses = [],
    array $queryFields = [],
    array $queryOptions = [],
    bool $useMemo = true
): array {
    $key = md5(serialize($whereClauses + $queryFields + $queryOptions));

    static $showcaseObjects = [];

    if ($useMemo && isset($showcaseObjects[$key])) {
        return $showcaseObjects[$key];
    }

    global $db;

    static $tableExists = null;

    if ($tableExists === null) {
        $tableExists = $db->table_exists('myshowcase_config');
    }

    if (!$tableExists) {
        //return [];
    }

    global $mybb;

    $query = $db->simple_select(
        'myshowcase_config',
        implode(',', array_merge(['showcase_id'], $queryFields)),
        implode(' AND ', $whereClauses),
        $queryOptions
    );

    if (isset($queryOptions['limit']) && $queryOptions['limit'] === 1) {
        return (array)$db->fetch_array($query);
    }

    $showcaseObjects[$key] = [];

    while ($showcase_data = $db->fetch_array($query)) {
        $showcaseObjects[$key][(int)$showcase_data['showcase_id']] = $showcase_data;
    }

    return $showcaseObjects[$key];
}

function showcaseDataTableExists(int $showcase_id, bool $refresh = false): bool
{
    static $dataTableExists = [];

    if ($refresh || !isset($dataTableExists[$showcase_id])) {
        global $db;

        $dataTableExists[$showcase_id] = (bool)$db->table_exists('myshowcase_data' . $showcase_id);
    }

    return $dataTableExists[$showcase_id];
}

function showcaseDataTableFieldExists(int $showcase_id, string $field_name): bool
{
    global $db;

    return $db->field_exists($field_name, 'myshowcase_data' . $showcase_id);
}

function showcaseDataTableFieldRename(
    int $showcase_id,
    string $oldFieldName,
    string $newFieldName,
    string $newDefinition
): bool {
    global $db;
    return $db->rename_column('myshowcase_data' . $showcase_id, $oldFieldName, $newFieldName, $newDefinition);
}

function showcaseDataTableDrop(int $showcase_id): bool
{
    global $db;

    $hookArguments = [
        'showcaseID' => &$showcase_id,
    ];

    $hookArguments = hooksRun('showcase_data_table_drop_start', $hookArguments);

    // entry_id is the serial column; gid is its name in installations that were never migrated.
    SchemaManager::dropTable('myshowcase_data' . $showcase_id, ['entry_id', 'gid']);

    return true;
}

function showcaseDataTableFieldDrop(int $showcase_id, string $field_name): bool
{
    global $db;

    $hookArguments = [
        'showcaseID' => &$showcase_id,
        'fieldName' => &$field_name,
    ];

    $showcase = (new BuildShowcase())->getShowcase($showcase_id);

    $hookArguments = hooksRun('showcase_data_table_field_drop_start', $hookArguments);

    $entries_repository = $showcase->context->entries_repository;

    foreach ($entries_repository->queryEntries(query_fields: ['entry_id']) as $entryID => $entryData) {
        $entries_repository->deleteEntry($entryID);
    }

    $db->drop_column('myshowcase_data' . $showcase_id, $field_name);

    return true;
}

function collectionInsert(array $collectionData, bool $isUpdate = false, $collection_id = 0): int
{
    global $db;

    $tablesData = TABLES_DATA['myshowcase_collections'];

    $insertData = [];

    $hookArguments = [
        'insertData' => &$insertData,
        'collectionData' => &$collectionData,
        'isUpdate' => $isUpdate,
        'collectionID' => &$collection_id,
        'tableData' => &$tablesData,
    ];

    foreach ($tablesData as $field_name => $field_definition) {
        if (isset($collectionData[$field_name])) {
            $insertData[$field_name] = sanitizeTableFieldValue($collectionData[$field_name], $field_definition['type']);
        }
    }

    $hookArguments = hooksRun('collection_insert_update_end', $hookArguments);

    if ($isUpdate) {
        $db->update_query('myshowcase_collections', $insertData, "collection_id='{$collection_id}'");
    } else {
        $db->insert_query('myshowcase_collections', $insertData);

        $collection_id = (int)$db->insert_id();
    }

    return $collection_id;
}

function collectionUpdate(array $collectionData, int $collection_id): int
{
    return collectionInsert($collectionData, true, $collection_id);
}

function collectionGet(array $whereClauses = [], array $queryFields = [], array $queryOptions = []): array
{
    global $db;

    $query = $db->simple_select(
        'myshowcase_collections',
        implode(',', array_merge(['collection_id'], $queryFields)),
        implode(' AND ', $whereClauses),
        $queryOptions
    );

    if (isset($queryOptions['limit']) && $queryOptions['limit'] === 1) {
        return (array)$db->fetch_array($query);
    }

    $collectionData = [];

    while ($collection = $db->fetch_array($query)) {
        $collectionData[(int)$collection['collection_id']] = $collection;
    }

    return $collectionData;
}

function collectionDelete(int $collection_id): bool
{
    global $db;

    $hookArguments = [
        'collectionID' => &$collection_id,
    ];

    $hookArguments = hooksRun('collection_delete_start', $hookArguments);

    foreach (fieldDataGet(["collection_id='{$collection_id}'"]) as $fieldDataID => $fieldDataData) {
        fieldDataDelete($fieldDataID);
    }

    $db->delete_query('myshowcase_collections', "collection_id='{$collection_id}'");

    return true;
}

function fieldsInsert(array $field_data, bool $isUpdate = false, int $field_id = 0): int
{
    global $db;

    $tablesData = TABLES_DATA['myshowcase_fields'];

    $insertData = [];

    $hookArguments = [
        'insertData' => &$insertData,
        'fieldData' => &$field_data,
        'isUpdate' => $isUpdate,
        'fieldID' => &$field_id,
        'tableData' => &$tablesData,
    ];

    foreach ($tablesData as $field_name => $field_definition) {
        if (isset($field_data[$field_name])) {
            if (is_array($field_data[$field_name])) {
                $field_data[$field_name] = implode(',', $field_data[$field_name]);
            }

            $insertData[$field_name] = sanitizeTableFieldValue($field_data[$field_name], $field_definition['type']);
        }
    }

    $hookArguments = hooksRun('field_insert_update_end', $hookArguments);

    if ($isUpdate) {
        $db->update_query('myshowcase_fields', $insertData, "field_id='{$field_id}'");
    } else {
        $db->insert_query('myshowcase_fields', $insertData);

        // Same as collectionInsert() and fieldDataInsert(): hand back the id the database generated.
        $field_id = (int)$db->insert_id();
    }

    return $field_id;
}

function fieldsUpdate(array $field_definition, int $field_id): int
{
    return fieldsInsert($field_definition, true, $field_id);
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

    $field_definition = [];

    while ($field = $db->fetch_array($query)) {
        $field_definition[(int)$field['field_id']] = $field;
    }

    return $field_definition;
}

function fieldsDelete(int $field_id): bool
{
    global $db;

    $hookArguments = [
        'fieldID' => &$field_id,
    ];

    $hookArguments = hooksRun('field_delete_start', $hookArguments);

    foreach (fieldDataGet(["field_id='{$field_id}'"]) as $fieldDataID => $fieldDataData) {
        fieldDataDelete($fieldDataID);
    }

    $db->delete_query('myshowcase_fields', "field_id='{$field_id}'");

    return true;
}

function fieldDataInsert(array $field_data, bool $isUpdate = false, int $fieldDataID = 0): int
{
    global $db;

    $tablesData = TABLES_DATA['myshowcase_field_data'];

    $insertData = [];

    $hookArguments = [
        'insertData' => &$insertData,
        'fieldData' => &$field_data,
        'isUpdate' => $isUpdate,
        'fieldID' => &$field_id,
        'tableData' => &$tablesData,
    ];

    foreach ($tablesData as $field_name => $field_definition) {
        if (isset($field_data[$field_name])) {
            $insertData[$field_name] = sanitizeTableFieldValue($field_data[$field_name], $field_definition['type']);
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

function fieldDataUpdate(array $field_data, int $fieldDataID): int
{
    return fieldDataInsert($field_data, true, $fieldDataID);
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

    $field_definition = [];

    while ($field = $db->fetch_array($query)) {
        $field_definition[(int)$field['field_data_id']] = $field;
    }

    return $field_definition;
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

function dataTableStructureGet(int $showcase_id = 0): array
{
    global $db;

    $dataTableStructure = DATA_TABLE_STRUCTURE['myshowcase_data'];

    if ($showcase_id &&
        ($showcase_data = showcaseGet(["showcase_id='{$showcase_id}'"], ['collection_id'], ['limit' => 1]))) {
        $collection_id = (int)$showcase_data['collection_id'];

        foreach (
            fieldsGet(
                ["collection_id='{$collection_id}'"],
                ['field_key', 'field_type', 'maximum_length', 'is_required', 'default_value', 'default_type']
            ) as $field_id => $field_definition
        ) {
            $dataTableStructure[$field_definition['field_key']] = [];

            if (fieldTypeMatchInt($field_definition['field_type'])) {
                $dataTableStructure[$field_definition['field_key']]['type'] = $field_definition['field_type'];

                $dataTableStructure[$field_definition['field_key']]['size'] = (int)$field_definition['maximum_length'];

                if ($field_definition['default_value'] !== '') {
                    $defaultValue = (int)$field_definition['default_value'];
                } else {
                    $defaultValue = 0;
                }
            } elseif (fieldTypeMatchFloat($field_definition['field_type'])) {
                $dataTableStructure[$field_definition['field_key']]['type'] = $field_definition['field_type'];

                $dataTableStructure[$field_definition['field_key']]['size'] = (float)$field_definition['maximum_length'];

                if ($field_definition['default_value'] !== '') {
                    $defaultValue = (float)$field_definition['default_value'];
                } else {
                    $defaultValue = 0;
                }
            } elseif (fieldTypeMatchChar($field_definition['field_type'])) {
                $dataTableStructure[$field_definition['field_key']]['type'] = $field_definition['field_type'];

                $dataTableStructure[$field_definition['field_key']]['size'] = (int)$field_definition['maximum_length'];

                if ($field_definition['default_value'] !== '') {
                    $defaultValue = $db->escape_string($field_definition['default_value']);
                } else {
                    $defaultValue = '';
                }
            } elseif (fieldTypeMatchText($field_definition['field_type'])) {
                $dataTableStructure[$field_definition['field_key']]['type'] = $field_definition['field_type'];

                // todo, TEXT fields cannot have default values, should validate in front end
                $field_definition['default_type'] = FieldDefaultTypes::IsNull;
            } elseif (fieldTypeMatchDateTime($field_definition['field_type'])) {
                $dataTableStructure[$field_definition['field_key']]['type'] = $field_definition['field_type'];

                if ($field_definition['default_value'] !== '') {
                    $defaultValue = $db->escape_string($field_definition['default_value']);
                } else {
                    $defaultValue = '';
                }
            } elseif (fieldTypeMatchBinary($field_definition['field_type'])) {
                $dataTableStructure[$field_definition['field_key']]['type'] = $field_definition['field_type'];

                $dataTableStructure[$field_definition['field_key']]['size'] = (int)$field_definition['maximum_length'];

                if ($field_definition['default_value'] !== '') {
                    $defaultValue = $db->escape_string($field_definition['default_value']);
                } else {
                    $defaultValue = '';
                }
            }

            switch ($field_definition['default_type']) {
                case FieldDefaultTypes::AsDefined:
                    if ($field_definition['default_value'] !== '' && isset($defaultValue)) {
                        $dataTableStructure[$field_definition['field_key']]['default'] = $defaultValue;
                    }
                    break;
                case FieldDefaultTypes::IsNull:
                    unset($dataTableStructure[$field_definition['field_key']]['default']);

                    $dataTableStructure[$field_definition['field_key']]['null'] = true;
                    break;
                case FieldDefaultTypes::CurrentTimestamp:
                    unset($dataTableStructure[$field_definition['field_key']]['default']);

                    $dataTableStructure[$field_definition['field_key']]['default'] = 'TIMESTAMP';
                    break;
                case FieldDefaultTypes::UUID:
                    unset($dataTableStructure[$field_definition['field_key']]['default']);

                    $dataTableStructure[$field_definition['field_key']]['default'] = 'UUID';
                    break;
            }

            if (!empty($field_definition['is_required'])) {
                global $mybb;

                if (fieldTypeSupportsFullText($field_definition['field_type']) &&
                    $mybb->settings['searchtype'] === 'fulltext') {
                    isset($dataTableStructure['full_keys']) || $dataTableStructure['full_keys'] = [];

                    $dataTableStructure['full_keys'][$field_definition['field_key']] = $field_definition['field_key'];
                } else {
                    isset($dataTableStructure['keys']) || $dataTableStructure['keys'] = [];

                    $dataTableStructure['keys'][$field_definition['field_key']] = $field_definition['field_key'];
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
        FieldDefaultTypes::AsDefined => $lang->myshowcase_admin_fields_create_update_form_default_type_as_defined,
        FieldDefaultTypes::IsNull => $lang->myshowcase_admin_fields_create_update_form_default_type_null,
        FieldDefaultTypes::CurrentTimestamp => $lang->myshowcase_admin_fields_create_update_form_default_type_time_stamp,
        FieldDefaultTypes::UUID => $lang->myshowcase_admin_fields_create_update_form_default_type_uuid,
    ];
}

function castTableFieldValue(mixed $value, string $fieldType): mixed
{
    if (fieldTypeMatchInt($fieldType)) {
        $value = (int)$value;
    } elseif (fieldTypeMatchFloat($fieldType)) {
        $value = (float)$value;
    } elseif (fieldTypeMatchDateTime($fieldType) && !($value instanceof DateTime)) {
        try {
            $value = new DateTime($value, new DateTimeZone('UTC'));
        } catch (Exception $e) {
            $value = null;
        }
    } elseif (fieldTypeMatchChar($fieldType) ||
        fieldTypeMatchText($fieldType) ||
        fieldTypeMatchDateTime($fieldType) && !($value instanceof DateTime)) {
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
        (fieldTypeMatchDateTime($fieldType) && !($value instanceof DateTime))) {
        $value = $db->escape_string($value);
    } elseif (fieldTypeMatchDateTime($fieldType) && ($value instanceof DateTime)) {
        $value = date('Y-m-d H:i:s', $value->getTimestamp());
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

    foreach ($tablesData as $field_name => $field_definition) {
        if (isset($logData[$field_name])) {
            $insertData[$field_name] = sanitizeTableFieldValue($logData[$field_name], $field_definition['type']);
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