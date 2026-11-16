<?php
/**
 * Board-side steps of the field tests (tests/fields/http/run.py), run from the board root:
 *   php <this file> setup <users_file>                 the test users (generated passwords are written to <users_file>),
 *                                                      a group that may fill and view the restricted fields, and the global
 *                                                      permission rows of the groups (every other row is deleted)
 *   php <this file> attach <route> <uploads_rel>       the uploads folder and the PNG attachment type of one showcase (DATA)
 *   php <this file> gid <title>                        prints the id of a usergroup
 *   php <this file> rebuild                            rebuilds the plugin's caches after a change made with SQL
 *
 * Output is one JSON line. Nothing here is the plugin's code path: entries are made by the test through the forum form.
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
    foreach ([CacheDataTypes::Config, CacheDataTypes::Permissions, CacheDataTypes::Fields, CacheDataTypes::FieldData, CacheDataTypes::AttachmentTypes] as $type) {
        $cacheManager->update($type);
    }
};

$groupId = static function (string $title) use ($db): int {
    return (int)$db->fetch_field($db->simple_select('usergroups', 'gid', "title='" . $db->escape_string($title) . "'"), 'gid');
};

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

        $restricted = $groupId('FieldKit restricted');

        if (!$restricted) {
            $registered = array_filter($db->fetch_array($db->simple_select('usergroups', '*', "gid='2'")), 'is_string', ARRAY_FILTER_USE_KEY);
            unset($registered['gid']);
            $registered['title'] = 'FieldKit restricted';
            $registered['type'] = 2;
            $restricted = (int)$db->insert_query('usergroups', array_map(static fn($v) => $db->escape_string((string)$v), $registered));
            $cache->update_usergroups();
        }

        $users = [];

        // fkmoderator is in the Moderators group: its showcase row (case showcase-permissions) lets it delete entries of others
        $moderators = $groupId('Moderators');

        foreach (['fkmember' => 2, 'fkrestricted' => $restricted, 'fkmoderator' => $moderators] as $username => $group) {
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

        $db->update_query('myshowcase_config', ['is_enabled' => 1], '1=1');
        $db->update_query('settings', ['value' => 'log'], "name='errorlogmedium'");
        $db->update_query('settings', ['value' => $db->escape_string(MYBB_ROOT . 'error.log')], "name='errorloglocation'");
        $db->update_query('settings', ['value' => E_ALL], "name='errortypemedium'");
        rebuild_settings();

        file_put_contents($users_file, json_encode(['users' => $users, 'restricted' => $restricted]));
        chmod($users_file, 0600);

        $db->delete_query('myshowcase_permissions');

        $view = ['main_can_view', 'entries_can_view', 'comments_can_view', 'attachments_can_view', 'can_search_entries'];
        $grantRow(1, $view);
        $grantRow(2, array_merge($view, ['entries_can_post', 'entries_can_update', 'attachments_can_upload', 'attachments_can_download']));
        $grantRow($restricted, array_merge($view, ['entries_can_post', 'entries_can_update', 'attachments_can_upload', 'attachments_can_download']));

        $rebuild();

        $out(['restricted' => $restricted, 'users' => array_keys($users)]);
        break;

    case 'showcase-permissions':
        // The rows of one showcase: the same grants as the global rows, for the groups of this suite (the forum checks the
        // showcase's own rows first).
        $route = $argv[2];
        $showcase = (int)$db->fetch_field($db->simple_select('myshowcase_config', 'showcase_id', "route_identifier='" . $db->escape_string($route) . "'"), 'showcase_id');
        $restricted_gid = $groupId('FieldKit restricted');

        $db->update_query('myshowcase_config', ['is_enabled' => 1], "showcase_id='{$showcase}'");
        $db->delete_query('myshowcase_permissions', "showcase_id='{$showcase}'");

        $grantShowcaseRow = static function (int $showcase, int $group, array $columns) use ($db): void {
            $row = ['showcase_id' => $showcase, 'group_id' => $group];

            foreach (TABLES_DATA['myshowcase_permissions'] as $column => $definition) {
                if (!is_array($definition) || !isset($definition['type']) || !empty($definition['auto_increment']) ||
                    in_array($column, ['showcase_id', 'group_id', 'moderator_id', 'user_id'], true)) {
                    continue;
                }

                $row[$column] = in_array($column, $columns, true) ? 1 : 0;
            }

            $db->insert_query('myshowcase_permissions', $row);
        };

        $view = ['main_can_view', 'entries_can_view', 'comments_can_view', 'attachments_can_view', 'can_search_entries'];
        $grantShowcaseRow($showcase, 1, $view);
        $grantShowcaseRow($showcase, 2, array_merge($view, ['entries_can_post', 'entries_can_update', 'attachments_can_upload', 'attachments_can_download', 'entries_can_export', 'entries_can_import']));
        $grantShowcaseRow($showcase, $restricted_gid, array_merge($view, ['entries_can_post', 'entries_can_update', 'attachments_can_upload', 'attachments_can_download']));
        $grantShowcaseRow($showcase, $groupId('Moderators'), array_merge($view, ['entries_can_delete']));
        // The Moderators group is a moderator of this showcase (moderator type group, MODERATOR_TYPE_GROUP = 1): its members may
        // moderate entries of others when the group has the permission.
        $db->delete_query('myshowcase_moderators', "showcase_id='{$showcase}'");
        $db->insert_query('myshowcase_moderators', ['showcase_id' => $showcase, 'user_id' => $groupId('Moderators'), 'group_id' => 1]);

        $rebuild();

        $out(['showcase' => $showcase, 'rows' => 3]);
        break;

    case 'attach':
        $route = $argv[2];
        $uploads = rtrim(MYBB_ROOT, '/') . '/' . $argv[3];

        if (!is_dir($uploads)) {
            mkdir($uploads, 0777, true);
        }

        chmod($uploads, 0777);

        $showcase = (int)$db->fetch_field($db->simple_select('myshowcase_config', 'showcase_id', "route_identifier='" . $db->escape_string($route) . "'"), 'showcase_id');
        $db->update_query('myshowcase_config', ['attachments_uploads_path' => $db->escape_string($uploads), 'attachments_allow_in_entries' => 1], "showcase_id='{$showcase}'");

        $all_groups = [];
        $result = $db->simple_select('usergroups', 'gid', '', ['order_by' => 'gid']);

        while ($row = $db->fetch_array($result)) {
            $all_groups[] = (int)$row['gid'];
        }

        $png_row = ['name' => 'PNG Image', 'mimetype' => 'image/png', 'extension' => 'png', 'maxsize' => 10240,
                    'icon' => 'images/attachtypes/image.png', 'enabled' => 1, 'forcedownload' => 0, 'avatarfile' => 0,
                    'myshowcase_ids' => (string)$showcase, 'groups' => implode(',', $all_groups)];
        $png = $db->fetch_field($db->simple_select('attachtypes', 'atid', "extension='png'"), 'atid');

        if ($png) {
            $db->update_query('attachtypes', $png_row, "atid='" . (int)$png . "'");
        } else {
            $db->insert_query('attachtypes', $png_row);
        }

        $cache->update_attachtypes();
        $rebuild();

        $out(['showcase' => $showcase, 'uploads' => $uploads]);
        break;

    case 'external-setting':
        // The import of files from URLs (decision D51): test data for the round trip of the file fields.
        $db->update_query('settings', ['value' => (string)(int)$argv[2]], "name='myshowcase_import_external_files'");
        rebuild_settings();
        $out(['myshowcase_import_external_files' => (int)$argv[2]]);
        break;

    case 'gid':
        $out(['gid' => $groupId($argv[2])]);
        break;

    case 'bounded-types':
        // decision D124: the HTML types whose input has a min and a max, read from the code (FieldBoundUtil)
        $out(\MyShowcase\Util\FieldBoundUtil::BOUNDED_HTML_TYPES);
        break;
    case 'reserved-keys':
        // decision D152: the names a field key can not have, read from the code (the entries table's columns and the SQL words)
        $out(\MyShowcase\Service\FieldService::reservedFieldKeys());
        break;
    case 'rebuild':
        $rebuild();
        $out(['rebuilt' => true]);
        break;

    default:
        fwrite(STDERR, "unknown command\n");
        exit(2);
}
