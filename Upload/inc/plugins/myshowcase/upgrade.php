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

use MyShowcase\System\UserPermissions;

use function MyShowcase\Plugin\Functions\getTemplatesList;

if (!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.');
}

global $cache;

/*
* Code to upgrade cache name to new one
* after move from PavementSucks.com to CommunityForums.com
*/

$cpcom_plugins = $cache->read('cpcom_plugins');

$pscom_plugins = $cache->read('pscom_plugins');
if ($pscom_plugins['versions']['myshowcase']) {
    $cpcom_plugins['versions']['myshowcase'] = $pscom_plugins['versions']['myshowcase'];
    $cache->update('cpcom_plugins', $cpcom_plugins);

    unset($pscom_plugins['versions']['myshowcase']);
    $cache->update('pscom_plugins', $pscom_plugins);
}


/*
* Upgrade code that occurs prior to table installation
*
*/
function myshowcase_upgrade_install_pre_table(array $need_upgrade): bool
{
//	if(is_array($need_upgrade) )
//	{
    //save resources,don't load globals unless doing an upgrade
//       global $db, $mybb, $config, $lang, $cache, $lang;
    //    }

    return true;
}

/*
* Upgrade code that occurs after table installation
*
*/
function myshowcase_upgrade_install_post_table(array $need_upgrade): bool
{
//	if(is_array($need_upgrade) )
//	{
    //save resources,don't load globals unless doing an upgrade
//        global $db, $mybb, $config;
//   }

    return true;
}

/*
* Upgrade code that occurs after template installation
*
*/
function myshowcase_upgrade_install_post_template(array $need_upgrade): bool
{
//	if(is_array($need_upgrade) )
//	{
    //save resources,don't load globals unless doing an upgrade
//        global $db, $mybb, $config;
//   }

    return true;
}

