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

namespace MyShowcase\Admin;

use Form;
use MyBB;
use MyLanguage;
use MyShowcase\Entity\PermissionEntity;
use MyShowcase\Util\PluginLibraryLoader;
use MyShowcase\Entity\ShowcaseEntity;
use MyShowcase\Enum\CacheDataTypes;
use MyShowcase\Enum\FormTypes;
use MyShowcase\Repository\EntriesRepository;
use MyShowcase\Service\CacheManager;
use MyShowcase\Service\ExportsStorage;
use MyShowcase\Service\SchemaManager;
use MyShowcase\Showcase;
use MyShowcase\Util\SelectableLists;
use Random\RandomException;
use ReflectionClass;

use function MyShowcase\Plugin\Functions\dataTableStructureGet;
use function MyShowcase\Plugin\Functions\fieldTypeMatchBinary;
use function MyShowcase\Plugin\Functions\fieldTypeMatchChar;
use function MyShowcase\Plugin\Functions\fieldTypeMatchDateTime;
use function MyShowcase\Plugin\Functions\fieldTypeMatchText;
use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\loadLanguage;
use function MyShowcase\Plugin\Functions\showcaseDataTableDrop;
use function MyShowcase\Plugin\Functions\showcaseDataTableExists;
use function MyShowcase\Plugin\Functions\showcaseGet;

use const MyShowcase\Plugin\Core\DATA_TABLE_STRUCTURE;
use const MyShowcase\Plugin\Core\FIELDS_DATA;
use const MyShowcase\Plugin\Core\TABLES_DATA;
use const MyShowcase\VERSION;
use const MyShowcase\VERSION_CODE;

function pluginInformation(): array
{
    global $lang;

    loadLanguage();

    $donate_button =
        '<a href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=NZ574GV99KXTU&item_name=Donation%20for%20MyShowcase" style="float:right;margin-top:-8px;padding:4px;" target="_blank"><img src="https://www.paypalobjects.com/WEBSCR-640-20110306-1/en_US/i/btn/btn_donate_SM.gif" /></a>';

    return [
        'name' => 'MyShowcase System',
        'description' => $donate_button . $lang->myshowcase_system_description,
        'website' => 'https://ougc.network',
        'author' => 'CommunityPlugins & Omar G.',
        'authorsite' => 'https://ougc.network',
        'version' => VERSION,
        'versioncode' => VERSION_CODE,
        'compatibility' => '19*',
        'codename' => 'ougc_myshowcase',
        'pl' => [
            'version' => PluginLibraryLoader::MINIMUM_VERSION,
            'url' => 'https://community.mybb.com/mods.php?action=view&pid=573'
        ],
        'myalerts' => [
            'version' => '2.1.0',
            'url' => 'https://community.mybb.com/thread-171301.html'
        ]
    ];
}

/**
 * @throws RandomException
 */
