<?php

declare(strict_types=1);

namespace MyShowcase\Service;

use datacache;
use DB_MySQLi;
use DB_PgSQL;
use DB_SQLite;
use MyShowcase\Enum\CacheDataTypes;
use MyShowcase\Enum\ModeratorPermissions;
use MyShowcase\Repository\ModeratorsRepository;
use MyShowcase\Repository\PermissionsRepository;

use MysqlPdoDbDriver;

use PostgresPdoDbDriver;

use function MyShowcase\Plugin\Functions\castTableFieldValue;
use function MyShowcase\Plugin\Functions\fieldDataGet;
use function MyShowcase\Plugin\Functions\fieldsetGet;
use function MyShowcase\Plugin\Functions\fieldsGet;
use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\showcaseGet;

use const MyShowcase\DEBUG;
use const MyShowcase\Plugin\Core\TABLES_DATA;

readonly class CacheManager
{
    public function __construct(
        private readonly DB_SQLite|DB_PgSQL|PostgresPdoDbDriver|MysqlPdoDbDriver|DB_MySQLi $database,
        private readonly datacache $cache,
    ) {
    }

    /**
     * Update the cache.
     *
     * @param int $cacheKey The cache item.
     * @return array
     */
    public function update(int $cacheKey): array
    {
        $cache_data = [];

        $tablesData = TABLES_DATA;

        $hookArguments = [
            'cacheKey' => $cacheKey,
            'cacheData' => &$cache_data,
            'tableData' => &$tablesData,
        ];

        $hookArguments = hooksRun('cache_update_start', $hookArguments);

        switch ($cacheKey) {
            case CacheDataTypes::Config:
                $showcaseObjects = showcaseGet(
                    queryFields: array_keys($tablesData['myshowcase_config']),
                    queryOptions: ['order_by' => 'display_order']
                );

                foreach ($showcaseObjects as $showcase_id => $showcaseData) {
                    $cache_data[$showcase_id] = [];

                    foreach ($tablesData['myshowcase_config'] as $field_name => $field_definition) {
                        if (isset($showcaseData[$field_name])) {
                            $cache_data[$showcase_id][$field_name] = castTableFieldValue(
                                $showcaseData[$field_name],
                                $field_definition['type']
                            );
                        }
                    }
                }

                break;
            case CacheDataTypes::Permissions:
                $function = function (iterable $generator) use ($tablesData, &$cache_data) {
                    foreach ($generator as $permission_id => $permission_data) {
                        $showcase_id = (int)$permission_data['showcase_id'];

                        $group_id = (int)$permission_data['group_id'];

                        $cache_data[$showcase_id][$group_id] = [];

                        foreach ($tablesData['myshowcase_permissions'] as $field_name => $field_definition) {
                            if (isset($permission_data[$field_name])) {
                                $cache_data[$showcase_id][$group_id][$field_name] = castTableFieldValue(
                                    $permission_data[$field_name],
                                    $field_definition['type']
                                );
                            }
                        }
                    }
                };

                $function(
                    (new PermissionsRepository($this->database))
                        ->queryPermissions(query_fields: array_keys($tablesData['myshowcase_permissions']))
                );

                foreach (showcaseGet() as $showcase_id => $showcaseData) {
                    $function(
                        (new PermissionsRepository($this->database, ['showcase_id' => $showcase_id]))
                            ->queryPermissions(query_fields: array_keys($tablesData['myshowcase_permissions']))
                    );
                }

                break;
            case CacheDataTypes::Fieldsets:
                $fieldsetObjects = fieldsetGet(
                    [],
                    ['set_id', 'set_name']
                );

                foreach ($fieldsetObjects as $fieldsetID => $fieldsetData) {
                    $cache_data[$fieldsetID] = [
                        'set_id' => (int)$fieldsetData['set_id'],
                        'set_name' => (string)$fieldsetData['set_name'],
                    ];
                }

                break;
            case CacheDataTypes::Fields:
                $queryFields = $tablesData['myshowcase_fields'];

                unset($queryFields['unique_keys']);

                $fieldObjects = fieldsGet(
                    [],
                    array_keys($queryFields),
                    ['order_by' => 'display_order']
                );

                foreach ($fieldObjects as $fieldID => $fieldData) {
                    foreach ($tablesData['myshowcase_fields'] as $field_name => $field_definition) {
                        if (isset($fieldData[$field_name])) {
                            $cache_data[(int)$fieldData['set_id']][$fieldID][$field_name] = castTableFieldValue(
                                $fieldData[$field_name],
                                $field_definition['type']
                            );
                        }
                    }
                }

                break;
            case CacheDataTypes::FieldData;
                $fieldDataObjects = fieldDataGet(
                    [],
                    ['set_id', 'field_id', 'value_id', 'value', 'value_id', 'display_order'],
                    ['order_by' => 'display_order']
                );

                foreach ($fieldDataObjects as $fieldDataID => $field_definition) {
                    $cache_data[(int)$field_definition['set_id']][$fieldDataID][(int)$field_definition['value_id']] = $field_definition;
                }

                break;
            case CacheDataTypes::Moderators;
                $moderatorObjects = (new ModeratorsRepository($this->database))
                    ->queryModerators(
                        [],
                        [
                            'moderator_id',
                            'showcase_id',
                            'user_id',
                            'is_group',
                            ModeratorPermissions::EntriesCanView,
                            ModeratorPermissions::EntriesCanView,
                            ModeratorPermissions::EntriesCanView,
                            ModeratorPermissions::CommentsCanView
                        ]
                    );

                foreach ($moderatorObjects as $moderatorID => $moderatorData) {
                    $cache_data[(int)$moderatorData['showcase_id']][$moderatorID] = $moderatorData;
                }

                break;
            case CacheDataTypes::AttachmentTypes;
                $query = $this->database->simple_select(
                    'attachtypes',
                    'atid AS attachment_type_id, name AS type_name, mimetype AS mime_type, extension AS file_extension, maxsize AS maximum_size, icon AS type_icon, forcedownload AS force_download, groups AS allowed_groups, myshowcase_ids, myshowcase_image_minimum_dimensions, myshowcase_image_maximum_dimensions',
                    "myshowcase_ids!=''"
                );

                while ($attachmentTypeData = $this->database->fetch_array($query)) {
                    $attachmentTypeID = (int)$attachmentTypeData['attachment_type_id'];

                    foreach (explode(',', $attachmentTypeData['myshowcase_ids']) as $showcase_id) {
                        $showcase_id = (int)$showcase_id;

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

                        $cache_data[$showcase_id][$attachmentTypeID] = [
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

        $this->cache->update("myshowcase_{$cacheKey}", $cache_data);

        return $cache_data;
    }

    public function get(int $cacheKey, bool $forceReload = false): array
    {
        $cache_data = $this->cache->read("myshowcase_{$cacheKey}");

        if (!is_array($cache_data) && $forceReload || DEBUG) {
            $cache_data = $this->update($cacheKey);
        }

        return $cache_data ?? [];
    }
}