/*
* Upgrade code that occurs at activation
*
*/
function myshowcase_upgrade_activate(array $need_upgrade): bool
{
    if (is_array($need_upgrade)) {
        //save resources,don't load globals unless doing an upgrade
        global $db, $mybb, $config, $lang, $cache, $plugins;

        //make template corrections
        $myshowcase_templates = getTemplatesList();

        if (version_compare($need_upgrade['prev'], '2.1.0', '<')) {
            //add new field for random portal option
            if (!$db->field_exists('attachments_portal_build_widget', 'myshowcase_config')) {
                $query = 'ALTER TABLE ' . TABLE_PREFIX . 'myshowcase_config ADD `attachments_portal_build_widget` INT(1) DEFAULT \'0\'';
                $db->write_query($query);
            }

            $db->insert_query('templates', $insert_array);

            $db->insert_query('templates', $insert_array);

            //insert missing template
            $db->delete_query('templates', 'title=\'myshowcase_view_data_3\' and sid=-2');
            $insert_array = [
                'title' => 'myshowcase_view_data_3',
                'template' => $db->escape_string($myshowcase_templates['myshowcase_view_data_3']),
                'sid' => -2,
                'version' => 1600,
                'dateline' => TIME_NOW
            ];

            $db->insert_query('templates', $insert_array);
        }
        if (version_compare($need_upgrade['prev'], '2.2.0', '<')) {
            //increase size of comment field
            $newdef = 'TEXT ' . $db->build_create_table_collation() . ' NULL DEFAULT NULL';
            $db->modify_column('myshowcase_comments', 'comment', $newdef);

            //add prune time support
            if (!$db->field_exists('prune_time', 'myshowcase_config')) {
                $newdef = 'VARCHAR( 10 ) NOT NULL DEFAULT 0 AFTER `enabled`';
                $db->add_column('myshowcase_config', 'prune_time', $newdef);
            }

            //add smilie support
            if (!$db->field_exists('parser_allow_smilies', 'myshowcase_config')) {
                $newdef = 'SMALLINT(1) NOT NULL DEFAULT 0 AFTER `enabled`';
                $db->add_column('myshowcase_config', 'parser_allow_smilies', $newdef);
            }

            //add bbcode support
            if (!$db->field_exists('parser_allow_mycode', 'myshowcase_config')) {
                $newdef = 'SMALLINT(1) NOT NULL DEFAULT 0 AFTER `enabled`';
                $db->add_column('myshowcase_config', 'parser_allow_mycode', $newdef);
            }

            //add html support
            if (!$db->field_exists('parser_allow_html', 'myshowcase_config')) {
                $newdef = 'SMALLINT(1) NOT NULL DEFAULT 0 AFTER `enabled`';
                $db->add_column('myshowcase_config', 'parser_allow_html', $newdef);
            }

            //add parse setting
            if (!$db->field_exists('parse', 'myshowcase_fields')) {
                $newdef = 'SMALLINT(1) NOT NULL DEFAULT 0 AFTER `enabled`';
                $db->add_column('myshowcase_fields', 'parse', $newdef);
            }

            //add php format setting
            if (!$db->field_exists('format', 'myshowcase_fields')) {
                $newdef = 'VARCHAR(10) NOT NULL DEFAULT \'no\' AFTER `enabled`';
                $db->add_column('myshowcase_fields', 'format', $newdef);
            }

            //insert missing/new template
            $insert_array = [
                'title' => 'myshowcase_list_message',
                'template' => $db->escape_string($myshowcase_templates['myshowcase_list_message']),
                'sid' => -2,
                'version' => 1600,
                'dateline' => TIME_NOW
            ];

            $db->insert_query('templates', $insert_array);

            //replace myshowcase_top template
            $db->delete_query('templates', 'title=\'myshowcase_top\' and sid=-2');
            $insert_array = [
                'title' => 'myshowcase_top',
                'template' => $db->escape_string($myshowcase_templates['myshowcase_top']),
                'sid' => -2,
                'version' => 1600,
                'dateline' => TIME_NOW
            ];

            $db->insert_query('templates', $insert_array);

            //replace myshowcase_view_attachments_image template
            $db->delete_query('templates', 'title=\'myshowcase_view_attachment_image\' and sid=-2');
            $db->delete_query('templates', 'title=\'myshowcase_view_attachments_image\' and sid=-2');
            $insert_array = [
                'title' => 'myshowcase_view_attachments_image',
                'template' => $db->escape_string($myshowcase_templates['myshowcase_view_attachments_image']),
                'sid' => -2,
                'version' => 1600,
                'dateline' => TIME_NOW
            ];

            $db->insert_query('templates', $insert_array);

            //replace myshowcase_view template
            $db->delete_query('templates', 'title=\'myshowcase_view\' and sid=-2');
            $insert_array = [
                'title' => 'myshowcase_view',
                'template' => $db->escape_string($myshowcase_templates['myshowcase_view']),
                'sid' => -2,
                'version' => 1600,
                'dateline' => TIME_NOW
            ];

            $db->insert_query('templates', $insert_array);

            //replace myshowcase_view_admin template
            $db->delete_query('templates', 'title=\'myshowcase_view_admin\' and sid=-2');
            $insert_array = [
                'title' => 'myshowcase_view',
                'template' => $db->escape_string($myshowcase_templates['myshowcase_view_admin']),
                'sid' => -2,
                'version' => 1600,
                'dateline' => TIME_NOW
            ];

            $db->insert_query('templates', $insert_array);

            //add task
            $query = $db->simple_select('tasks', 'tid', "title='MyShowcase'");
            if ($db->num_rows($query) == 0) {
                include('../inc/functions_task.php');

                $new_task = [
                    'title' => $db->escape_string('MyShowcase'),
                    'description' => $db->escape_string('Prunes showcases where enabled.'),
                    'file' => $db->escape_string('myshowcase'),
                    'minute' => $db->escape_string('9'),
                    'hour' => $db->escape_string('4'),
                    'day' => $db->escape_string('*'),
                    'month' => $db->escape_string('*'),
                    'weekday' => $db->escape_string('*'),
                    'enabled' => 1,
                    'logging' => 1
                ];

                $new_task['nextrun'] = fetch_next_run($new_task);
                $tid = $db->insert_query('tasks', $new_task);
                $plugins->run_hooks('admin_tools_tasks_add_commit');
                $cache->update_tasks();
            }
        }

        if (version_compare($need_upgrade['prev'], '2.2.1', '<')) {
            //fix format field
            if (!$db->field_exists('format', 'myshowcase_fields')) {
                $newdef = 'VARCHAR(10) NOT NULL DEFAULT \'no\' AFTER `enabled`';
                $db->add_column('myshowcase_fields', 'format', $newdef);
            }
        }

        if (version_compare($need_upgrade['prev'], '2.3.0', '<')) {
            //replace myshowcase_view_admin template
            $db->delete_query('templates', 'title=\'myshowcase_view_admin\' and sid=-2');
            $insert_array = [
                'title' => 'myshowcase_view_admin',
                'template' => $db->escape_string($myshowcase_templates['myshowcase_view_admin']),
                'sid' => -2,
                'version' => 1600,
                'dateline' => TIME_NOW
            ];

            $db->insert_query('templates', $insert_array);

            //replace myshowcase_top template
            $db->delete_query('templates', 'title=\'myshowcase_top\' and sid=-2');
            $insert_array = [
                'title' => 'myshowcase_top',
                'template' => $db->escape_string($myshowcase_templates['myshowcase_top']),
                'sid' => -2,
                'version' => 1600,
                'dateline' => TIME_NOW
            ];

            $db->insert_query('templates', $insert_array);

            //insert myshowcase_js_header template
            $insert_array = [
                'title' => 'myshowcase_js_header',
                'template' => $db->escape_string($myshowcase_templates['myshowcase_js_header']),
                'sid' => -2,
                'version' => 1600,
                'dateline' => TIME_NOW
            ];

            $db->insert_query('templates', $insert_array);

            //insert myshowcase_field_date template
            $insert_array = [
                'title' => 'myshowcase_field_date',
                'template' => $db->escape_string($myshowcase_templates['myshowcase_field_date']),
                'sid' => -2,
                'version' => 1600,
                'dateline' => TIME_NOW
            ];

            $db->insert_query('templates', $insert_array);
        }

        if (version_compare($need_upgrade['prev'], '2.4.0', '<')) {
            //set existing templates to version 0 so find updated works
            $template_list = implode("','", array_keys($myshowcase_templates));
            $db->update_query('templates', ['version' => 0], "title in ('" . $template_list . "') and sid != -2");
            $db->update_query('templates', ['version' => 0], "title in ('" . $template_list . "') and sid = -2");

            //replace myshowcase_top template
            $db->delete_query('templates', 'title=\'myshowcase_top\' and sid=-2');
            $insert_array = [
                'title' => 'myshowcase_top',
                'template' => $db->escape_string($myshowcase_templates['myshowcase_top']),
                'sid' => -2,
                'version' => 1600,
                'dateline' => TIME_NOW
            ];

            $db->insert_query('templates', $insert_array);

            //replace myshowcase_view_attachments_image template
            $db->delete_query('templates', 'title=\'myshowcase_view_attachments_image\' and sid=-2');
            $insert_array = [
                'title' => 'myshowcase_view_attachments_image',
                'template' => $db->escape_string($myshowcase_templates['myshowcase_view_attachments_image']),
                'sid' => -2,
                'version' => 1600,
                'dateline' => TIME_NOW
            ];

            $db->insert_query('templates', $insert_array);

            //add watermark fields
            if (!$db->field_exists(UserPermissions::CanWaterMarkAttachments, 'myshowcase_permissions')) {
                $newdef = 'SMALLINT(1) NOT NULL DEFAULT 0 AFTER `cansearch`';
                $db->add_column(
                    'myshowcase_permissions',
                    UserPermissions::CanWaterMarkAttachments,
                    $newdef
                );
            }

            if (!$db->field_exists('attachments_watermark_file', 'myshowcase_config')) {
                $newdef = 'VARCHAR(50) NULL AFTER `attachments_uploads_path`';
                $db->add_column('myshowcase_config', 'attachments_watermark_file', $newdef);
            }

            if (!$db->field_exists('attachments_watermark_location', 'myshowcase_config')) {
                $newdef = 'VARCHAR(12) NOT NULL DEFAULT \'lower-right\' AFTER `attachments_watermark_file`';
                $db->add_column('myshowcase_config', 'attachments_watermark_location', $newdef);
            }

            //add myshowcase_watermark template
            $db->delete_query('templates', 'title=\'myshowcase_watermark\' and sid=-2');
            $insert_array = [
                'title' => 'myshowcase_watermark',
                'template' => $db->escape_string($myshowcase_templates['myshowcase_watermark']),
                'sid' => -2,
                'version' => 1600,
                'dateline' => TIME_NOW
            ];

            $db->insert_query('templates', $insert_array);

            //replace myshowcase_new_attachments template
            $db->delete_query('templates', 'title=\'myshowcase_new_attachments\' and sid=-2');
            $insert_array = [
                'title' => 'myshowcase_new_attachments',
                'template' => $db->escape_string($myshowcase_templates['myshowcase_new_attachments']),
                'sid' => -2,
                'version' => 1600,
                'dateline' => TIME_NOW
            ];

            $db->insert_query('templates', $insert_array);

            //replace myshowcase_new_attachments_attachment template
            $db->delete_query('templates', 'title=\'myshowcase_new_attachments_attachment\' and sid=-2');
            $insert_array = [
                'title' => 'myshowcase_new_attachments_attachment',
                'template' => $db->escape_string($myshowcase_templates['myshowcase_new_attachments_attachment']),
                'sid' => -2,
                'version' => 1600,
                'dateline' => TIME_NOW
            ];

            $db->insert_query('templates', $insert_array);

            //replace myshowcase_new_attachments_input template
            $db->delete_query('templates', 'title=\'myshowcase_new_attachments_input\' and sid=-2');
            $insert_array = [
                'title' => 'myshowcase_new_attachments_input',
                'template' => $db->escape_string($myshowcase_templates['myshowcase_new_attachments_input']),
                'sid' => -2,
                'version' => 1600,
                'dateline' => TIME_NOW
            ];

            $db->insert_query('templates', $insert_array);

            //replace myshowcase_bottom template
            $db->delete_query('templates', 'title=\'myshowcase_bottom\' and sid=-2');
            $insert_array = [
                'title' => 'myshowcase_bottom',
                'template' => $db->escape_string($myshowcase_templates['myshowcase_bottom']),
                'sid' => -2,
                'version' => 1600,
                'dateline' => TIME_NOW
            ];

            $db->insert_query('templates', $insert_array);
        }

        if (version_compare($need_upgrade['prev'], '2.5.0', '<')) {
            //set existing templates to version 0 so find updated works
            $template_list = implode("','", array_keys($myshowcase_templates));
            $db->update_query('templates', ['version' => 0], "title in ('" . $template_list . "') and sid != -2");
            $db->update_query('templates', ['version' => 0], "title in ('" . $template_list . "') and sid = -2");

            //replace myshowcase_attachment_view
            $db->delete_query('templates', 'title=\'myshowcase_attachment_view\' and sid=-2');
            $insert_array = [
                'title' => 'myshowcase_attachment_view',
                'template' => $db->escape_string($myshowcase_templates['myshowcase_attachment_view']),
                'sid' => -2,
                'version' => 1600,
                'dateline' => TIME_NOW
            ];

            $db->insert_query('templates', $insert_array);

            //replace myshowcase_inlinemod
            $db->delete_query('templates', 'title=\'myshowcase_inlinemod\' and sid=-2');
            $insert_array = [
                'title' => 'myshowcase_inlinemod',
                'template' => $db->escape_string($myshowcase_templates['myshowcase_inlinemod']),
                'sid' => -2,
                'version' => 1600,
                'dateline' => TIME_NOW
            ];

            $db->insert_query('templates', $insert_array);

            //replace myshowcase_top
            $db->delete_query('templates', 'title=\'myshowcase_top\' and sid=-2');
            $insert_array = [
                'title' => 'myshowcase_top',
                'template' => $db->escape_string($myshowcase_templates['myshowcase_top']),
                'sid' => -2,
                'version' => 1600,
                'dateline' => TIME_NOW
            ];

            $db->insert_query('templates', $insert_array);
        }
        if (version_compare($need_upgrade['prev'], '2.5.1', '<')) {
            //set existing templates to version 0 so find updated works
            $template_list = implode("','", array_keys($myshowcase_templates));
            $db->update_query('templates', ['version' => 0], "title in ('" . $template_list . "') and sid != -2");
            $db->update_query('templates', ['version' => 0], "title in ('" . $template_list . "') and sid = -2");

            //replace myshowcase_attachment_view
            $db->delete_query('templates', 'title=\'myshowcase_attachment_view\' and sid=-2');
            $insert_array = [
                'title' => 'myshowcase_attachment_view',
                'template' => $db->escape_string($myshowcase_templates['myshowcase_attachment_view']),
                'sid' => -2,
                'version' => 1600,
                'dateline' => TIME_NOW
            ];

            $db->insert_query('templates', $insert_array);

            //replace myshowcase_inlinemod
            $db->delete_query('templates', 'title=\'myshowcase_inlinemod\' and sid=-2');
            $insert_array = [
                'title' => 'myshowcase_inlinemod',
                'template' => $db->escape_string($myshowcase_templates['myshowcase_inlinemod']),
                'sid' => -2,
                'version' => 1600,
                'dateline' => TIME_NOW
            ];

            $db->insert_query('templates', $insert_array);

            //replace myshowcase_top
            $db->delete_query('templates', 'title=\'myshowcase_top\' and sid=-2');
            $insert_array = [
                'title' => 'myshowcase_top',
                'template' => $db->escape_string($myshowcase_templates['myshowcase_top']),
                'sid' => -2,
                'version' => 1600,
                'dateline' => TIME_NOW
            ];

            $db->insert_query('templates', $insert_array);
        }

        if (version_compare($need_upgrade['prev'], '2.5.2', '<')) {
            //replace myshowcase_attachment_view
            $db->delete_query('templates', 'title=\'myshowcase_attachment_view\' and sid=-2');
            $insert_array = [
                'title' => 'myshowcase_attachment_view',
                'template' => $db->escape_string($myshowcase_templates['myshowcase_attachment_view']),
                'sid' => -2,
                'version' => 1600,
                'dateline' => TIME_NOW
            ];

            $db->insert_query('templates', $insert_array);

            //replace myshowcase_inlinemod
            $db->delete_query('templates', 'title=\'myshowcase_inlinemod\' and sid=-2');
            $insert_array = [
                'title' => 'myshowcase_inlinemod',
                'template' => $db->escape_string($myshowcase_templates['myshowcase_inlinemod']),
                'sid' => -2,
                'version' => 1600,
                'dateline' => TIME_NOW
            ];

            $db->insert_query('templates', $insert_array);

            //replace myshowcase_top
            $db->delete_query('templates', 'title=\'myshowcase_top\' and sid=-2');
            $insert_array = [
                'title' => 'myshowcase_top',
                'template' => $db->escape_string($myshowcase_templates['myshowcase_top']),
                'sid' => -2,
                'version' => 1600,
                'dateline' => TIME_NOW
            ];

            $db->insert_query('templates', $insert_array);
        }
    }

    return true;
}