function pluginActivation(): bool
{
    global $PL, $mybb;

    loadPluginLibrary();

    $PL->settings('myshowcase', 'MyShowcase System', 'Options on how to configure the MyShowcase section.', [
        'delete_tables_on_uninstall' => [
            'title' => 'Drop MyShowcase tables when uninstalling?',
            'description' => 'When uninstalling, do you want to drop the showcase tables and delete the data in them? Reinstalling will not overwrite existing data if you select [no].',
            'optionscode' => 'yesno',
            'value' => 0
        ],
    ]);

    // Insert/update version into cache
    $plugins = $mybb->cache->read('ougc_plugins');

    if (!$plugins) {
        $plugins = [];
    }

    $pluginInformation = pluginInformation();

    if (!isset($plugins['myshowcase'])) {
        $plugins['myshowcase'] = $pluginInformation['versioncode'];
    }

    taskInstallation();

    /*~*~* RUN UPDATES START *~*~*/

    global $db;

    SchemaManager::migrateCollections(TABLES_DATA);

    foreach (
        [
            'myshowcase_attachments' => [
                'aid' => 'attachment_id',
                'gid' => 'entry_id',
                'id' => 'showcase_id',
                'posthash' => 'post_hash',
                'uid' => 'user_id',
                'filename' => 'file_name',
                'filetype' => 'mime_type',
                'filesize' => 'file_size',
                'attachname' => 'attachment_name',
                'dateuploaded' => 'dateline',
                'uploaddate' => 'update_dateline',
                'visible' => 'status',
                'thumbnail' => 'thumbnail_name',
            ],
            'myshowcase_comments' => [
                'cid' => 'comment_id',
                'id' => 'showcase_id',
                'gid' => 'entry_id',
                'uid' => 'user_id',
            ],
            'myshowcase_config' => [
                // todo, drop thumb_width, thumb_height, maximum_text_field_length (prune_time replaced by main_prune_age)
                'id' => 'showcase_id',
                'fieldsetid' => 'collection_id',
                'f2gpath' => 'showcase_url',
                'allowsmilies' => 'parser_allow_smilies',
                'allowbbcode' => 'parser_allow_mycode_in_entries',
                'allowhtml' => 'parser_allow_mycode_in_entries',
                'othermaxlength' => 'maximum_text_field_length',
                'maximum_text_field_lenght' => 'maximum_text_field_length',
                'comment_dispinit' => 'comments_per_page',
                'disp_attachcols' => 'attachments_grouping',
                'attachments_per_row' => 'attachments_grouping',
                'disp_empty' => 'entries_display_empty_fields',
                'link_in_postbit' => 'display_in_posts',
                'allow_attachments' => 'attachments_allow_in_entries',
                'use_attach' => 'attachments_main_render',
                'defaultimage' => 'attachments_main_render_default_image',
                'default_image' => 'attachments_main_render_default_image',
                'imgfolder' => 'attachments_uploads_path',
                'images_directory' => 'attachments_uploads_path',
                'watermarkloc' => 'attachments_watermark_location',
                'water_mark_image_location' => 'attachments_watermark_location',
                'portal_random' => 'attachments_portal_build_widget',
                'build_random_entry_widget' => 'attachments_portal_build_widget',
                'mainfile' => 'route_identifier',
                'modnewedit' => 'moderate_entries_update',
                'moderate_edits' => 'moderate_entries_update',
                'comment_length' => 'comments_message_maximum_length',
                'allow_html' => 'parser_allow_html_in_entries',
                'allow_mycode' => 'parser_allow_mycode_in_entries',
                'allow_smilies' => 'parser_allow_smilies',
                'allow_image_code' => 'parser_allow_image_code_in_entries',
                'allow_video_code' => 'parser_allow_video_code_in_entries',
                'allow_comments' => 'comments_enable',
                'display_signatures' => 'entries_display_signatures',
            ],
            'myshowcase_permissions' => [
                // drop candelauthcomment
                'pid' => 'permission_id',
                'id' => 'showcase_id',
                'gid' => 'group_id',
                'canview' => 'main_can_view',
                'canadd' => 'entries_can_post',
                'canedit' => 'entries_can_update',
                'canviewcomment' => 'comments_can_view',
                'cancomment' => 'comments_can_post',
                'candelowncomment' => 'comments_can_delete',
                'canviewattach' => 'attachments_can_view',
                'canattach' => 'attachments_can_upload',
                'canwatermark' => 'attachments_watermark_images',
                'cansearch' => 'can_search_entries',
            ],
            'myshowcase_moderators' => [
                'mid' => 'moderator_id',
                'id' => 'showcase_id',
                'uid' => 'user_id',
                'isgroup' => 'group_id',
            ],
            'myshowcase_collections' => [
                'setid' => 'collection_id',
                'setname' => 'name',
            ],
            'myshowcase_fields' => [
                'fid' => 'field_id',
                'setid' => 'collection_id',
                'name' => 'field_key',
                'min_length' => 'minimum_length',
                'max_length' => 'maximum_length',
                'require' => 'is_required',
                'requiredField' => 'is_required',
                'field_order' => 'display_order',
                'list_table_order' => 'render_order',
                'searchable' => 'enable_search',
            ],
            'myshowcase_field_data' => [
                //'value_id' => 'field_data_id',
                'fieldDataID' => 'field_data_id',
                'valueid' => 'value_id',
                'disporder' => 'display_order',
                'setid' => 'collection_id',
                'fid' => 'field_id',
            ],
            'myshowcase_data' => [
                'gid' => 'entry_id',
                'uid' => 'user_id',
                'posthash' => 'post_hash',
            ],
        ] as $tableName => $tableData
    ) {
        if ($db->table_exists($tableName)) {
            foreach ($tableData as $oldColumnName => $newColumnName) {
                if ($db->field_exists($oldColumnName, $tableName) &&
                    !$db->field_exists($newColumnName, $tableName) &&
                    isset(TABLES_DATA[$tableName][$newColumnName])) {
                    SchemaManager::renameColumn(
                        $tableName,
                        $oldColumnName,
                        $newColumnName,
                        TABLES_DATA[$tableName][$newColumnName]
                    );
                }
            }
        }

        if ($tableName === 'myshowcase_data' && $db->table_exists('myshowcase_config')) {
            foreach (showcaseGet() as $showcase_id => $showcase_data) {
                if (showcaseDataTableExists($showcase_id)) {
                    foreach ($tableData as $oldColumnName => $newColumnName) {
                        if ($db->field_exists($oldColumnName, 'myshowcase_data' . $showcase_id) &&
                            !$db->field_exists($newColumnName, 'myshowcase_data' . $showcase_id)) {
                            SchemaManager::renameColumn(
                                'myshowcase_data' . $showcase_id,
                                $oldColumnName,
                                $newColumnName,
                                DATA_TABLE_STRUCTURE[$tableName][$newColumnName]
                            );
                        }
                    }
                }
            }
        }
    }

    if ($db->table_exists('myshowcase_reports')) {
        //$db->drop_table('myshowcase_reports');
    }

    /*~*~* RUN UPDATES END *~*~*/

    $tablesData = TABLES_DATA;

    $hookArguments = [
        'tablesData' => &$tablesData
    ];

    $hookArguments = hooksRun('admin_activate_intermediate', $hookArguments);

    // Unique indexes are created at the end.
    dbVerifyTables($tablesData, false);

    dbVerifyColumns();

    cachesRebuild();

    dbConvertTablesToInnoDb();

    /*~*~* RUN UPDATES START *~*~*/

    $db->update_query(
        'myshowcase_config',
        ['attachments_watermark_location' => 0],
        'attachments_watermark_location NOT IN (1,2,3,4,5)'
    );

    /*~*~* RUN UPDATES END *~*~*/

    foreach (showcaseGet() as $showcase_id => $showcase_data) {
        $dataTableStructure = dataTableStructureGet($showcase_id);

        dbVerifyTables(["myshowcase_data{$showcase_id}" => $dataTableStructure], false);
    }

    dbVerifyIndexes($tablesData);

    foreach (showcaseGet() as $showcase_id => $showcase_data) {
        dbVerifyIndexes(["myshowcase_data{$showcase_id}" => dataTableStructureGet($showcase_id)]);
    }

    $mybb->cache->update_usergroups();

    $plugins['myshowcase'] = $pluginInformation['versioncode'];

    $mybb->cache->update('ougc_plugins', $plugins);

    cachesRebuild();

    return true;
}

