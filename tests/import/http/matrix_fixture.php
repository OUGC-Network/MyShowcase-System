<?php
/**
 * Board-side steps of the access matrix (tests/import/http/access_matrix.py), run from the board root:
 *   php <this file> setup <users_file> <gallery_uploads_rel>   the matrix users and groups, the global permission rows of
 *                                                               every group, the moderator row, the attachment types and the
 *                                                               uploads folder (DATA of the test, not the plugin's code)
 *   php <this file> type-groups <excluded_group_title>         the groups of the PNG and ZIP types: every group but the
 *                                                               excluded one
 *   php <this file> rebuild                                    rebuilds the plugin's caches after a change made with SQL
 *
 * Output is one JSON line. Nothing here is the plugin's code path: entries and attachments are made by the test through
 * the forum (the form and the attachment upload), and the state changes of the matrix are made with SQL by the test.
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

/** A usergroup of the matrix: a copy of the Registered group under its own title (created once). */
$matrixGroup = static function (string $title) use ($db, $groupId, $cache): int {
    $gid = $groupId($title);

    if ($gid) {
        return $gid;
    }

    $registered = array_filter($db->fetch_array($db->simple_select('usergroups', '*', "gid='2'")), 'is_string', ARRAY_FILTER_USE_KEY);
    unset($registered['gid']);
    $registered['title'] = $title;
    $registered['type'] = 2;
    $gid = (int)$db->insert_query('usergroups', array_map(static fn($v) => $db->escape_string((string)$v), $registered));
    $cache->update_usergroups();

    return $gid;
};

/**
 * The global (showcase_id 0) permission row of a group: the listed permissions are 1, the others 0. A name that is not a
 * column of the permission table is an error (a silently ignored name would make the matrix test the wrong thing).
 *
 * @param string[] $columns
 */
$grantRow = static function (int $group, array $columns) use ($db): void {
    $known = [];

    foreach (TABLES_DATA['myshowcase_permissions'] as $column => $definition) {
        if (is_array($definition) && isset($definition['type']) && empty($definition['auto_increment'])) {
            $known[$column] = true;
        }
    }

    foreach ($columns as $column) {
        if (!isset($known[$column])) {
            fwrite(STDERR, "unknown permission column: {$column}\n");

            exit(1);
        }
    }

    $row = ['showcase_id' => 0, 'group_id' => $group];

    foreach (array_keys($known) as $column) {
        if (in_array($column, ['showcase_id', 'group_id', 'moderator_id', 'user_id'], true)) {
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

        $uploads_rel = $argv[3];

        $nodl = $matrixGroup('Matrix no download');
        $excluded = $matrixGroup('Matrix excluded');
        $moderator = $matrixGroup('Matrix moderator');
        $nomain = $matrixGroup('Matrix no main');

        $users = [];

        $memberships = ['owner' => 2, 'member2' => 2, 'nodl' => $nodl, 'excluded' => $excluded, 'modr' => $moderator, 'nomain' => $nomain];

        foreach ($memberships as $username => $group) {
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
        // MyBB's error log records every error and exception of the requests (the matrix reads it on a failure).
        $db->update_query('settings', ['value' => 'log'], "name='errorlogmedium'");
        $db->update_query('settings', ['value' => $db->escape_string(MYBB_ROOT . 'error.log')], "name='errorloglocation'");
        $db->update_query('settings', ['value' => E_ALL], "name='errortypemedium'");
        rebuild_settings();

        file_put_contents($users_file, json_encode($users));
        chmod($users_file, 0600);

        // Every global permission row is written here: the column defaults are not relied on.
        $db->delete_query('myshowcase_permissions');

        $registered = [
            'main_can_view', 'entries_can_view', 'comments_can_view', 'attachments_can_view', 'can_search_entries',
            'entries_can_post', 'entries_can_update', 'entries_can_import', 'entries_can_export',
            'attachments_can_upload', 'attachments_can_download', 'entries_can_view_unapproved', 'entries_can_view_deleted',
        ];

        $grantRow(1, ['main_can_view', 'entries_can_view', 'comments_can_view', 'attachments_can_view', 'can_search_entries']);
        $grantRow(2, $registered);
        $grantRow($nodl, array_values(array_diff($registered, ['attachments_can_download'])));
        $grantRow($excluded, $registered);
        $grantRow($nomain, array_values(array_diff($registered, ['main_can_view'])));
        $grantRow($moderator, array_merge($registered, ['attachments_can_view_unapproved']));

        // The moderator of the gallery (the moderators row the ACP writes for a moderator of a showcase).
        $gallery = (int)$db->fetch_field($db->simple_select('myshowcase_config', 'showcase_id', "route_identifier='gallery'"), 'showcase_id');
        $downloads = (int)$db->fetch_field($db->simple_select('myshowcase_config', 'showcase_id', "route_identifier='downloads'"), 'showcase_id');
        $db->insert_query('myshowcase_moderators', ['showcase_id' => $gallery, 'user_id' => $users['modr']['uid'], 'group_id' => 0]);

        // The uploads folder and the attachment types: PNG (gallery) and ZIP (downloads), every group but the excluded one.
        $uploads = rtrim(MYBB_ROOT, '/') . '/' . $uploads_rel;

        if (!is_dir($uploads)) {
            mkdir($uploads, 0777, true);
        }

        chmod($uploads, 0777);

        $db->update_query('myshowcase_config', ['attachments_uploads_path' => $db->escape_string($uploads), 'import_export_flood_seconds' => 0], "showcase_id IN ({$gallery},{$downloads})");

        $png_row = ['name' => 'PNG Image', 'mimetype' => 'image/png', 'extension' => 'png', 'maxsize' => 10240,
                    'icon' => 'images/attachtypes/image.png', 'enabled' => 1, 'forcedownload' => 0, 'avatarfile' => 0,
                    'myshowcase_ids' => (string)$gallery];
        $png = $db->fetch_field($db->simple_select('attachtypes', 'atid', "extension='png'"), 'atid');

        if ($png) {
            $db->update_query('attachtypes', $png_row, "atid='" . (int)$png . "'");
        } else {
            $db->insert_query('attachtypes', $png_row);
        }

        $db->update_query('attachtypes', ['maxsize' => 51200, 'myshowcase_ids' => (string)$downloads, 'enabled' => 1], "extension='zip'");

        $rebuild();

        $out(['users' => $users, 'groups' => ['nodl' => $nodl, 'excluded' => $excluded, 'moderator' => $moderator, 'nomain' => $nomain],
              'gallery' => $gallery, 'downloads' => $downloads, 'uploads' => $uploads]);
        break;

    case 'type-groups':
        // The groups allowed to get the PNG and ZIP types: all the matrix groups, the guests and the registered group, but
        // not the excluded group. Written as a list of group ids (MyBB's attachtypes.groups).
        $excluded = $groupId($argv[2]);

        $groups = [];

        $result = $db->simple_select('usergroups', 'gid', '', ['order_by' => 'gid']);

        while ($row = $db->fetch_array($result)) {
            if ((int)$row['gid'] !== $excluded) {
                $groups[] = (int)$row['gid'];
            }
        }

        $list = implode(',', $groups);

        $db->update_query('attachtypes', ['groups' => $list], "extension IN ('png','zip')");

        $rebuild();

        $out(['type_groups' => $list, 'excluded' => $excluded]);
        break;

    case 'rebuild':
        $rebuild();

        $out(['rebuilt' => true]);
        break;

    default:
        fwrite(STDERR, "unknown command\n");

        exit(2);
}
