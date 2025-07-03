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

use Exception;
use Postparser;
use DirectoryIterator;
use ReflectionClass;
use MyShowcase\System\FilterTypes;
use MyShowcase\System\FieldDefaultTypes;
use MyShowcase\System\FieldHtmlTypes;
use MyShowcase\System\FieldTypes;
use MyShowcase\System\FormatTypes;
use MyShowcase\System\DataHandler;
use MyShowcase\System\Render;
use MyShowcase\System\ModeratorPermissions;
use MyShowcase\System\UserPermissions;
use MyShowcase\System\Showcase;
use MyShowcase\Fields\CheckBoxField;
use MyShowcase\Fields\ColorField;
use MyShowcase\Fields\DateField;
use MyShowcase\Fields\DateTimeLocalField;
use MyShowcase\Fields\EmailField;
use MyShowcase\Fields\FieldsInterface;
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

use const MyShowcase\Plugin\Core\ATTACHMENT_IMAGE_TOO_SMALL_FOR_THUMBNAIL;
use const MyShowcase\Plugin\Core\ATTACHMENT_STATUS_PENDING_APPROVAL;
use const MyShowcase\Plugin\Core\ATTACHMENT_STATUS_VISIBLE;
use const MyShowcase\Plugin\Core\ATTACHMENT_THUMBNAIL_ERROR;
use const MyShowcase\Plugin\Core\ATTACHMENT_THUMBNAIL_SMALL;
use const MyShowcase\Plugin\Core\CACHE_TYPE_ATTACHMENT_TYPES;
use const MyShowcase\Plugin\Core\CACHE_TYPE_CONFIG;
use const MyShowcase\Plugin\Core\CACHE_TYPE_FIELD_DATA;
use const MyShowcase\Plugin\Core\CACHE_TYPE_FIELD_SETS;
use const MyShowcase\Plugin\Core\CACHE_TYPE_FIELDS;
use const MyShowcase\Plugin\Core\CACHE_TYPE_MODERATORS;
use const MyShowcase\Plugin\Core\CACHE_TYPE_PERMISSIONS;
use const MyShowcase\Plugin\Core\DATA_HANDLER_METHOD_INSERT;
use const MyShowcase\Plugin\Core\DATA_TABLE_STRUCTURE;
use const MyShowcase\Plugin\Core\DEBUG;
use const MyShowcase\Plugin\Core\FILTER_TYPE_NONE;
use const MyShowcase\Plugin\Core\FILTER_TYPE_USER_ID;
use const MyShowcase\Plugin\Core\SETTINGS;
use const MyShowcase\Plugin\Core\TABLES_DATA;
use const MyShowcase\Plugin\Core\UPLOAD_STATUS_FAILED;
use const MyShowcase\Plugin\Core\UPLOAD_STATUS_INVALID;
use const MyShowcase\Plugin\Core\URL;
use const MyShowcase\Plugin\Core\WATERMARK_LOCATION_CENTER;
use const MyShowcase\Plugin\Core\WATERMARK_LOCATION_LOWER_LEFT;
use const MyShowcase\Plugin\Core\WATERMARK_LOCATION_LOWER_RIGHT;
use const MyShowcase\Plugin\Core\WATERMARK_LOCATION_UPPER_LEFT;
use const MyShowcase\Plugin\Core\WATERMARK_LOCATION_UPPER_RIGHT;
use const MyShowcase\ROOT;

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

        if (substr($callable, 0, $namespaceWithPrefixLength) == $namespaceLowercase . '\\') {
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

function hooksRun(string $hookName, array &$hookArguments = []): array
{
    global $plugins;

    return $plugins->run_hooks('myshowcase_system_' . $hookName, $hookArguments);
}

function urlHandler(string $newUrl = ''): string
{
    static $setUrl = URL;

    if (($newUrl = trim($newUrl))) {
        $setUrl = $newUrl;
    }

    return $setUrl;
}

function urlHandlerSet(string $newUrl): bool
{
    urlHandler($newUrl);

    return true;
}

function urlHandlerGet(): string
{
    return urlHandler();
}

function urlHandlerBuild(array $urlAppend = [], string $separator = '&amp;', bool $encode = true): string
{
    global $PL;

    if (!is_object($PL)) {
        $PL or require_once PLUGINLIBRARY;
    }

    if ($urlAppend && !is_array($urlAppend)) {
        $urlAppend = explode('=', $urlAppend);
        $urlAppend = [$urlAppend[0] => $urlAppend[1]];
    }

    return $PL->url_append(urlHandlerGet(), $urlAppend, $separator, $encode);
}

function getSetting(string $settingKey = '')
{
    global $mybb;

    return SETTINGS[$settingKey] ?? (
        $mybb->settings['myshowcase_' . $settingKey] ?? false
    );
}

function getTemplateName(string $templateName = '', string $showcasePrefix = '', bool $addPrefix = true): string
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

function getTemplate(string $templateName = '', bool $enableHTMLComments = true, string $showcasePrefix = ''): string
{
    global $templates;

    if (DEBUG) {
        //$templates->get(getTemplateName($templateName));

        //$templates->get(getTemplateName($templateName, $showcasePrefix));
    }

    if (DEBUG && file_exists($filePath = ROOT . "/Templates/{$templateName}.html")) {
        $templateContents = file_get_contents($filePath);

        $templates->cache[getTemplateName($templateName)] = $templateContents;
    } elseif (my_strpos($templateName, '/') !== false) {
        $templateName = substr($templateName, my_strpos($templateName, '/') + 1);
    }

    if ($showcasePrefix !== '' && isset($templates->cache[getTemplateName($templateName, $showcasePrefix)])) {
        return $templates->render(getTemplateName($templateName, $showcasePrefix), true, $enableHTMLComments);
    } elseif ($showcasePrefix) {
        return getTemplate($templateName, $enableHTMLComments);
    }

    return $templates->render(getTemplateName($templateName, $showcasePrefix), true, $enableHTMLComments);
}

function getTemplateTwig(
    string $templateName = '',
    bool $enableHTMLComments = true,
    string $showcasePrefix = ''
): string {
    if (DEBUG && file_exists($filePath = ROOT . "/Templates/{$templateName}.html")) {
        $templateContents = file_get_contents($filePath);
    }

    return $templateContents ?? '';
}

function templateGetCachedName(string $templateName = '', string $showcasePrefix = '', bool $addPrefix = false): string
{
    global $templates;

    if (DEBUG) {
        //$templates->get(getTemplateName($templateName));

        //$templates->get(getTemplateName($templateName, $showcasePrefix));
    }

    if (DEBUG && file_exists($filePath = ROOT . "/Templates/{$templateName}.html")) {
        $templateContents = file_get_contents($filePath);

        $templates->cache[getTemplateName($templateName)] = $templateContents;
    } elseif (my_strpos($templateName, '/') !== false) {
        $templateName = substr($templateName, my_strpos($templateName, '/') + 1);
    }

    if ($showcasePrefix !== '' && isset($templates->cache[getTemplateName($templateName, $showcasePrefix)])) {
        return getTemplateName($templateName, $showcasePrefix, $addPrefix);
    } elseif ($showcasePrefix) {
        return templateGetCachedName($templateName, addPrefix: $addPrefix);
    }

    return getTemplateName($templateName, $showcasePrefix, $addPrefix);
}

//set default permissions for all groups in all myshowcases
//if you edit or reorder these, you need to also edit
//the summary.php file (starting line 156) so the fields match this order
function showcaseDefaultPermissions(): array
{
    return [
        UserPermissions::CanCreateEntries => false,
        UserPermissions::CanUpdateEntries => false,
        UserPermissions::CanUploadAttachments => false,
        UserPermissions::CanView => true,
        UserPermissions::CanViewComments => true,
        UserPermissions::CanViewAttachments => true,
        UserPermissions::CanCreateComments => false,
        UserPermissions::CanDeleteComments => false,
        //UserPermissions::CanDeleteAuthorComments => false,
        UserPermissions::CanSearch => true,
        UserPermissions::CanWaterMarkAttachments => false,
        UserPermissions::AttachmentsFilesLimit => 0
    ];
}

function showcaseDefaultModeratorPermissions(): array
{
    return [
        ModeratorPermissions::CanManageEntries => false,
        ModeratorPermissions::CanManageEntries => false,
        ModeratorPermissions::CanManageEntries => false,
        ModeratorPermissions::CanManageComments => false
    ];
}

function getTemplatesList(): array
{
    $templatesDirIterator = new DirectoryIterator(ROOT . '/Templates');

    $templatesList = [];

    foreach ($templatesDirIterator as $template) {
        if (!$template->isFile()) {
            continue;
        }

        $pathName = $template->getPathname();

        $pathInfo = pathinfo($pathName);

        if ($pathInfo['extension'] === 'html') {
            $templatesList[$pathInfo['filename']] = file_get_contents($pathName);
        }
    }

    return $templatesList;
}

/**
 * Update the cache.
 *
 * @param string The cache item.
 * @param bool Clear the cache item.
 */
function cacheUpdate(string $cacheKey): array
{
    global $db, $cache;

    $cacheData = [];

    $tableFields = TABLES_DATA;

    $hookArguments = [
        'cacheKey' => $cacheKey,
        'cacheData' => &$cacheData,
        'tableFields' => &$tableFields,
    ];

    $hookArguments = hooksRun('cache_update_start', $hookArguments);

    switch ($cacheKey) {
        case CACHE_TYPE_CONFIG:
            $showcaseObjects = showcaseGet(
                queryFields: array_keys($tableFields['myshowcase_config']),
                queryOptions: ['order_by' => 'display_order']
            );

            foreach ($showcaseObjects as $showcaseID => $showcaseData) {
                $cacheData[$showcaseID] = [];

                foreach ($tableFields['myshowcase_config'] as $fieldName => $fieldDefinition) {
                    if (isset($showcaseData[$fieldName])) {
                        $cacheData[$showcaseID][$fieldName] = castTableFieldValue(
                            $showcaseData[$fieldName],
                            $fieldDefinition['type']
                        );
                    }
                }
            }

            break;
        case CACHE_TYPE_PERMISSIONS:
            $permissionsObjects = permissionsGet(
                [],
                array_keys($tableFields['myshowcase_permissions'])
            );

            foreach ($permissionsObjects as $permissionID => $permissionData) {
                $showcaseID = (int)$permissionData['showcase_id'];

                $groupID = (int)$permissionData['group_id'];

                $cacheData[$showcaseID][$groupID] = [];

                foreach ($tableFields['myshowcase_permissions'] as $fieldName => $fieldDefinition) {
                    if (isset($permissionData[$fieldName])) {
                        $cacheData[$showcaseID][$groupID][$fieldName] = castTableFieldValue(
                            $permissionData[$fieldName],
                            $fieldDefinition['type']
                        );
                    }
                }
            }

            break;
        case CACHE_TYPE_FIELD_SETS:
            $fieldsetObjects = fieldsetGet(
                [],
                ['set_id', 'set_name']
            );

            foreach ($fieldsetObjects as $fieldsetID => $fieldsetData) {
                $cacheData[$fieldsetID] = [
                    'set_id' => (int)$fieldsetData['set_id'],
                    'set_name' => (string)$fieldsetData['set_name'],
                ];
            }

            break;
        case CACHE_TYPE_FIELDS:
            $queryFields = $tableFields['myshowcase_fields'];

            unset($queryFields['unique_keys']);

            $fieldObjects = fieldsGet(
                [],
                array_keys($queryFields),
                ['order_by' => 'display_order']
            );

            foreach ($fieldObjects as $fieldID => $fieldData) {
                foreach ($tableFields['myshowcase_fields'] as $fieldName => $fieldDefinition) {
                    if (isset($fieldData[$fieldName])) {
                        $cacheData[(int)$fieldData['set_id']][$fieldID][$fieldName] = castTableFieldValue(
                            $fieldData[$fieldName],
                            $fieldDefinition['type']
                        );
                    }
                }
            }

            break;
        case CACHE_TYPE_FIELD_DATA;
            $fieldDataObjects = fieldDataGet(
                [],
                ['set_id', 'field_id', 'value_id', 'value', 'value_id', 'display_order'],
                ['order_by' => 'display_order']
            );

            foreach ($fieldDataObjects as $fieldDataID => $fieldData) {
                $cacheData[(int)$fieldData['set_id']][$fieldDataID][(int)$fieldData['value_id']] = $fieldData;
            }

            break;
        case CACHE_TYPE_MODERATORS;
            $moderatorObjects = moderatorGet(
                [],
                [
                    'moderator_id',
                    'showcase_id',
                    'user_id',
                    'is_group',
                    ModeratorPermissions::CanManageEntries,
                    ModeratorPermissions::CanManageEntries,
                    ModeratorPermissions::CanManageEntries,
                    ModeratorPermissions::CanManageComments
                ]
            );

            foreach ($moderatorObjects as $moderatorID => $moderatorData) {
                $cacheData[(int)$moderatorData['showcase_id']][$moderatorID] = $moderatorData;
            }

            break;
        case CACHE_TYPE_ATTACHMENT_TYPES;
            $query = $db->simple_select(
                'attachtypes',
                'atid AS attachment_type_id, name AS type_name, mimetype AS mime_type, extension AS file_extension, maxsize AS maximum_size, icon AS type_icon, forcedownload AS force_download, groups AS allowed_groups, myshowcase_ids, myshowcase_image_minimum_dimensions, myshowcase_image_maximum_dimensions',
                "myshowcase_ids!=''"
            );

            while ($attachmentTypeData = $db->fetch_array($query)) {
                $attachmentTypeID = (int)$attachmentTypeData['attachment_type_id'];

                foreach (explode(',', $attachmentTypeData['myshowcase_ids']) as $showcaseID) {
                    $showcaseID = (int)$showcaseID;

                    list($minimumWith, $minimumHeight) = array_pad(
                        array_map(
                            'intval',
                            explode('x', $attachmentTypeData['myshowcase_image_minimum_dimensions'] ?? '')
                        ),
                        2,
                        0
                    );

                    if ($minimumWith < 1 || $minimumHeight < 1) {
                        $minimumWith = $minimumHeight = 0;
                    }

                    list($maximumWidth, $maximumHeight) = array_pad(
                        array_map(
                            'intval',
                            explode('x', $attachmentTypeData['myshowcase_image_maximum_dimensions'] ?? '')
                        ),
                        2,
                        0
                    );

                    if ($maximumWidth < 1 || $maximumHeight < 1) {
                        $maximumWidth = $maximumHeight = 0;
                    }

                    $cacheData[$showcaseID][$attachmentTypeID] = [
                        'type_name' => $attachmentTypeData['type_name'],
                        'mime_type' => my_strtolower($attachmentTypeData['mime_type']),
                        'file_extension' => my_strtolower($attachmentTypeData['file_extension']),
                        'maximum_size' => (int)$attachmentTypeData['maximum_size'],
                        'type_icon' => $attachmentTypeData['type_icon'],
                        'force_download' => (int)$attachmentTypeData['force_download'],
                        'allowed_groups' => $attachmentTypeData['allowed_groups'],
                        'image_minimum_dimensions_width' => $minimumWith,
                        'image_minimum_dimensions_height' => $minimumHeight,
                        'image_maximum_dimensions_width' => $maximumWidth,
                        'image_maximum_dimensions_height' => $maximumHeight,
                    ];
                }
            }

            break;
    }

    $hookArguments = hooksRun('cache_update_end', $hookArguments);

    $cache->update("myshowcase_{$cacheKey}", $cacheData);

    return $cacheData;
}

function cacheGet(string $cacheKey, bool $forceReload = false): array
{
    global $cache;

    $cacheData = $cache->read("myshowcase_{$cacheKey}");

    if (!is_array($cacheData) && $forceReload || DEBUG) {
        $cacheData = cacheUpdate($cacheKey);
    }

    return $cacheData ?? [];
}

function showcaseInsert(array $showcaseData, bool $isUpdate = false, int $showcaseID = 0): int
{
    global $db;

    $tableFields = TABLES_DATA['myshowcase_config'];

    $insertData = [];

    $hookArguments = [
        'insertData' => &$insertData,
        'showcaseData' => &$showcaseData,
        'isUpdate' => $isUpdate,
        'showcaseID' => &$showcaseID,
        'tableFields' => &$tableFields,
    ];

    foreach ($tableFields as $fieldName => $fieldDefinition) {
        if (isset($showcaseData[$fieldName])) {
            $insertData[$fieldName] = sanitizeTableFieldValue($showcaseData[$fieldName], $fieldDefinition['type']);
        }
    }

    $hookArguments = hooksRun('showcase_insert_update_end', $hookArguments);

    if ($isUpdate) {
        $db->update_query('myshowcase_config', $insertData, "showcase_id='{$showcaseID}'");
    } else {
        $showcaseID = (int)$db->insert_query('myshowcase_config', $showcaseData);
    }

    return $showcaseID;
}

function showcaseUpdate(array $showcaseData, int $showcaseID): int
{
    return showcaseInsert($showcaseData, true, $showcaseID);
}

function showcaseDelete(int $showcaseID): bool
{
    global $db;

    $hookArguments = [
        'showcaseID' => &$showcaseID,
    ];

    $hookArguments = hooksRun('showcase_delete_start', $hookArguments);

    foreach (permissionsGet(["showcase_id='{$showcaseID}'"]) as $permissionID => $permissionData) {
        permissionsDelete($permissionID);
    }

    foreach (moderatorGet(["showcase_id='{$showcaseID}'"]) as $moderatorID => $moderatorData) {
        moderatorsDelete($moderatorID);
    }

    foreach (commentsGet(["showcase_id='{$showcaseID}'"]) as $commentID => $commentData) {
        commentsDelete($commentID);
    }

    foreach (attachmentGet(["showcase_id='{$showcaseID}'"]) as $attachmentID => $attachmentData) {
        attachmentDelete($attachmentID);
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

    try {
        $query = $db->simple_select(
            'myshowcase_config',
            implode(',', array_merge(['showcase_id'], $queryFields)),
            implode(' AND ', $whereClauses),
            $queryOptions
        );
    } catch (Exception $e) {
        return [];
    }

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
    global $db;

    $hookArguments = [
        'showcaseID' => &$showcaseID,
        'fieldName' => &$fieldName,
    ];

    $hookArguments = hooksRun('showcase_data_table_field_drop_start', $hookArguments);

    foreach (entryGet($showcaseID) as $entryID => $entryData) {
        entryDelete($showcaseID, $entryID);
    }

    $db->drop_column('myshowcase_data' . $showcaseID, $fieldName);

    return true;
}

function entryInsert(int $showcaseID, array $entryData, bool $isUpdate = false, int $entryID = 0): int
{
    global $db;

    $tableFields = DATA_TABLE_STRUCTURE['myshowcase_data'];

    $insertData = [];

    $hookArguments = [
        'insertData' => &$insertData,
        'entryData' => &$entryData,
        'isUpdate' => $isUpdate,
        'showcaseID' => &$showcaseID,
        'entryID' => &$entryID,
        'tableFields' => &$tableFields,
    ];

    foreach ($tableFields as $fieldName => $fieldDefinition) {
        if (isset($entryData[$fieldName])) {
            $insertData[$fieldName] = sanitizeTableFieldValue($entryData[$fieldName], $fieldDefinition['type']);
        }
    }

    $fieldSetID = (int)(showcaseGet(
        ["showcase_id='{$showcaseID}'"],
        ['field_set_id'],
        ['limit' => 1]
    )['field_set_id'] ?? 0);

    $fieldObjects = fieldsGet(
        ["set_id='{$fieldSetID}'", "enabled='1'"],
        [
            'field_id',
            'field_key',
            'field_type'
        ]
    );

    $hookArguments['showcaseFieldSetID'] = &$fieldSetID;

    $hookArguments['fieldObjects'] = &$fieldObjects;

    foreach ($fieldObjects as $fieldID => $fieldData) {
        if (isset($entryData[$fieldData['field_key']])) {
            if (fieldTypeMatchInt($fieldData['field_type'])) {
                $insertData[$fieldData['field_key']] = (int)$entryData[$fieldData['field_key']];
            } elseif (fieldTypeMatchFloat($fieldData['field_type'])) {
                $insertData[$fieldData['field_key']] = (float)$entryData[$fieldData['field_key']];
            } elseif (fieldTypeMatchChar($fieldData['field_type']) ||
                fieldTypeMatchText($fieldData['field_type']) ||
                fieldTypeMatchDateTime($fieldData['field_type'])) {
                $insertData[$fieldData['field_key']] = $db->escape_string($entryData[$fieldData['field_key']]);
            } elseif (fieldTypeMatchBinary($fieldData['field_type'])) {
                $insertData[$fieldData['field_key']] = $db->escape_binary($entryData[$fieldData['field_key']]);
            }
        }
    }

    $hookArguments = hooksRun('entry_insert_update_end', $hookArguments);

    if ($isUpdate) {
        $db->update_query('myshowcase_data' . $showcaseID, $insertData, "entry_id='{$entryID}'");
    } else {
        $entryID = $db->insert_query('myshowcase_data' . $showcaseID, $insertData);
    }

    return $entryID;
}

function entryUpdate(int $showcaseID, array $entryData, int $entryID): int
{
    return entryInsert($showcaseID, $entryData, true, $entryID);
}

function entryGet(
    int $showcaseID,
    array $whereClauses = [],
    array $queryFields = [],
    array $queryOptions = [],
    array $queryTables = []
): array {
    global $db;

    $queryTables = array_merge(["myshowcase_data{$showcaseID} entryData"], $queryTables);

    $query = $db->simple_select(
        implode(" LEFT JOIN {$db->table_prefix}", $queryTables),
        implode(',', array_merge(['entry_id'], $queryFields)),
        implode(' AND ', $whereClauses),
        $queryOptions
    );

    if (isset($queryOptions['limit']) && $queryOptions['limit'] === 1) {
        return (array)$db->fetch_array($query);
    }

    $entriesObjects = [];

    while ($fieldValueData = $db->fetch_array($query)) {
        $entriesObjects[(int)$fieldValueData['entry_id']] = $fieldValueData;
    }

    return $entriesObjects;
}

function permissionsInsert(int $showcaseID, array $permissionData, bool $isUpdate = false, int $permissionID = 0): int
{
    $tableFields = TABLES_DATA['myshowcase_permissions'];

    $hookArguments = [
        'insertData' => &$insertData,
        'permissionData' => &$permissionData,
        'isUpdate' => $isUpdate,
        'showcaseID' => &$showcaseID,
        'permissionID' => &$permissionID,
        'tableFields' => &$tableFields,
    ];

    $insertData = [];

    foreach ($tableFields as $fieldName => $fieldDefinition) {
        if (isset($permissionData[$fieldName])) {
            $insertData[$fieldName] = sanitizeTableFieldValue($permissionData[$fieldName], $fieldDefinition['type']);
        }
    }

    global $db;

    $hookArguments = hooksRun('permissions_insert_update_end', $hookArguments);

    if ($isUpdate) {
        $db->update_query('myshowcase_permissions', $insertData, "permission_id='{$permissionID}'");
    } else {
        $permissionID = (int)$db->insert_query('myshowcase_permissions', $insertData);
    }

    return $permissionID;
}

function permissionsUpdate(int $showcaseID, array $permissionData, int $permissionID): int
{
    return permissionsInsert($showcaseID, $permissionData, true, $permissionID);
}

function permissionsDelete(int $permissionID): bool
{
    global $db;

    $hookArguments = [
        'permissionID' => &$permissionID,
    ];

    $hookArguments = hooksRun('permissions_delete_start', $hookArguments);

    $db->delete_query('myshowcase_permissions', "permission_id='{$permissionID}'");

    return true;
}

function permissionsGet(array $whereClauses = [], array $queryFields = [], array $queryOptions = []): array
{
    global $db;

    $query = $db->simple_select(
        'myshowcase_permissions',
        implode(',', array_merge(['permission_id'], $queryFields)),
        implode(' AND ', $whereClauses),
        $queryOptions
    );

    if (isset($queryOptions['limit']) && $queryOptions['limit'] === 1) {
        return (array)$db->fetch_array($query);
    }

    $permissionData = [];

    while ($permission = $db->fetch_array($query)) {
        $permissionData[(int)$permission['permission_id']] = $permission;
    }

    return $permissionData;
}

function moderatorsInsert(int $showcaseID, array $moderatorData, bool $isUpdate = false, int $moderatorID = 0): int
{
    global $db;

    $tableFields = TABLES_DATA['myshowcase_moderators'];

    $insertData = [];

    $hookArguments = [
        'insertData' => &$insertData,
        'moderatorData' => &$moderatorData,
        'isUpdate' => $isUpdate,
        'showcaseID' => &$showcaseID,
        'moderatorID' => &$moderatorID,
        'tableFields' => &$tableFields,
    ];

    foreach ($tableFields as $fieldName => $fieldDefinition) {
        if (isset($moderatorData[$fieldName])) {
            $insertData[$fieldName] = sanitizeTableFieldValue($moderatorData[$fieldName], $fieldDefinition['type']);
        }
    }

    $hookArguments = hooksRun('moderator_insert_update_end', $hookArguments);

    if ($isUpdate) {
        $db->update_query('myshowcase_moderators', $insertData, "moderator_id='{$moderatorID}'");
    } else {
        $moderatorID = (int)$db->insert_query('myshowcase_moderators', $insertData);
    }

    return $moderatorID;
}

function moderatorsUpdate(int $showcaseID, array $moderatorData, int $moderatorID): int
{
    return moderatorsInsert($showcaseID, $moderatorData, true, $moderatorID);
}

function moderatorGet(array $whereClauses = [], array $queryFields = [], array $queryOptions = []): array
{
    global $db;

    $query = $db->simple_select(
        'myshowcase_moderators',
        implode(',', array_merge(['moderator_id'], $queryFields)),
        implode(' AND ', $whereClauses),
        $queryOptions
    );

    if (isset($queryOptions['limit']) && $queryOptions['limit'] === 1) {
        return (array)$db->fetch_array($query);
    }

    $moderatorData = [];

    while ($moderator = $db->fetch_array($query)) {
        $moderatorData[(int)$moderator['moderator_id']] = $moderator;
    }

    return $moderatorData;
}

function moderatorsDelete(int $moderatorID): bool
{
    global $db;

    $hookArguments = [
        'moderatorID' => &$moderatorID,
    ];

    $hookArguments = hooksRun('moderator_delete_start', $hookArguments);

    $db->delete_query('myshowcase_moderators', "moderator_id='{$moderatorID}'");

    return true;
}

function fieldsetInsert(array $fieldsetData, bool $isUpdate = false, $fieldsetID = 0): int
{
    global $db;

    $tableFields = TABLES_DATA['myshowcase_fieldsets'];

    $insertData = [];

    $hookArguments = [
        'insertData' => &$insertData,
        'fieldsetData' => &$fieldsetData,
        'isUpdate' => $isUpdate,
        'fieldsetID' => &$fieldsetID,
        'tableFields' => &$tableFields,
    ];

    foreach ($tableFields as $fieldName => $fieldDefinition) {
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

    $tableFields = TABLES_DATA['myshowcase_fields'];

    $insertData = [];

    $hookArguments = [
        'insertData' => &$insertData,
        'fieldData' => &$fieldData,
        'isUpdate' => $isUpdate,
        'fieldID' => &$fieldID,
        'tableFields' => &$tableFields,
    ];

    foreach ($tableFields as $fieldName => $fieldDefinition) {
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

function fieldsUpdate(array $fieldData, int $fieldID): int
{
    return fieldsInsert($fieldData, true, $fieldID);
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

    $fieldData = [];

    while ($field = $db->fetch_array($query)) {
        $fieldData[(int)$field['field_id']] = $field;
    }

    return $fieldData;
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

    $tableFields = TABLES_DATA['myshowcase_fields'];

    $insertData = [];

    $hookArguments = [
        'insertData' => &$insertData,
        'fieldData' => &$fieldData,
        'isUpdate' => $isUpdate,
        'fieldID' => &$fieldID,
        'tableFields' => &$tableFields,
    ];

    foreach ($tableFields as $fieldName => $fieldDefinition) {
        if (isset($fieldData[$fieldName])) {
            $insertData[$fieldName] = sanitizeTableFieldValue($fieldData[$fieldName], $fieldDefinition['type']);
        }
    }

    $hookArguments = hooksRun('field_data_insert_update_end', $hookArguments);

    if ($isUpdate) {
        $db->update_query('myshowcase_field_data', $fieldData, "field_data_id='{$fieldDataID}'");
    } else {
        $db->insert_query('myshowcase_field_data', $fieldData);

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

    $fieldData = [];

    while ($field = $db->fetch_array($query)) {
        $fieldData[(int)$field['field_data_id']] = $field;
    }

    return $fieldData;
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

function entryDelete(int $showcaseID, int $entryID): bool
{
    global $db;

    $hookArguments = [
        'entryID' => &$entryID,
        'showcaseID' => &$showcaseID,
    ];

    $hookArguments = hooksRun('entry_delete_start', $hookArguments);

    foreach (commentsGet(["entry_id='{$entryID}'"]) as $commentID => $commentData) {
        commentsDelete($commentID);
    }

    foreach (attachmentGet(["entry_id='{$entryID}'"]) as $attachmentID => $attachmentData) {
        attachmentDelete($attachmentID);
    }

    $db->delete_query('myshowcase_data' . $showcaseID, "entry_id='{$entryID}'");

    return true;
}

function attachmentInsert(array $attachmentData, bool $isUpdate = false, int $attachmentID = 0): int
{
    global $db;

    $tableFields = TABLES_DATA['myshowcase_attachments'];

    $insertData = [];

    $hookArguments = [
        'insertData' => &$insertData,
        'attachmentData' => &$attachmentData,
        'isUpdate' => $isUpdate,
        'attachmentID' => &$attachmentID,
        'tableFields' => &$tableFields,
    ];

    foreach ($tableFields as $fieldName => $fieldDefinition) {
        if (isset($attachmentData[$fieldName])) {
            $insertData[$fieldName] = sanitizeTableFieldValue($attachmentData[$fieldName], $fieldDefinition['type']);
        }
    }

    $hookArguments = hooksRun('attachment_insert_update_end', $hookArguments);

    if ($isUpdate) {
        $db->update_query('myshowcase_attachments', $insertData, "attachment_id='{$attachmentID}'");
    } else {
        $attachmentID = (int)$db->insert_query('myshowcase_attachments', $insertData);
    }

    return $attachmentID;
}

function attachmentUpdate(array $attachmentData, int $attachmentID): int
{
    return attachmentInsert($attachmentData, true, $attachmentID);
}

function attachmentGet(array $whereClauses = [], array $queryFields = [], array $queryOptions = []): array
{
    global $db;

    $query = $db->simple_select(
        'myshowcase_attachments',
        implode(',', array_merge(['attachment_id'], $queryFields)),
        implode(' AND ', $whereClauses),
        $queryOptions
    );

    if (isset($queryOptions['limit']) && $queryOptions['limit'] === 1) {
        return (array)$db->fetch_array($query);
    }

    $attachmentObjects = [];

    while ($attachment = $db->fetch_array($query)) {
        $attachmentObjects[(int)$attachment['attachment_id']] = $attachment;
    }

    return $attachmentObjects;
}

function attachmentDelete(int $attachmentID): bool
{
    global $db;

    $hookArguments = [
        'attachmentID' => &$attachmentID,
    ];

    $hookArguments = hooksRun('attachment_delete_start', $hookArguments);

    $db->delete_query('myshowcase_attachments', "attachment_id='{$attachmentID}'");

    return true;
}

function commentInsert(array $commentData, bool $isUpdate = false, int $commentID = 0): int
{
    global $db;

    $tableFields = TABLES_DATA['myshowcase_comments'];

    $insertData = [];

    $hookArguments = [
        'insertData' => &$insertData,
        'commentData' => &$commentData,
        'isUpdate' => $isUpdate,
        'commentID' => &$commentID,
        'tableFields' => &$tableFields,
    ];

    foreach ($tableFields as $fieldName => $fieldDefinition) {
        if (isset($commentData[$fieldName])) {
            $insertData[$fieldName] = sanitizeTableFieldValue($commentData[$fieldName], $fieldDefinition['type']);
        }
    }

    $hookArguments = hooksRun('comment_insert_update_end', $hookArguments);

    if ($isUpdate) {
        $db->update_query('myshowcase_comments', $insertData, "comment_id='{$commentID}'");
    } else {
        $commentID = (int)$db->insert_query('myshowcase_comments', $insertData);
    }

    return $commentID;
}

function commentUpdate(array $commentData, int $commentID = 0): int
{
    return commentInsert($commentData, true, $commentID);
}

function commentsGet(array $whereClauses = [], array $queryFields = [], array $queryOptions = []): array
{
    global $db;

    $query = $db->simple_select(
        'myshowcase_comments',
        implode(', ', array_merge(['comment_id'], $queryFields)),
        implode(' AND ', $whereClauses),
        $queryOptions
    );

    if (isset($queryOptions['limit']) && $queryOptions['limit'] === 1) {
        return (array)$db->fetch_array($query);
    }

    $commentObjects = [];

    while ($comment = $db->fetch_array($query)) {
        $commentObjects[(int)$comment['comment_id']] = $comment;
    }

    return $commentObjects;
}

function commentsDelete(int $commentID): bool
{
    global $db;

    $hookArguments = [
        'commentID' => &$commentID,
    ];

    $hookArguments = hooksRun('comment_delete_start', $hookArguments);

    $db->delete_query('myshowcase_comments', "comment_id='{$commentID}'");

    return true;
}

/**
 * Remove an attachment from a specific showcase
 *
 * @param Showcase $showcase The showcase ID
 * @param string $entryHash The entry_hash if available
 * @param int $attachmentID The attachment ID
 * @param int $entryID
 * @return bool
 */
function attachmentRemove(
    Showcase $showcase,
    string $entryHash = '',
    int $attachmentID = 0,
    int $entryID = 0
): bool {
    $whereClauses = ["showcase_id='{$showcase->showcase_id}'", "attachment_id='{$attachmentID}'"];

    if (!empty($entryHash)) {
        global $db;

        $whereClauses[] = "entry_hash='{$db->escape_string($entryHash)}'";
    } else {
        $whereClauses[] = "entry_id='{$entryID}'";
    }

    $attachmentData = attachmentGet($whereClauses, ['attachment_name', 'thumbnail_name', 'status'], ['limit' => 1]);

    $attachmentData = hooksRun('remove_attachment_do_delete', $attachmentData);

    attachmentDelete($attachmentID);

    unlink($showcase->config['attachments_uploads_path'] . '/' . $attachmentData['attachment_name']);

    if (!empty($attachmentData['thumbnail_name'])) {
        unlink($showcase->config['attachments_uploads_path'] . '/' . $attachmentData['thumbnail_name']);
    }

    $dateDirectory = explode('/', $attachmentData['attachment_name']);

    if (!empty($dateDirectory[0]) && is_dir($showcase->config['attachments_uploads_path'] . '/' . $dateDirectory[0])) {
        rmdir($showcase->config['attachments_uploads_path'] . '/' . $dateDirectory[0]);
    }

    return true;
}

/**
 * Upload an attachment in to the file system
 *
 * @param Showcase $showcase Attachment data (as fed by PHPs $_FILE)
 * @param array $attachmentData
 * @param bool $isUpdate
 * @param bool $watermarkImage
 * @return array Array of attachment data if successful, otherwise array of error data
 */
function attachmentUpload(
    Showcase $showcase,
    array $attachmentData,
    bool $isUpdate = false,
    bool $watermarkImage = false
): array {
    global $db, $mybb, $lang, $cache;

    $returnData = [];

    if (isset($attachmentData['error']) && $attachmentData['error'] !== 0) {
        $returnData['error'] = $lang->myShowcaseAttachmentsUploadErrorUploadFailed . $lang->error_uploadfailed_detail;

        switch ($attachmentData['error']) {
            case 1: // UPLOAD_ERR_INI_SIZE
                $returnData['error'] .= $lang->error_uploadfailed_php1;
                break;
            case 2: // UPLOAD_ERR_FORM_SIZE
                $returnData['error'] .= $lang->error_uploadfailed_php2;
                break;
            case 3: // UPLOAD_ERR_PARTIAL
                $returnData['error'] .= $lang->error_uploadfailed_php3;
                break;
            case 4: // UPLOAD_ERR_NO_FILE
                $returnData['error'] .= $lang->error_uploadfailed_php4;
                break;
            case 6: // UPLOAD_ERR_NO_TMP_DIR
                $returnData['error'] .= $lang->error_uploadfailed_php6;
                break;
            case 7: // UPLOAD_ERR_CANT_WRITE
                $returnData['error'] .= $lang->error_uploadfailed_php7;
                break;
            default:
                $returnData['error'] .= $lang->sprintf($lang->error_uploadfailed_phpx, $attachmentData['error']);
                break;
        }

        return $returnData;
    }

    if (!is_uploaded_file($attachmentData['tmp_name']) || empty($attachmentData['tmp_name'])) {
        $returnData['error'] = $lang->myShowcaseAttachmentsUploadErrorUploadFailed . $lang->error_uploadfailed_php4;

        return $returnData;
    }

    $attachmentFileExtension = my_strtolower(get_extension($attachmentData['name']));

    $attachmentMimeType = my_strtolower($attachmentData['type']);

    $attachmentTypes = cacheGet(CACHE_TYPE_ATTACHMENT_TYPES)[$showcase->showcase_id] ?? [];

    $attachmentType = false;

    foreach ($attachmentTypes as $attachmentTypeID => $attachmentTypeData) {
        if ($attachmentTypeData['file_extension'] === $attachmentFileExtension &&
            $attachmentTypeData['mime_type'] === $attachmentMimeType) {
            $attachmentType = $attachmentTypeData;

            break;
        }
    }

    if ($attachmentType === false) {
        $returnData['error'] = $lang->error_attachtype;

        return $returnData;
    }

    if ($attachmentData['size'] > $attachmentType['maximum_size'] * 1024 && !empty($attachmentType['maximum_size'])) {
        $returnData['error'] = $lang->sprintf(
            $lang->error_attachsize,
            htmlspecialchars_uni($attachmentData['name']),
            $attachmentType['maximum_size']
        );

        return $returnData;
    }

    if ($showcase->userPermissions[UserPermissions::AttachmentsUploadQuote] > 0) {
        $totalUserUsage = attachmentGet(
            ["user_id='{$showcase->entryUserID}'"],
            ['SUM(file_size) AS total_user_usage'],
            ['group_by' => 'showcase_id']
        )['total_user_usage'] ?? 0;

        $totalUserUsage = $totalUserUsage + $attachmentData['size'];

        if ($totalUserUsage > ($showcase->userPermissions[UserPermissions::AttachmentsUploadQuote] * 1024)) {
            $returnData['error'] = $lang->sprintf(
                $lang->error_reachedattachquota,
                get_friendly_size($showcase->userPermissions[UserPermissions::AttachmentsUploadQuote] * 1024)
            );

            return $returnData;
        }
    }

    $existingAttachment = attachmentGet(
        [
            "file_name='{$db->escape_string($attachmentData['name'])}'",
            "showcase_id='{$showcase->showcase_id}'",
            "(entry_hash='{$db->escape_string($showcase->entryHash)}' OR (entry_id='{$showcase->entryID}' AND entry_id!='0'))"
        ],
        queryOptions: ['limit' => 1]
    );

    $existingAttachmentID = (int)($existingAttachment['attachment_id'] ?? 0);

    if ($existingAttachmentID && !$isUpdate) {
        $returnData['error'] = $lang->error_alreadyuploaded;

        return $returnData;
    }

    // Check if the attachment directory (YYYYMM) exists, if not, create it
    $directoryMonthName = gmdate('Ym');

    if (!is_dir($showcase->config['attachments_uploads_path'] . '/' . $directoryMonthName)) {
        mkdir($showcase->config['attachments_uploads_path'] . '/' . $directoryMonthName);

        if (!is_dir($showcase->config['attachments_uploads_path'] . '/' . $directoryMonthName)) {
            $directoryMonthName = '';
        }
    }

    // If safe_mode is enabled, don't attempt to use the monthly directories as it won't work
    if (ini_get('safe_mode') || my_strtolower(ini_get('safe_mode')) === 'on') {
        $directoryMonthName = '';
    }

    // All seems to be good, lets move the attachment!
    $timeNow = TIME_NOW;

    $attachmentHas = generateUUIDv4();

    $fileName = "attachment_{$showcase->entryUserID}_{$showcase->entryID}_{$timeNow}_{$attachmentHas}.attach";

    $fileDataResult = fileUpload(
        $attachmentData,
        $showcase->config['attachments_uploads_path'] . '/' . $directoryMonthName,
        $fileName
    );

    // Failed to create the attachment in the monthly directory, just throw it in the main directory
    if ($fileDataResult['error'] && $directoryMonthName) {
        $fileDataResult = fileUpload($attachmentData, $showcase->config['attachments_uploads_path'] . '/', $fileName);
    }

    if ($directoryMonthName) {
        $fileName = $directoryMonthName . '/' . $fileName;
    }

    if ($fileDataResult['error']) {
        $returnData['error'] = $lang->myShowcaseAttachmentsUploadErrorUploadFailed . $lang->error_uploadfailed_detail;

        switch ($fileDataResult['error']) {
            case UPLOAD_STATUS_INVALID:
                $returnData['error'] .= $lang->error_uploadfailed_nothingtomove;
                break;
            case UPLOAD_STATUS_FAILED:
                $returnData['error'] .= $lang->error_uploadfailed_movefailed;
                break;
        }

        return $returnData;
    }

    // Lets just double check that it exists
    if (!file_exists($showcase->config['attachments_uploads_path'] . '/' . $fileName)) {
        $returnData['error'] = $lang->myShowcaseAttachmentsUploadErrorUploadFailed . $lang->error_uploadfailed_detail . $lang->error_uploadfailed_lost;

        return $returnData;
    }

    $insertData = [
        'showcase_id' => $showcase->showcase_id,
        'entry_id' => $showcase->entryID,
        'attachment_hash' => generateUUIDv4(),
        'entry_hash' => $showcase->entryHash,
        //'post_hash' => $showcase->commentHash,
        'user_id' => $showcase->entryUserID,
        'file_name' => $fileName,
        'mime_type' => my_strtolower($fileDataResult['type']),
        'file_size' => $fileDataResult['size'],
        'attachment_name' => $fileDataResult['original_filename'],
        'dateline' => TIME_NOW,
        'status' => ATTACHMENT_STATUS_VISIBLE,
        /*'cdn_file' => 0,*/

    ];

    if ($isUpdate) {
        $insertData['edit_stamp'] = TIME_NOW;
    }

    if (!$isUpdate && (
            $showcase->config['moderate_attachments_upload'] ||
            $showcase->userPermissions[UserPermissions::ModerateAttachmentsUpload]
        )) {
        $insertData['status'] = ATTACHMENT_STATUS_PENDING_APPROVAL;
    } elseif ($isUpdate && (
            $showcase->config['moderate_attachments_update'] ||
            $showcase->userPermissions[UserPermissions::ModerateAttachmentsUpdate]
        )) {
        $insertData['status'] = ATTACHMENT_STATUS_PENDING_APPROVAL;
    }

    // If we're uploading an image, check the MIME type compared to the image type and attempt to generate a thumbnail
    if (in_array($attachmentFileExtension, ['gif', 'png', 'jpg', 'jpeg', 'jpe', 'webp'])) {
        $fullImageDimensions = getimagesize($showcase->config['attachments_uploads_path'] . '/' . $fileName);

        if (!is_array($fullImageDimensions)) {
            delete_uploaded_file($showcase->config['attachments_uploads_path'] . '/' . $fileName);

            $returnData['error'] = $lang->myShowcaseAttachmentsUploadErrorUploadFailed;

            return $returnData;
        }

        if (!empty($attachmentType['image_minimum_dimensions_width'])) {
            if ($fullImageDimensions[0] < $attachmentType['image_minimum_dimensions_width'] ||
                $fullImageDimensions[1] < $attachmentType['image_minimum_dimensions_height']) {
                delete_uploaded_file($showcase->config['attachments_uploads_path'] . '/' . $fileName);

                $returnData['error'] = $lang->sprintf(
                    $lang->myShowcaseAttachmentsUploadErrorMinimumDimensions,
                    htmlspecialchars_uni($insertData['attachment_name']),
                    $attachmentType['image_minimum_dimensions_width'],
                    $attachmentType['image_minimum_dimensions_height']
                );

                return $returnData;
            }
        }

        if (!empty($attachmentType['image_maximum_dimensions_width'])) {
            if ($fullImageDimensions[0] > $attachmentType['image_maximum_dimensions_width'] ||
                $fullImageDimensions[1] > $attachmentType['image_maximum_dimensions_height']) {
                delete_uploaded_file($showcase->config['attachments_uploads_path'] . '/' . $fileName);

                $returnData['error'] = $lang->sprintf(
                    $lang->myShowcaseAttachmentsUploadErrorMaximumDimensions,
                    htmlspecialchars_uni($insertData['attachment_name']),
                    $attachmentType['image_maximum_dimensions_width'],
                    $attachmentType['image_maximum_dimensions_height']
                );

                return $returnData;
            }
        }

        // Check a list of known MIME types to establish what kind of image we're uploading
        $imageType = match ($insertData['mime_type']) {
            'image/gif' => IMAGETYPE_GIF,
            'image/jpeg', 'image/x-jpg', 'image/x-jpeg', 'image/pjpeg', 'image/jpg' => IMAGETYPE_JPEG,
            'image/png', 'image/x-png' => IMAGETYPE_PNG,
            'image/bmp', 'image/x-bmp', 'image/x-windows-bmp' => IMAGETYPE_BMP,
            'image/webp' => IMAGETYPE_WEBP,
            default => 0,
        };

        // todo, https://github.com/JamesHeinrich/phpThumb
        // todo, https://github.com/jamiebicknell/Thumb

        if (function_exists('finfo_open')) {
            $file_info = finfo_open(FILEINFO_MIME);

            $attachmentMimeType = my_strtolower(
                explode(
                    ';',
                    finfo_file($file_info, $showcase->config['attachments_uploads_path'] . '/' . $fileName)
                )[0] ?? ''
            );

            finfo_close($file_info);
        } elseif (function_exists('mime_content_type')) {
            $attachmentMimeType = my_strtolower(
                mime_content_type(
                    MYBB_ROOT . $showcase->config['attachments_uploads_path'] . '/' . $fileName
                )
            );
        }

        $returnData['mime_type'] = $attachmentMimeType;

        // we check again just in case
        if ($attachmentType['mime_type'] !== $attachmentMimeType) {
            $returnData['error'] = $lang->error_attachtype;

            return $returnData;
        }

        if ($fullImageDimensions[2] !== $imageType || !$imageType) {
            delete_uploaded_file($showcase->config['attachments_uploads_path'] . '/' . $fileName);

            $returnData['error'] = $lang->myShowcaseAttachmentsUploadErrorInvalidType;

            return $returnData;
        }

        require_once MYBB_ROOT . 'inc/functions_image.php';

        if ($showcase->config['attachments_thumbnails_width'] > 0 &&
            $showcase->config['attachments_thumbnails_height'] > 0 &&
            in_array($imageType, [IMAGETYPE_GIF, IMAGETYPE_JPEG, IMAGETYPE_PNG])) {
            $thumbnailImage = generate_thumbnail(
                $showcase->config['attachments_uploads_path'] . '/' . $fileName,
                $showcase->config['attachments_uploads_path'],
                str_replace('.attach', "_thumb.{$attachmentFileExtension}", $fileName),
                $showcase->config['attachments_thumbnails_width'],
                $showcase->config['attachments_thumbnails_height']
            );

            // maybe should just ignore ?
            if ($thumbnailImage['code'] === ATTACHMENT_THUMBNAIL_ERROR) {
                delete_uploaded_file($showcase->config['attachments_uploads_path'] . '/' . $fileName);

                $returnData['error'] = $lang->myShowcaseAttachmentsUploadErrorUploadFailed;

                return $returnData;
            }

            // we only generate thumbnails for large images
            if ($thumbnailImage['code'] !== ATTACHMENT_IMAGE_TOO_SMALL_FOR_THUMBNAIL) {
                if (empty($thumbnailImage['filename'])) {
                    delete_uploaded_file($showcase->config['attachments_uploads_path'] . '/' . $fileName);

                    $returnData['error'] = $lang->myShowcaseAttachmentsUploadErrorThumbnailFailure;

                    return $returnData;
                }

                $insertData['thumbnail_name'] = $returnData['thumbnail_name'] = $thumbnailImage['filename'];

                $thumbnailImageDimensions = getimagesize(
                    $showcase->config['attachments_uploads_path'] . '/' . $thumbnailImage['filename']
                );

                if (!is_array($thumbnailImageDimensions)) {
                    delete_uploaded_file($showcase->config['attachments_uploads_path'] . '/' . $fileName);

                    $returnData['error'] = $lang->myShowcaseAttachmentsUploadErrorThumbnailFailure;

                    return $returnData;
                }

                $insertData['thumbnail_dimensions'] = $returnData['thumbnail_dimensions'] = "{$thumbnailImageDimensions[0]}x{$thumbnailImageDimensions[1]}";
            }

            if ($thumbnailImage['code'] === ATTACHMENT_IMAGE_TOO_SMALL_FOR_THUMBNAIL) {
                $insertData['thumbnail_dimensions'] = $returnData['thumbnail_dimensions'] = ATTACHMENT_THUMBNAIL_SMALL;
            }
        }

        $watermarkImagePath = MYBB_ROOT . $showcase->config['attachments_watermark_file'];

        //if requested and enabled, watermark the master image
        if ($showcase->userPermissions[UserPermissions::CanWaterMarkAttachments] &&
            $watermarkImage &&
            file_exists($watermarkImagePath)) {
            //get watermark image object
            switch (strtolower(get_extension($showcase->config['attachments_watermark_file']))) {
                case 'gif':
                    $watermarkImageObject = imagecreatefromgif($watermarkImagePath);
                    break;
                case 'jpg':
                case 'jpeg':
                case 'jpe':
                    $watermarkImageObject = imagecreatefromjpeg($watermarkImagePath);
                    break;
                case 'png':
                    $watermarkImageObject = imagecreatefrompng($watermarkImagePath);
                    break;
            }

            if (!empty($watermarkImageObject)) {
                //get watermark size
                $waterMarkImageWidth = imagesx($watermarkImageObject);

                $waterMarkImageHeight = imagesy($watermarkImageObject);

                //get size of base image
                $fullImageDetails = getimagesize($showcase->config['attachments_uploads_path'] . '/' . $fileName);

                //set watermark location
                switch ($showcase->config['attachments_watermark_location']) {
                    case WATERMARK_LOCATION_LOWER_LEFT:
                        $waterMarkPositionX = 5;

                        $waterMarkPositionY = $fullImageDetails[1] - $waterMarkImageHeight - 5;
                        break;
                    case WATERMARK_LOCATION_LOWER_RIGHT:
                        $waterMarkPositionX = $fullImageDetails[0] - $waterMarkImageWidth - 5;

                        $waterMarkPositionY = $fullImageDetails[1] - $waterMarkImageHeight - 5;
                        break;
                    case WATERMARK_LOCATION_CENTER:
                        $waterMarkPositionX = $fullImageDetails[0] / 2 - $waterMarkImageWidth / 2;

                        $waterMarkPositionY = $fullImageDetails[1] / 2 - $waterMarkImageHeight / 2;
                        break;
                    case WATERMARK_LOCATION_UPPER_LEFT:
                        $waterMarkPositionX = 5;

                        $waterMarkPositionY = 5;
                        break;
                    case WATERMARK_LOCATION_UPPER_RIGHT:
                        $waterMarkPositionX = $fullImageDetails[0] - $waterMarkImageWidth - 5;

                        $waterMarkPositionY = 5;
                        break;
                }

                //get base image object
                switch ($imageType) {
                    case IMAGETYPE_GIF:
                        $uploadedAttachmentFile = imagecreatefromgif(
                            $showcase->config['attachments_uploads_path'] . '/' . $fileName
                        );
                        break;
                    case IMAGETYPE_JPEG:
                        $uploadedAttachmentFile = imagecreatefromjpeg(
                            $showcase->config['attachments_uploads_path'] . '/' . $fileName
                        );
                        break;
                    case IMAGETYPE_PNG:
                        $uploadedAttachmentFile = imagecreatefrompng(
                            $showcase->config['attachments_uploads_path'] . '/' . $fileName
                        );
                        break;
                }

                if (!empty($uploadedAttachmentFile) && isset($waterMarkPositionX) && isset($waterMarkPositionY)) {
                    imagealphablending($uploadedAttachmentFile, true);

                    imagealphablending($watermarkImageObject, true);

                    imagecopy(
                        $uploadedAttachmentFile,
                        $watermarkImageObject,
                        $waterMarkPositionX,
                        $waterMarkPositionY,
                        0,
                        0,
                        min($waterMarkImageWidth, $fullImageDetails[0]),
                        min($waterMarkImageHeight, $fullImageDetails[1])
                    );

                    //remove watermark from memory
                    imagedestroy($watermarkImageObject);

                    //write modified file

                    $fileOpen = fopen($showcase->config['attachments_uploads_path'] . '/' . $fileName, 'w');

                    if ($fileOpen) {
                        ob_start();

                        switch ($imageType) {
                            case IMAGETYPE_GIF:
                                imagegif($uploadedAttachmentFile);
                                break;
                            case IMAGETYPE_JPEG:
                                imagejpeg($uploadedAttachmentFile);
                                break;
                            case IMAGETYPE_PNG:
                                imagepng($uploadedAttachmentFile);
                                break;
                        }

                        $content = ob_get_clean();

                        ob_end_clean();

                        fwrite($fileOpen, $content);

                        fclose($fileOpen);

                        imagedestroy($uploadedAttachmentFile);
                    }
                }
            }
        }

        $returnData['dimensions'] = $insertData['dimensions'] = "{$fullImageDimensions[0]}x{$fullImageDimensions[1]}";
    }

    $insertData = hooksRun('upload_attachment_do_insert', $insertData);

    if ($existingAttachmentID && $isUpdate) {
        attachmentUpdate($insertData, $existingAttachmentID);
    } else {
        $existingAttachmentID = attachmentInsert($insertData);
    }

    $returnData['attachment_id'] = $existingAttachmentID;

    return $returnData;
}

//todo review hooks here
/**
 * Actually move a file to the uploads directory
 *
 * @param array $fileData The PHP $_FILE array for the file
 * @param string $uploadsPath The path to save the file in
 * @param string $fileName The file_name for the file (if blank, current is used)
 */
function fileUpload(array $fileData, string $uploadsPath, string $fileName = ''): array
{
    $returnData = [];

    if (empty($fileData['name']) || $fileData['name'] === 'none' || $fileData['size'] < 1) {
        $returnData['error'] = UPLOAD_STATUS_INVALID;

        return $returnData;
    }

    if (!$fileName) {
        $fileName = $fileData['name'];
    }

    $returnData['original_filename'] = preg_replace('#/$#', '', $fileData['name']);

    $fileName = preg_replace('#/$#', '', $fileName);

    if (!move_uploaded_file($fileData['tmp_name'], $uploadsPath . '/' . $fileName)) {
        $returnData['error'] = UPLOAD_STATUS_FAILED;

        return $returnData;
    }

    my_chmod($uploadsPath . '/' . $fileName, '0644');

    $returnData['file_name'] = $fileName;

    $returnData['path'] = $uploadsPath;

    $returnData['type'] = $fileData['type'];

    $returnData['size'] = $fileData['size'];

    return hooksRun('upload_file_end', $returnData);
}

function entryGetRandom(): string
{
    global $db, $lang, $mybb, $cache, $templates;

    //get list of enabled myshowcases with random in portal turned on
    $showcase_list = [];

    $myshowcases = cacheGet(CACHE_TYPE_CONFIG);
    foreach ($myshowcases as $id => $myshowcase) {
        //$myshowcase['attachments_portal_build_widget'] == 1;
        if ($myshowcase['enabled'] == 1 && $myshowcase['attachments_portal_build_widget'] == 1) {
            $showcase_list[$id]['name'] = $myshowcase['name'];
            $showcase_list[$id]['script_name'] = $myshowcase['script_name'];
            $showcase_list[$id]['attachments_uploads_path'] = $myshowcase['attachments_uploads_path'];
            $showcase_list[$id]['field_set_id'] = $myshowcase['field_set_id'];
        }
    }

    //if no showcases set to show on portal return
    if (count($showcase_list) == 0) {
        return '';
    } else {
        //get a random showcase showcase_id of those enabled
        $rand_id = array_rand($showcase_list, 1);
        $rand_showcase = $showcase_list[$rand_id];

        /* URL Definitions */
        if ($mybb->settings['seourls'] == 'yes' || ($mybb->settings['seourls'] == 'auto' && $_SERVER['SEO_SUPPORT'] == 1)) {
            $showcase_file = strtolower($rand_showcase['name']) . '-view-{entry_id}.html';
        } else {
            $showcase_file = $rand_showcase['script_name'] . '?action=view&entry_id={entry_id}';
        }

        //init fixed fields
        $fields_fixed = [];
        $fields_fixed[0]['name'] = 'g.user_id';
        $fields_fixed[0]['type'] = 'default';
        $fields_fixed[1]['name'] = 'dateline';
        $fields_fixed[1]['type'] = 'default';

        //get dynamic field info for the random showcase
        $field_list = [];
        $fields = cacheGet(CACHE_TYPE_FIELD_SETS);

        //get subset specific to the showcase given assigned field set
        $fields = $fields[$rand_showcase['field_set_id']];

        //get fields that are enabled and set for list display with pad to help sorting fixed fields)
        $description_list = [];
        foreach ($fields as $id => $field) {
            if (/*(int)$field['render_order'] !== \MyShowcase\Plugin\Core\ALL_UNLIMITED_VALUE && */ $field['enabled'] == 1) {
                $field_list[$field['render_order'] + 10]['field_key'] = $field['field_key'];
                $field_list[$field['render_order'] + 10]['type'] = $field['html_type'];
                $description_list[$field['render_order']] = $field['field_key'];
            }
        }

        //merge dynamic and fixed fields
        $fields_for_search = array_merge($fields_fixed, $field_list);

        //build where clause based on search_field terms
        $addon_join = '';
        $addon_fields = '';
        foreach ($fields_for_search as $id => $field) {
            if ($field['type'] == FieldHtmlTypes::Select || $field['type'] == FieldHtmlTypes::Radio) {
                $addon_join .= ' LEFT JOIN ' . TABLE_PREFIX . 'myshowcase_field_data tbl_' . $field['field_key'] . ' ON (tbl_' . $field['field_key'] . '.value_id = g.' . $field['field_key'] . ' AND tbl_' . $field['field_key'] . ".field_id = '" . $field['field_id'] . "') ";
                $addon_fields .= ', tbl_' . $field['field_key'] . '.value AS ' . $field['field_key'];
            } else {
                $addon_fields .= ', ' . $field['field_key'];
            }
        }


        $rand_entry = 0;
        while ($rand_entry == 0) {
            $attachmentData = attachmentGet(
                ["showcase_id='{$rand_id}'", "mime_type LIKE 'image%'", "status='1'", "entry_id!='0'"],
                ['entry_id', 'attachment_name', 'thumbnail_name'],
                ['limit' => 1, 'order_by' => 'RAND()']
            );

            $rand_entry = $attachmentData['entry_id'];
            $rand_entry_img = $attachmentData['attachment_name'];
            $rand_entry_thumb = $attachmentData['thumbnail_name'];

            if ($rand_entry) {
                $query = $db->query(
                    '
					SELECT entry_id, username, g.views, comments' . $addon_fields . '
					FROM ' . TABLE_PREFIX . 'myshowcase_data' . $rand_id . ' g
					LEFT JOIN ' . TABLE_PREFIX . 'users u ON (u.uid = g.user_id)
					' . $addon_join . '
					WHERE approved = 1 AND entry_id=' . $rand_entry . '
					LIMIT 0, 1'
                );

                if ($db->num_rows($query) == 0) {
                    $rand_entry = 0;
                }
            }
        }

        if (!$rand_entry || !isset($query)) {
            return '';
        }

        $alternativeBackground = 'trow2';
        $entry = $db->fetch_array($query);

        $lasteditdate = my_date($mybb->settings['dateformat'], $entry['dateline']);
        $lastedittime = my_date($mybb->settings['timeformat'], $entry['dateline']);
        $item_lastedit = $lasteditdate . '<br>' . $lastedittime;

        $item_member = build_profile_link(
            $entry['username'],
            $entry['user_id'],
        );

        $item_view_user = str_replace('{username}', $entry['username'], $lang->myshowcase_view_user);

        $entryUrl = str_replace('{entry_id}', $entry['entry_id'], $showcase_file);

        $entry['description'] = '';
        foreach ($description_list as $order => $name) {
            $entry['description'] .= $entry[$name] . ' ';
        }

        $alternativeBackground = ($alternativeBackground == 'trow1' ? 'trow2' : 'trow1');

        if ((int)$rand_entry_thumb === ATTACHMENT_THUMBNAIL_SMALL) {
            $rand_img = $rand_showcase['attachments_uploads_path'] . '/' . $rand_entry_img;
        } else {
            $rand_img = $rand_showcase['attachments_uploads_path'] . '/' . $rand_entry_thumb;
        }

        return $myshowcase->templateGetTwig('portal_rand_showcase', [
            'showcaseName' => $rand_showcase['name'],
            'entryUrl' => $entryUrl,
            'entryDescription' => $entry['description'],
            'userName' => $entryUsername,
            'entryViews' => $entry['views'],
            'entryComments' => $entry['comments'],
            'entryImage' => $rand_img,
        ]);
    }
}

function dataTableStructureGet(int $showcaseID = 0): array
{
    require_once ROOT . '/System/FieldDefaultTypes.php';

    global $db;

    $dataTableStructure = DATA_TABLE_STRUCTURE['myshowcase_data'];

    if ($showcaseID &&
        ($showcaseData = showcaseGet(["showcase_id='{$showcaseID}'"], ['field_set_id'], ['limit' => 1]))) {
        $fieldsetID = (int)$showcaseData['field_set_id'];

        foreach (
            fieldsGet(
                ["set_id='{$fieldsetID}'"],
                ['field_key', 'field_type', 'maximum_length', 'is_required', 'default_value', 'default_type']
            ) as $fieldID => $fieldData
        ) {
            $dataTableStructure[$fieldData['field_key']] = [];

            if (fieldTypeMatchInt($fieldData['field_type'])) {
                $dataTableStructure[$fieldData['field_key']]['type'] = $fieldData['field_type'];

                $dataTableStructure[$fieldData['field_key']]['size'] = (int)$fieldData['maximum_length'];

                if ($fieldData['default_value'] !== '') {
                    $defaultValue = (int)$fieldData['default_value'];
                } else {
                    $defaultValue = 0;
                }
            } elseif (fieldTypeMatchFloat($fieldData['field_type'])) {
                $dataTableStructure[$fieldData['field_key']]['type'] = $fieldData['field_type'];

                $dataTableStructure[$fieldData['field_key']]['size'] = (float)$fieldData['maximum_length'];

                if ($fieldData['default_value'] !== '') {
                    $defaultValue = (float)$fieldData['default_value'];
                } else {
                    $defaultValue = 0;
                }
            } elseif (fieldTypeMatchChar($fieldData['field_type'])) {
                $dataTableStructure[$fieldData['field_key']]['type'] = $fieldData['field_type'];

                $dataTableStructure[$fieldData['field_key']]['size'] = (int)$fieldData['maximum_length'];

                if ($fieldData['default_value'] !== '') {
                    $defaultValue = $db->escape_string($fieldData['default_value']);
                } else {
                    $defaultValue = '';
                }
            } elseif (fieldTypeMatchText($fieldData['field_type'])) {
                $dataTableStructure[$fieldData['field_key']]['type'] = $fieldData['field_type'];

                // todo, TEXT fields cannot have default values, should validate in front end
                $fieldData['default_type'] = FieldDefaultTypes::IsNull;
            } elseif (fieldTypeMatchDateTime($fieldData['field_type'])) {
                $dataTableStructure[$fieldData['field_key']]['type'] = $fieldData['field_type'];

                if ($fieldData['default_value'] !== '') {
                    $defaultValue = $db->escape_string($fieldData['default_value']);
                } else {
                    $defaultValue = '';
                }
            } elseif (fieldTypeMatchBinary($fieldData['field_type'])) {
                $dataTableStructure[$fieldData['field_key']]['type'] = $fieldData['field_type'];

                $dataTableStructure[$fieldData['field_key']]['size'] = (int)$fieldData['maximum_length'];

                if ($fieldData['default_value'] !== '') {
                    $defaultValue = $db->escape_string($fieldData['default_value']);
                } else {
                    $defaultValue = '';
                }
            }

            switch ($fieldData['default_type']) {
                case FieldDefaultTypes::AsDefined:
                    if ($fieldData['default_value'] !== '' && isset($defaultValue)) {
                        $dataTableStructure[$fieldData['field_key']]['default'] = $defaultValue;
                    }
                    break;
                case FieldDefaultTypes::IsNull:
                    unset($dataTableStructure[$fieldData['field_key']]['default']);

                    $dataTableStructure[$fieldData['field_key']]['null'] = true;
                    break;
                case FieldDefaultTypes::CurrentTimestamp:
                    unset($dataTableStructure[$fieldData['field_key']]['default']);

                    $dataTableStructure[$fieldData['field_key']]['default'] = 'TIMESTAMP';
                    break;
                case FieldDefaultTypes::UUID:
                    unset($dataTableStructure[$fieldData['field_key']]['default']);

                    $dataTableStructure[$fieldData['field_key']]['default'] = 'UUID';
                    break;
            }

            if (!empty($fieldData['is_required'])) {
                global $mybb;

                if (fieldTypeSupportsFullText($fieldData['field_type']) &&
                    $mybb->settings['searchtype'] === 'fulltext') {
                    isset($dataTableStructure['full_keys']) || $dataTableStructure['full_keys'] = [];

                    $dataTableStructure['full_keys'][$fieldData['field_key']] = $fieldData['field_key'];
                } else {
                    isset($dataTableStructure['keys']) || $dataTableStructure['keys'] = [];

                    $dataTableStructure['keys'][$fieldData['field_key']] = $fieldData['field_key'];
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

function showcaseGetObject(int $selectedShowcaseID): ?Showcase
{
    $showcaseObjects = showcaseGet(
        queryFields: array_keys(TABLES_DATA['myshowcase_config'])
    );

    $scriptName = '';

    foreach ($showcaseObjects as $showcaseID => $showcaseData) {
        if ($selectedShowcaseID === $showcaseID) {
            $scriptName = $showcaseData['script_name'];
        }
    }

    return showcaseGetObjectByScriptName($scriptName);
}

function showcaseGetObjectByScriptName(string $scriptName): Showcase
{
    require_once ROOT . '/System/Showcase.php';
    require_once ROOT . '/System/Entry.php';

    static $showcaseObjects = [];

    if (!isset($showcaseObjects[$scriptName])) {
        $showcaseObjects[$scriptName] = new Showcase($scriptName);
    }

    return $showcaseObjects[$scriptName];
}

function renderGetObject(Showcase $showcaseObject): Render
{
    require_once ROOT . '/System/Render.php';

    static $renderObjects = [];

    if (!isset($renderObjects[$showcaseObject->showcase_id])) {
        $renderObjects[$showcaseObject->showcase_id] = new Render($showcaseObject);
    }

    return $renderObjects[$showcaseObject->showcase_id];
}

function dataHandlerGetObject(
    Showcase $showcaseObject,
    string $method = DATA_HANDLER_METHOD_INSERT
): DataHandler {
    require_once MYBB_ROOT . 'inc/datahandler.php';
    require_once ROOT . '/System/DataHandler.php';

    static $dataHandlerObjects = [];

    if (!isset($dataHandlerObjects[$showcaseObject->showcase_id])) {
        $dataHandlerObjects[$showcaseObject->showcase_id] = new DataHandler($showcaseObject, $method);
    }

    return $dataHandlerObjects[$showcaseObject->showcase_id];
}

function fieldGetObject(Showcase $showcaseObject, array $fieldData): FieldsInterface
{
    static $fieldObjects = [];

    if (!isset($fieldObjects[$showcaseObject->showcase_id][$fieldData['field_key']])) {
        require_once ROOT . '/Fields/FieldsInterface.php';
        require_once ROOT . '/Fields/FieldTrait.php';
        require_once ROOT . '/Fields/MultipleFieldTrait.php';
        require_once ROOT . '/Fields/SingleFieldTrait.php';

        switch ($fieldData['html_type']) {
            case FieldHtmlTypes::CheckBox:
                require_once ROOT . '/Fields/CheckBoxField.php';

                $fieldObjects[$showcaseObject->showcase_id][$fieldData['field_key']] = new CheckBoxField(
                    $showcaseObject, $fieldData
                );
                break;
            case FieldHtmlTypes::Color:
                require_once ROOT . '/Fields/ColorField.php';

                $fieldObjects[$showcaseObject->showcase_id][$fieldData['field_key']] = new ColorField(
                    $showcaseObject, $fieldData
                );
                break;
            case FieldHtmlTypes::Date:
                require_once ROOT . '/Fields/DateField.php';

                $fieldObjects[$showcaseObject->showcase_id][$fieldData['field_key']] = new DateField(
                    $showcaseObject, $fieldData
                );
                break;
            case FieldHtmlTypes::DateTimeLocal:
                require_once ROOT . '/Fields/DateTimeLocalField.php';

                $fieldObjects[$showcaseObject->showcase_id][$fieldData['field_key']] = new DateTimeLocalField(
                    $showcaseObject, $fieldData
                );
                break;
            case FieldHtmlTypes::Email:
                require_once ROOT . '/Fields/EmailField.php';

                $fieldObjects[$showcaseObject->showcase_id][$fieldData['field_key']] = new EmailField(
                    $showcaseObject, $fieldData
                );
                break;
            case FieldHtmlTypes::File:
                require_once ROOT . '/Fields/FileField.php';

                $fieldObjects[$showcaseObject->showcase_id][$fieldData['field_key']] = new FileField(
                    $showcaseObject, $fieldData
                );
                break;
            case FieldHtmlTypes::Month:
                require_once ROOT . '/Fields/MonthField.php';

                $fieldObjects[$showcaseObject->showcase_id][$fieldData['field_key']] = new MonthField(
                    $showcaseObject, $fieldData
                );
                break;
            case FieldHtmlTypes::Number:
                require_once ROOT . '/Fields/NumberField.php';

                $fieldObjects[$showcaseObject->showcase_id][$fieldData['field_key']] = new NumberField(
                    $showcaseObject, $fieldData
                );
                break;
            case FieldHtmlTypes::Password:
                require_once ROOT . '/Fields/PasswordField.php';

                $fieldObjects[$showcaseObject->showcase_id][$fieldData['field_key']] = new PasswordField(
                    $showcaseObject, $fieldData
                );
                break;
            case FieldHtmlTypes::Radio:
                require_once ROOT . '/Fields/RadioField.php';

                $fieldObjects[$showcaseObject->showcase_id][$fieldData['field_key']] = new RadioField(
                    $showcaseObject, $fieldData
                );
                break;
            case FieldHtmlTypes::Range:
                require_once ROOT . '/Fields/RangeField.php';

                $fieldObjects[$showcaseObject->showcase_id][$fieldData['field_key']] = new RangeField(
                    $showcaseObject, $fieldData
                );
                break;
            case FieldHtmlTypes::Search:
                require_once ROOT . '/Fields/SearchField.php';

                $fieldObjects[$showcaseObject->showcase_id][$fieldData['field_key']] = new SearchField(
                    $showcaseObject, $fieldData
                );
                break;
            case FieldHtmlTypes::Telephone:
                require_once ROOT . '/Fields/TelephoneField.php';

                $fieldObjects[$showcaseObject->showcase_id][$fieldData['field_key']] = new TelephoneField(
                    $showcaseObject, $fieldData
                );
                break;
            case FieldHtmlTypes::Text:
                require_once ROOT . '/Fields/TextField.php';

                $fieldObjects[$showcaseObject->showcase_id][$fieldData['field_key']] = new TextField(
                    $showcaseObject, $fieldData
                );
                break;
            case FieldHtmlTypes::Time:
                require_once ROOT . '/Fields/TimeField.php';

                $fieldObjects[$showcaseObject->showcase_id][$fieldData['field_key']] = new TimeField(
                    $showcaseObject, $fieldData
                );
                break;
            case FieldHtmlTypes::Url:
                require_once ROOT . '/Fields/UrlField.php';

                $fieldObjects[$showcaseObject->showcase_id][$fieldData['field_key']] = new UrlField(
                    $showcaseObject, $fieldData
                );
                break;
            case FieldHtmlTypes::Week:
                require_once ROOT . '/Fields/WeekField.php';

                $fieldObjects[$showcaseObject->showcase_id][$fieldData['field_key']] = new WeekField(
                    $showcaseObject, $fieldData
                );
                break;
            case FieldHtmlTypes::TextArea:
                require_once ROOT . '/Fields/TextAreaField.php';

                $fieldObjects[$showcaseObject->showcase_id][$fieldData['field_key']] = new TextAreaField(
                    $showcaseObject, $fieldData
                );
                break;
            case FieldHtmlTypes::Select:
                require_once ROOT . '/Fields/SelectField.php';

                $fieldObjects[$showcaseObject->showcase_id][$fieldData['field_key']] = new SelectField(
                    $showcaseObject, $fieldData
                );
                break;
            case FieldHtmlTypes::SelectUsers:
                require_once ROOT . '/Fields/SelectUsersField.php';

                $fieldObjects[$showcaseObject->showcase_id][$fieldData['field_key']] = new SelectUsersField(
                    $showcaseObject, $fieldData
                );
                break;
            case FieldHtmlTypes::SelectEntries:
                require_once ROOT . '/Fields/SelectEntriesField.php';

                $fieldObjects[$showcaseObject->showcase_id][$fieldData['field_key']] = new SelectEntriesField(
                    $showcaseObject, $fieldData
                );
                break;
            case FieldHtmlTypes::SelectThreads:
                require_once ROOT . '/Fields/SelectThreadsField.php';

                $fieldObjects[$showcaseObject->showcase_id][$fieldData['field_key']] = new SelectThreadsField(
                    $showcaseObject, $fieldData
                );
                break;
            default:
                _dump($fieldData['html_type']);
        }
    }

    return $fieldObjects[$showcaseObject->showcase_id][$fieldData['field_key']];
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

function formatField(int $formatType, string|int &$fieldValue): string|int
{
    $fieldValue = match ($formatType) {
        FormatTypes::numberFormat => $fieldValue = my_number_format((int)$fieldValue),
        FormatTypes::numberFormat1 => $fieldValue = number_format((float)$fieldValue, 1),
        FormatTypes::numberFormat2 => $fieldValue = number_format((float)$fieldValue, 2),
        FormatTypes::htmlSpecialCharactersUni => $fieldValue = htmlspecialchars_uni($fieldValue),
        FormatTypes::stripTags => $fieldValue = strip_tags($fieldValue),
        default => $fieldValue
    };

    return $fieldValue;
}

function fieldTypesGet(): array
{
    return array_flip((new ReflectionClass(FieldTypes::class))->getConstants());
}

function fieldTypeMatchInt(string $fieldType): bool
{
    require_once ROOT . '/System/FieldTypes.php';

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

//https://stackoverflow.com/a/15875555
function generateUUIDv4(): string
{
    $data = random_bytes(16);

    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100

    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function slugGenerateEntry(int $showcaseID, ?int $length = null): string
{
    if (empty($length)) {
        $length = getSetting('slugLength');
    }

    global $db;

    $generatedSlug = '';

    $uniqueFound = false;

    while (!$uniqueFound) {
        $generatedSlug = my_strtolower(bin2hex(random_bytes($length)));

        $uniqueFound = !entryGet($showcaseID, ["entry_slug='{$db->escape_string($generatedSlug)}'"]);
    }

    return $generatedSlug;
}

function slugGenerateComment(?int $length = null): string
{
    if (empty($length)) {
        $length = getSetting('slugLength');
    }

    global $db;

    $generatedSlug = '';

    $uniqueFound = false;

    while (!$uniqueFound) {
        $generatedSlug = my_strtolower(bin2hex(random_bytes($length)));

        $uniqueFound = !commentsGet(["comment_slug='{$db->escape_string($generatedSlug)}'"]);
    }

    return $generatedSlug;
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

function cleanSlug(string $slug): string
{
    return str_replace(['---', '--'],
        '-',
        preg_replace(
            '/[^\da-z]/i',
            '-',
            my_strtolower($slug)
        ));
}

function generateFieldSetSelectArray(): array
{
    return array_map(function ($fieldsetData) {
        return $fieldsetData['set_name'];
    }, cacheGet(CACHE_TYPE_FIELD_SETS));
}

function generateFilterFieldsSelectArray(): array
{
    global $lang;

    return [
        FILTER_TYPE_NONE => $lang->none,
        FILTER_TYPE_USER_ID => $lang->myShowcaseAdminSummaryAddEditFilterForceFieldUserID,
    ];
}

function generateWatermarkLocationsSelectArray(): array
{
    return [
        WATERMARK_LOCATION_LOWER_LEFT => 'lower-left',
        WATERMARK_LOCATION_LOWER_RIGHT => 'lower-right',
        WATERMARK_LOCATION_CENTER => 'center',
        WATERMARK_LOCATION_UPPER_LEFT => 'upper-left',
        WATERMARK_LOCATION_UPPER_RIGHT => 'upper-right',
    ];
}

function attachmentLogInsert(array $logData, bool $isUpdate = false, int $logID = 0)
{
    global $db;

    $tableFields = TABLES_DATA['myshowcase_attachments_download_logs'];

    $insertData = [];

    $hookArguments = [
        'insertData' => &$insertData,
        'logData' => &$logData,
        'isUpdate' => $isUpdate,
        'logID' => &$logID,
        'tableFields' => &$tableFields,
    ];

    foreach ($tableFields as $fieldName => $fieldDefinition) {
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