/**
 * Converts the plugin tables and the showcase data tables that are still on MyISAM to InnoDB (MySQL and MariaDB
 * only; see SchemaManager::convertToInnoDb()). Core tables are never touched.
 */
function dbConvertTablesToInnoDb(): void
{
    $tables = array_keys(TABLES_DATA);

    foreach (array_keys(showcaseGet()) as $showcase_id) {
        if (showcaseDataTableExists((int)$showcase_id)) {
            $tables[] = 'myshowcase_data' . (int)$showcase_id;
        }
    }

    SchemaManager::convertToInnoDb($tables);
}

/**
 * Rebuilds the plugin caches.
 */
function cachesRebuild(): void
{
    global $db, $cache;

    $cache_manager = new CacheManager($db, $cache);

    foreach (
        [
            CacheDataTypes::Config,
            CacheDataTypes::Permissions,
            CacheDataTypes::Collections,
            CacheDataTypes::Moderators,
            CacheDataTypes::Fields,
            CacheDataTypes::FieldData,
            CacheDataTypes::AttachmentTypes,
        ] as $cacheType
    ) {
        $cache_manager->update($cacheType);
    }
}

function pluginDeactivation(): bool
{
    _deactivate_task();

    return true;
}

function pluginIsInstalled(): bool
{
    $tablesData = TABLES_DATA;

    $hookArguments = [
        'tablesData' => &$tablesData
    ];

    $hookArguments = hooksRun('admin_is_installed_start', $hookArguments);

    return dbVerifyTablesExists($tablesData) && dbVerifyColumnsExists() && dbVerifyColumnsExists($tablesData);
}

