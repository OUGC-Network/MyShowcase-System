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

if (!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.');
}

global $lang, $cache, $db, $plugins;
global $page;

$page->add_breadcrumb_item($lang->myshowcase_admin_help, 'index.php?module=myshowcase-help');
$page->output_header($lang->myshowcase_admin_help);

//make sure plugin is installed and active
$plugin_cache = $cache->read('plugins');
if (!$db->table_exists('myshowcase_config') || !array_key_exists('myshowcase', $plugin_cache['active'])) {
    flash_message($lang->myshowcase_plugin_not_installed, 'error');
    admin_redirect('index.php?module=config-plugins');
}

$plugins->run_hooks('admin_myshowcase_help_begin');

$tabs = [
    'main' => 'Main',
    'summary' => 'Showcases',
    'fields' => 'Collections',
    'edit' => 'Edit Showcase',
    'cache' => 'Rebuild Cache',
    'other' => 'Other Items'
];

$page->output_tab_control($tabs);

echo "<div id=\"tab_main\">\n";
echo $lang->myshowcase_help_main;
echo "</div>\n";

echo "<div id=\"tab_summary\">\n";
echo $lang->myshowcase_help_summary;
echo "</div>\n";

echo "<div id=\"tab_fields\">\n";
echo $lang->myshowcase_help_fields;
echo "</div>\n";

echo "<div id=\"tab_edit\">\n";
echo $lang->myshowcase_help_edit;
echo "</div>\n";

echo "<div id=\"tab_cache\">\n";
echo $lang->myshowcase_help_cache;
echo "</div>\n";

echo "<div id=\"tab_other\">\n";
echo $lang->myshowcase_help_other;
echo "</div>\n";

$plugins->run_hooks('admin_myshowcase_help_end');

$showcase_info = myshowcase_info();
echo '<p /><small>' . $showcase_info['name'] . ' version ' . $showcase_info['version'] . ' &copy; 2006-' . COPY_YEAR . ' <a href="' . $showcase_info['website'] . '">' . $showcase_info['author'] . '</a>.</small>';
$page->output_footer();

//todo review hooks here