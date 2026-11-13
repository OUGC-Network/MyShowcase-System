<?php
/**
 * Board-side steps of the import suite, run from the board root:  php <this file> <command> [args]
 *
 *   setup <users_file>    the test users (generated passwords are written to <users_file>), a group that may export
 *                         but not import, a group whose new entries wait for approval, and the global permission rows
 *                         of those groups (every other permission row is deleted)
 *   permission <group_title> <column> <value>   change one global permission of a group, and rebuild the cache
 *   group-id <title>      prints the id of a usergroup
 *   count <table>         prints the number of rows of a table (MyShowcase or MyBB table, without the prefix)
 *   showcase-setting <route> <column> <value>   change one column of a showcase's config row, and rebuild the cache
 *   db-check <route> add|drop   a CHECK constraint on the entries table of a showcase (MySQL 8.0.16 or later; a trigger on
 *                               SQLite): entries whose subject starts with "BOOM" are refused by the database, not by the validator
 *
 * Output is one JSON line. Nothing here is the plugin's own code path for entries: entries are created through the forum
 * form by the suite itself.
 */

declare(strict_types=1);

define('IN_MYBB', 1);
define('IN_ADMINCP', 1);
define('NO_ONLINE', 1);

$_SERVER['REMOTE_ADDR'] ??= '127.0.0.1';
$_SERVER['QUERY_STRING'] ??= '';

require './inc/init.php';

$mybb->user = ['uid' => 0, 'usergroup' => 1, 'additionalgroups' => '', 'displaygroup' => 1, 'username' => ''];

use MyShowcase\Enum\CacheDataTypes;
use MyShowcase\Service\CacheManager;

use const MyShowcase\Plugin\Core\TABLES_DATA;

require_once MYBB_ROOT . 'inc/datahandlers/user.php';

$cacheManager = new CacheManager($db, $cache);

$out = static fn(mixed $value) => print(json_encode($value, JSON_UNESCAPED_SLASHES) . "\n");

$rebuild = static function () use ($cacheManager): void {
    foreach ([CacheDataTypes::Config, CacheDataTypes::Permissions, CacheDataTypes::Fields, CacheDataTypes::FieldData] as $type) {
        $cacheManager->update($type);
    }
};

$groupId = static function (string $title) use ($db): int {
    return (int)$db->fetch_field($db->simple_select('usergroups', 'gid', "title='" . $db->escape_string($title) . "'"), 'gid');
};

/**
 * The global (showcase_id 0) permission row of a group, with the given permissions set and the others left at 0.
 *
 * @param string[] $columns
 */
$grantRow = static function (int $group, array $columns) use ($db): void {
    $row = ['showcase_id' => 0, 'group_id' => $group];

    foreach (TABLES_DATA['myshowcase_permissions'] as $column => $definition) {
        if (!is_array($definition) || !isset($definition['type']) || !empty($definition['auto_increment']) ||
            in_array($column, ['showcase_id', 'group_id', 'moderator_id', 'user_id'], true)) {
            continue;
        }

        $row[$column] = in_array($column, $columns, true) ? 1 : 0;
    }

    $db->insert_query('myshowcase_permissions', $row);
};

$switch = $argv[1] ?? '';