function pluginUninstallation(): bool
{
    global $db, $PL, $mybb;

    loadPluginLibrary();

    //drop tables but only if setting specific to allow uninstall is is_enabled
    if (!empty($mybb->settings['myshowcase_delete_tables_on_uninstall'])) {
        foreach (showcaseGet() as $showcase_id => $showcase_data) {
            if (showcaseDataTableExists($showcase_id)) {
                showcaseDataTableDrop($showcase_id);
            }
        }

        (new ExportsStorage())->removeAll();

        foreach (dbTables() as $tableName => $tableSchema) {
            SchemaManager::dropTable(
                $tableName,
                array_filter([$tableSchema['primary']])
            );
        }

        // Sequences left behind on PostgreSQL by installations that were migrated from field sets.
        if (SchemaManager::engine() === SchemaManager::ENGINE_PGSQL) {
            foreach (['set_id', 'setid'] as $legacyColumn) {
                $db->write_query(
                    SchemaManager::dropSequenceSql("myshowcase_fieldsets_{$legacyColumn}_seq", $db->table_prefix)
                );
            }
        }

        foreach (FIELDS_DATA as $tableName => $tableData) {
            foreach ($tableData as $field_name => $field_definition) {
                !$db->field_exists($field_name, $tableName) || $db->drop_column($tableName, $field_name);
            }
        }
    }

    $PL->settings_delete('myshowcase');

    $PL->templates_delete('myshowcase');

    // Delete version from cache
    $plugins = (array)$mybb->cache->read('ougc_plugins');

    if (isset($plugins['myshowcase'])) {
        unset($plugins['myshowcase']);
    }

    if (!empty($plugins)) {
        $mybb->cache->update('ougc_plugins', $plugins);
    } else {
        $mybb->cache->delete('ougc_plugins');
    }

    foreach ((new ReflectionClass(CacheDataTypes::class))->getConstants() as $cacheType) {
        $mybb->cache->delete("myshowcase_{$cacheType}");
    }

    taskUninstallation();

    return true;
}

function loadPluginLibrary(bool $check = true): bool
{
    global $PL, $lang;

    loadLanguage();

    if ($file_exists = file_exists(PLUGINLIBRARY)) {
        global $PL;

        $PL || require_once PLUGINLIBRARY;
    }

    if (!$check) {
        return false;
    }

    $_info = pluginInformation();

    if (!$file_exists || $PL->version < $_info['pl']['version']) {
        flash_message(
            $lang->sprintf($lang->MyShowcaseSystemPluginLibrary, $_info['pl']['url'], $_info['pl']['version']),
            'error'
        );

        admin_redirect('index.php?module=config-plugins');
    }

    return true;
}

function taskInstallation(int $action = 1): bool
{
    global $db, $lang, $cache, $new_task;

    loadLanguage();

    $query = $db->simple_select('tasks', '*', "file='myshowcase'", ['limit' => 1]);

    $task = $db->fetch_array($query);

    if ($task) {
        $db->update_query('tasks', ['enabled' => $action], "file='myshowcase'");
    } else {
        include_once MYBB_ROOT . 'inc/functions_task.php';

        $_ = $db->escape_string('*');

        $new_task = [
            'title' => $db->escape_string('MyShowcase'),
            'description' => $db->escape_string('Prunes showcases where is_enabled.'),
            'file' => $db->escape_string('myshowcase'),
            'minute' => 9,
            'hour' => 4,
            'day' => $_,
            'weekday' => $_,
            'month' => $_,
            'enabled' => 1,
            'logging' => 1
        ];

        $new_task['nextrun'] = fetch_next_run($new_task);

        $db->insert_query('tasks', $new_task);
    }

    $cache->update_tasks();

    return true;
}

function taskUninstallation(): bool
{
    global $db, $cache, $tid;

    $tid = $db->delete_query('tasks', "file='myshowcase'");

    $cache->update_tasks();

    return true;
}

function _deactivate_task(): bool
{
    global $cache;

    taskInstallation(0);

    $cache->update_tasks();

    return true;
}

/**
 * Table definitions split into columns, primary key and indexes (see SchemaManager::schemaOf()).
 */
function dbTables(array $tableObjects = TABLES_DATA): array
{
    $tablesData = [];

    foreach ($tableObjects as $tableName => $tableData) {
        $tablesData[$tableName] = SchemaManager::schemaOf($tableData);
    }

    return $tablesData;
}

/**
 * Creates missing tables and adds missing columns, then creates the regular, fulltext and unique indexes.
 *
 * @param bool $verifyUniqueKeys Create unique indexes too. Activation turns this off for its first pass and creates
 *                               them at its end.
 */
function dbVerifyTables(array $tableObjects = TABLES_DATA, bool $verifyUniqueKeys = true): bool
{
    foreach ($tableObjects as $tableName => $tableData) {
        SchemaManager::verifyTable($tableName, $tableData);
    }

    dbVerifyIndexes($tableObjects, $verifyUniqueKeys);

    return true;
}

function dbVerifyIndexes(array $tableObjects = TABLES_DATA, bool $verifyUniqueKeys = true): bool
{
    foreach ($tableObjects as $tableName => $tableData) {
        SchemaManager::verifyIndexes($tableName, $tableData, $verifyUniqueKeys);
    }

    return true;
}

function dbVerifyColumns(array $fieldObjects = FIELDS_DATA): bool
{
    foreach ($fieldObjects as $tableName => $tableData) {
        SchemaManager::verifyCoreColumns($tableName, $tableData);
    }

    return true;
}

function dbVerifyTablesExists(array $tableObjects = TABLES_DATA): bool
{
    global $db;

    $isInstalledEach = true;

    foreach (dbTables($tableObjects) as $tableName => $tableData) {
        $isInstalledEach = $db->table_exists($tableName) && $isInstalledEach;
    }

    return $isInstalledEach;
}

function dbVerifyColumnsExists(array $fieldObjects = FIELDS_DATA): bool
{
    global $db;

    $isInstalledEach = true;

    foreach ($fieldObjects as $tableName => $tableData) {
        if (!$db->table_exists($tableName)) {
            $isInstalledEach = false;

            continue;
        }

        foreach ($tableData as $field_name => $field_definition) {
            if (!isset($field_definition['type'])) {
                continue;
            }

            $isInstalledEach = $db->field_exists($field_name, $tableName) && $isInstalledEach;
        }
    }

    return $isInstalledEach;
}

/**
 * Column definition (without the column name) for the engine the board runs on.
 */
function buildDbFieldDefinition(array $field_definition): string
{
    return SchemaManager::currentColumnDefinition($field_definition);
}

/**
 * Create or edit language file.
 *
 * @param string Language file name or title.
 * @param array Language variables to add (key is varname, value is value).
 * @param array Language variables to remove (key is varname, value is value).
 * @param string Actual language.
 * @param bool Specifies if the language file is for ACP
 */
function languageModify(
    string $languageFileName,
    array $languageVariablesAddition = [],
    array $languageVariablesDelete = [],
    string $languageName = 'english',
    bool $isAdministrator = false
): bool {
    global $lang;

    if (count($languageVariablesAddition) === 0 && count($languageVariablesDelete) === 0) {
        return false;
    }

    $languageName = my_strtolower($languageName);

    $languageFileName = my_strtolower($languageFileName);

    if (!$isAdministrator) {
        $languagePath = $lang->path . '/' . $languageName;
    } else {
        $languagePath = $lang->path . $languageName . '/admin/';
    }

    if (!is_writable($languagePath)) {
        return false;
    }

    $languagePath = $languagePath . '/' . $languageFileName . '.lang.php';

    // include, not include_once: the sync writes the same file many times in one request, and a file that was
    // already included would not define $l again, so the entries written before would be lost.
    $l = [];

    if (file_exists($languagePath)) {
        if (!is_writable($languagePath)) {
            return false;
        }

        include $languagePath;
    }

    if (!isset($l) || !is_array($l)) {
        $l = [];
    }

    $l = array_merge($l, $languageVariablesAddition);

    $l = array_diff_key($l, $languageVariablesDelete);

    $pluginInformation = myshowcase_info();

    $fp = fopen($languagePath, 'w');

    fwrite($fp, '<?php' . PHP_EOL);

    $languageLines = '/**' . PHP_EOL;
    $languageLines .= ' * MyShowcase System for MyBB - ' . $lang->language . ' Language File ' . PHP_EOL;
    $languageLines .= ' * Copyright 2010 PavementSucks.com, All Rights Reserved' . PHP_EOL;
    $languageLines .= ' *' . PHP_EOL;
    $languageLines .= ' * Website: http://www.pavementsucks.com/plugins.html' . PHP_EOL;
    $languageLines .= ' * Version ' . $pluginInformation['version'] . PHP_EOL;
    $languageLines .= ' * License:' . PHP_EOL;
    $languageLines .= ' * File: \inc\langauges\\' . $lang->language . '\myshowcase.lang.php ' . PHP_EOL;
    $languageLines .= ' *' . PHP_EOL;
    $languageLines .= ' * MyShowcase language file for collection' . PHP_EOL;
    $languageLines .= ' */' . PHP_EOL . PHP_EOL;

    fwrite($fp, $languageLines);

    foreach ($l as $languageVariableKey => $languageVariableValue) {
        $languageVariableValue = ucfirst($languageVariableValue);

        $languageLines = "\$l['{$languageVariableKey}'] = '{$languageVariableValue}';" . PHP_EOL;

        fwrite($fp, $languageLines);
    }

    fclose($fp);

    unset($l, $fp, $languagePath);

    return true;
}