switch ($switch) {
    case 'setup':
        $users_file = $argv[2];

        // A group that may export and not import: the two permissions are independent.
        $exporters = $groupId('Import suite exporters');

        if (!$exporters) {
            $registered = array_filter($db->fetch_array($db->simple_select('usergroups', '*', "gid='2'")), 'is_string', ARRAY_FILTER_USE_KEY);
            unset($registered['gid']);
            $registered['title'] = 'Import suite exporters';
            $registered['type'] = 2;
            $exporters = (int)$db->insert_query('usergroups', array_map(static fn($v) => $db->escape_string((string)$v), $registered));
            $cache->update_usergroups();
        }

        // A group whose new entries wait for approval.
        $moderated = $groupId('Import suite moderated');

        if (!$moderated) {
            $registered = array_filter($db->fetch_array($db->simple_select('usergroups', '*', "gid='2'")), 'is_string', ARRAY_FILTER_USE_KEY);
            unset($registered['gid']);
            $registered['title'] = 'Import suite moderated';
            $registered['type'] = 2;
            $moderated = (int)$db->insert_query('usergroups', array_map(static fn($v) => $db->escape_string((string)$v), $registered));
            $cache->update_usergroups();
        }

        $users = [];

        foreach (['member1' => 2, 'member2' => 2, 'exporter' => $exporters, 'modq1' => $moderated] as $username => $group) {
            $password = bin2hex(random_bytes(10));

            $existing = get_user_by_username($username);

            if ($existing) {
                $handler = new UserDataHandler('update');
                $handler->set_data(['uid' => (int)$existing['uid'], 'password' => $password, 'password2' => $password, 'usergroup' => $group]);
                $handler->validate_user() && $handler->update_user();

                $uid = (int)$existing['uid'];
            } else {
                $handler = new UserDataHandler('insert');
                $handler->set_data([
                    'username' => $username, 'password' => $password, 'password2' => $password,
                    'email' => "{$username}@example.localhost", 'email2' => "{$username}@example.localhost",
                    'usergroup' => $group, 'regip' => '127.0.0.1',
                ]);

                if (!$handler->validate_user()) {
                    fwrite(STDERR, "{$username}: " . implode('; ', $handler->get_friendly_errors()) . "\n");

                    exit(1);
                }

                $uid = (int)$handler->insert_user()['uid'];
            }

            $users[$username] = ['uid' => $uid, 'group' => $group, 'password' => $password];
        }

        // The showcases are switched on, and MyBB's error log records every PHP error (the log check of the suite reads it).
        $db->update_query('myshowcase_config', ['is_enabled' => 1], '1=1');
        $db->update_query('settings', ['value' => 'log'], "name='errorlogmedium'");
        $db->update_query('settings', ['value' => $db->escape_string(MYBB_ROOT . 'error.log')], "name='errorloglocation'");
        $db->update_query('settings', ['value' => E_ALL], "name='errortypemedium'");
        rebuild_settings();

        file_put_contents($users_file, json_encode($users));
        chmod($users_file, 0600);

        // Every global permission row is written here: the column defaults are not relied on.
        $db->delete_query('myshowcase_permissions');

        $view = ['main_can_view', 'entries_can_view', 'comments_can_view', 'attachments_can_view', 'can_search_entries'];

        $grantRow(1, $view);
        $grantRow(2, array_merge($view, ['entries_can_post', 'entries_can_update', 'entries_can_export', 'entries_can_import']));
        $grantRow($exporters, array_merge($view, ['entries_can_post', 'entries_can_update', 'entries_can_export']));
        $grantRow($moderated, array_merge($view, ['entries_can_post', 'entries_can_update', 'entries_can_import', 'entries_moderate_new']));

        $rebuild();

        $out(['users' => array_keys($users), 'exporters' => $exporters, 'moderated' => $moderated]);
        break;

    case 'permission':
        [$title, $column, $value] = [$argv[2], $argv[3], $argv[4]];

        $gid = $groupId($title);

        $db->update_query('myshowcase_permissions', [$column => (int)$value], "group_id='{$gid}' AND showcase_id='0'");

        $rebuild();

        $out(['permission' => $column, 'group' => $gid, 'value' => (int)$value]);
        break;

    case 'group-id':
        $out(['gid' => $groupId($argv[2])]);
        break;

    case 'showcase-setting':
        $route = $argv[2];

        $db->update_query('myshowcase_config', [$argv[3] => $db->escape_string($argv[4])], "route_identifier='" . $db->escape_string($route) . "'");

        $rebuild();

        $out(['showcase' => $route, $argv[3] => $argv[4]]);
        break;

    case 'db-check':
        $table = 'myshowcase_data' . (int)$db->fetch_field($db->simple_select('myshowcase_config', 'showcase_id', "route_identifier='" . $db->escape_string($argv[2]) . "'"), 'showcase_id');

        // SQLite cannot add a CHECK constraint to an existing table: a trigger refuses the same rows instead.
        if ($db->type === 'sqlite') {
            if ($argv[3] === 'add') {
                $db->write_query("CREATE TRIGGER import_test_no_boom BEFORE INSERT ON {$db->table_prefix}{$table} WHEN NEW.subject LIKE 'BOOM%' BEGIN SELECT RAISE(ABORT, 'import test refusal'); END");
            } else {
                $db->write_query('DROP TRIGGER import_test_no_boom');
            }
        } elseif ($argv[3] === 'add') {
            $db->write_query("ALTER TABLE {$db->table_prefix}{$table} ADD CONSTRAINT import_test_no_boom CHECK (subject NOT LIKE 'BOOM%')");
        } else {
            $db->write_query("ALTER TABLE {$db->table_prefix}{$table} DROP CHECK import_test_no_boom");
        }

        $out(['check' => $argv[3]]);
        break;

    case 'count':
        $table = $argv[2];

        $out(['count' => (int)$db->fetch_field($db->simple_select($table, 'COUNT(*) AS c'), 'c')]);
        break;

    default:
        fwrite(STDERR, "unknown command\n");

        exit(2);
}