function buildPermissionsRow(
    MyBB $mybb,
    Form $form,
    MyLanguage $lang,
    string $name,
    array $definition,
    string $section = 'main',
    string $language_prefix = 'myShowcaseAdminSummaryAddEdit',
    bool $display_extra_text = false,
    array $input = [],
    null|ShowcaseEntity|array $showcase_config = null,
): string {
    $input_code = '';

    $options = ['id' => $name, 'class' => $definition['class'] ?? ''];

    if (!empty($definition['is_disabled'])) {
        $options['id'] .= '" disabled="disabled';
    }

    if (isset($definition['size'])) {
        $options['class'] .= "\" maxlength=\"{$definition['size']}";
    } elseif (isset($definition['max'])) {
        $options['class'] .= "\" max=\"{$definition['max']}";
    }

    if ($showcase_config instanceof ShowcaseEntity) {
        $showcase_config = (array)$showcase_config;
    }

    switch ($definition['input_type']) {
        case FormTypes::PermissionBoolean:
            if ($display_extra_text) {
                $input_code .= '<div class="group_settings_bit">';

                $input_code .= $lang->{$language_prefix . ($section ? '_' . $section : '') . '_' . $name} ?? $language_prefix . ($section ? '_' . $section : '') . '_' . $name;

                $input_code .= '<br /><small class="input">';

                $input_code .= $lang->{$language_prefix . ($section ? '_' . $section : '') . '_' . $name . '_description'} ?? $language_prefix . ($section ? '_' . $section : '') . '_' . $name . '_description';

                $input_code .= '</small><br />';
            }

            $input_code .= $form->generate_select_box(
                "showcase[{$name}]",
                [
                    PermissionEntity::BOOLEAN_TYPE_DISALLOW => $lang->{$language_prefix . ($section ? '_' . $section : '') . '_boolean_type_disallow'} ?? $language_prefix . ($section ? '_' . $section : '') . '_boolean_type_disallow',
                    PermissionEntity::BOOLEAN_TYPE_ALLOW => $lang->{$language_prefix . ($section ? '_' . $section : '') . '_boolean_type_allow'} ?? $language_prefix . ($section ? '_' . $section : '') . '_boolean_type_allow',
                    PermissionEntity::BOOLEAN_TYPE_INHERIT => $lang->{$language_prefix . ($section ? '_' . $section : '') . '_boolean_type_inherit'} ?? $language_prefix . ($section ? '_' . $section : '') . '_boolean_type_inherit',
                    PermissionEntity::BOOLEAN_TYPE_NEVER => $lang->{$language_prefix . ($section ? '_' . $section : '') . '_boolean_type_never'} ?? $language_prefix . ($section ? '_' . $section : '') . '_boolean_type_never',
                ],
                [$input[$name] ?? $mybb->get_input($name)],
                $options
            );

            if ($display_extra_text) {
                $input_code .= '</div>';
            }

            break;
        case FormTypes::SelectTimeInterval:
        case FormTypes::SelectCollection:
        case FormTypes::SelectTheme:
        case FormTypes::SelectRulesType:
        case FormTypes::SelectFilterField:
        case FormTypes::SelectSortField:
        case FormTypes::SelectPrefixType:
        case FormTypes::SelectAttachmentsMainRender:
        case FormTypes::SelectWatermarkLocation:
        case FormTypes::SelectAttachmentsDisplayType:
        case FormTypes::SelectTemplateSet:
            if ($display_extra_text) {
                $input_code .= '<div class="group_settings_bit">';

                $input_code .= $lang->{$language_prefix . ($section ? '_' . $section : '') . '_' . $name};

                $input_code .= '<br /><small class="input">';

                $input_code .= $lang->{$language_prefix . ($section ? '_' . $section : '') . '_' . $name . '_description'};

                $input_code .= '</small><br />';
            }

            if ($definition['input_type'] === FormTypes::SelectTimeInterval) {
                $select_array = (new SelectableLists($lang))->timeIntervalList($language_prefix, $section);
            } elseif ($definition['input_type'] === FormTypes::SelectCollection) {
                global $db, $mybb;

                $select_array = (new SelectableLists($lang, $db, $mybb))->collectionsList();

                if (isset($showcase_config['showcase_id']) &&
                    showcaseDataTableExists($showcase_config['showcase_id']) &&
                    (!isset($showcase_config['collection_id']) || !empty($showcase_config['collection_id']))) {
                    $options['class'] .= '" disabled="disabled';
                }
            } elseif ($definition['input_type'] === FormTypes::SelectTheme) {
                $select_array = (new SelectableLists($lang))->themesList();
            } elseif ($definition['input_type'] === FormTypes::SelectFilterField) {
                $select_array = (new SelectableLists($lang))->filterFieldsList();
            } elseif ($definition['input_type'] === FormTypes::SelectSortField) {
                $select_array = (new SelectableLists($lang))->sortFieldsEntryList();
            } elseif ($definition['input_type'] === FormTypes::SelectAttachmentsMainRender) {
                $select_array = (new SelectableLists($lang))->attachmentsMainRenderList();
            } elseif ($definition['input_type'] === FormTypes::SelectWatermarkLocation) {
                $select_array = (new SelectableLists($lang))->watermarkLocationsList();
            } elseif ($definition['input_type'] === FormTypes::SelectAttachmentsDisplayType) {
                $select_array = (new SelectableLists($lang))->attachmentsDisplayTypeList();
            } elseif ($definition['input_type'] === FormTypes::SelectTemplateSet) {
                $select_array = (new SelectableLists($lang))->templateSetsList((string)($input[$name] ?? $mybb->get_input($name)));
            } else {
                $select_array = [
                    0 => $lang->{$language_prefix . ($section ? '_' . $section : '') . '_rule_type_none'} ?? $language_prefix . ($section ? '_' . $section : '') . '_rule_type_none',
                    1 => $lang->{$language_prefix . ($section ? '_' . $section : '') . '_rule_type_main'} ?? $language_prefix . ($section ? '_' . $section : '') . '_rule_type_main',
                    2 => $lang->{$language_prefix . ($section ? '_' . $section : '') . '_rule_type_main_create_update'} ?? $language_prefix . ($section ? '_' . $section : '') . '_rule_type_main_create_update',
                    3 => $lang->{$language_prefix . ($section ? '_' . $section : '') . '_rule_type_link'} ?? $language_prefix . ($section ? '_' . $section : '') . '_rule_type_link',
                ];
            }

            $input_code .= $form->generate_select_box(
                "showcase[{$name}]",
                $select_array,
                [$input[$name] ?? $mybb->get_input($name)],
                $options
            );

            if ($display_extra_text) {
                $input_code .= '</div>';
            }

            break;
        case FormTypes::Text:
        case FormTypes::TextArea:
            if ($display_extra_text) {
                $input_code .= '<div class="group_settings_bit">';

                $input_code .= $lang->{$language_prefix . ($section ? '_' . $section : '') . '_' . $name} ?? $language_prefix . ($section ? '_' . $section : '') . '_' . $name;

                $input_code .= '<br /><small class="input">';

                $input_code .= $lang->{$language_prefix . ($section ? '_' . $section : '') . '_' . $name . '_description'} ?? $language_prefix . ($section ? '_' . $section : '') . '_' . $name . '_description';

                $input_code .= '</small><br />';
            }

            if ($definition['input_type'] === FormTypes::Text) {
                $input_code .= $form->generate_text_box(
                    "showcase[{$name}]",
                    $input[$name] ?? $mybb->get_input($name),
                    $options
                );
            } else {
                $input_code .= $form->generate_text_area(
                    "showcase[{$name}]",
                    $input[$name] ?? $mybb->get_input($name),
                    $options
                );
            }

            if ($display_extra_text) {
                $input_code .= '</div>';
            }

            break;
        case FormTypes::Number:
            if ($display_extra_text) {
                $input_code .= '<div class="group_settings_bit">';

                $input_code .= $lang->{$language_prefix . ($section ? '_' . $section : '') . '_' . $name} ?? $language_prefix . ($section ? '_' . $section : '') . '_' . $name;

                if (!empty($definition['highest'])) {
                    $input_code .= $lang->{$language_prefix . ($section ? '_' . $section : '') . '_highest'} ?? $language_prefix . ($section ? '_' . $section : '') . '_highest';
                } elseif (!empty($definition['lowest'])) {
                    $input_code .= $lang->{$language_prefix . ($section ? '_' . $section : '') . '_lowest'} ?? $language_prefix . ($section ? '_' . $section : '') . '_lowest';
                }

                if (!empty($definition['zero_unlimited'])) {
                    $input_code .= $lang->{$language_prefix . ($section ? '_' . $section : '') . '_zero_unlimited'} ?? $language_prefix . ($section ? '_' . $section : '') . '_zero_unlimited';
                }

                $input_code .= '<br /><small class="input">';

                $input_code .= $lang->{$language_prefix . ($section ? '_' . $section : '') . '_' . $name . '_description'} ?? $language_prefix . ($section ? '_' . $section : '') . '_' . $name . '_description';

                $input_code .= '</small><br />';
            }

            $input_code .= $form->generate_numeric_field(
                "showcase[{$name}]",
                $input[$name] ?? $mybb->get_input($name),
                $options
            );

            if ($display_extra_text) {
                $input_code .= '</div>';
            }

            break;
        case FormTypes::YesNo:
            if ($display_extra_text) {
                $input_code .= '<div class="group_settings_bit">';

                $input_code .= $lang->{$language_prefix . ($section ? '_' . $section : '') . '_' . $name} ?? $language_prefix . ($section ? '_' . $section : '') . '_' . $name;

                $input_code .= '<br /><small class="input">';

                $input_code .= $lang->{$language_prefix . ($section ? '_' . $section : '') . '_' . $name . '_description'} ?? $language_prefix . ($section ? '_' . $section : '') . '_' . $name . '_description';

                $input_code .= '</small><br />';
            }

            $input_code .= $form->generate_yes_no_radio(
                "showcase[{$name}]",
                $input[$name] ?? $mybb->get_input($name, MyBB::INPUT_INT),
                $options
            );

            if ($display_extra_text) {
                $input_code .= '</div>';
            }

            break;
        case FormTypes::Select:
            if ($display_extra_text) {
                $input_code .= '<div class="group_settings_bit">';

                $input_code .= $lang->{$language_prefix . ($section ? '_' . $section : '') . '_' . $name} ?? $language_prefix . ($section ? '_' . $section : '') . '_' . $name;

                $input_code .= '<br /><small class="input">';

                $input_code .= $lang->{$language_prefix . ($section ? '_' . $section : '') . '_' . $name . '_description'} ?? $language_prefix . ($section ? '_' . $section : '') . '_' . $name . '_description';

                $input_code .= '</small><br />';
            }

            $input_code .= $form->generate_select_box(
                "showcase[{$name}]",
                isset($definition['form_function']) ? $definition['form_function']() : $definition['form_array'],
                [$input[$name] ?? $mybb->get_input($name, MyBB::INPUT_INT)],
                $options
            );

            if ($display_extra_text) {
                $input_code .= '</div>';
            }

            break;
        case FormTypes::Checkbox:
            $input_code .= '<div class="user_settings_bit">';

            $options['checked'] = $input[$name] ?? $mybb->get_input($name, MyBB::INPUT_INT);

            $input_code .= $form->generate_check_box(
                "showcase[{$name}]",
                1,
                $lang->{$language_prefix . ($section ? '_' . $section : '') . '_' . $name} ?? $language_prefix . ($section ? '_' . $section : '') . '_' . $name,
                $options
            );

            $input_code .= '</div>';

            break;
        default:
            break;
    }

    return $input_code;
}