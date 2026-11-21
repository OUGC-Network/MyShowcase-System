<?php

declare(strict_types=1);

/*
 * Board-side test data for fresh_install.py (decision D3). It creates users and a group, reads and writes the global permission
 * rows the way an ACP save does, inserts one approved entry with one attachment, and reports the defaults of TABLES_DATA. It is
 * run from the board root and is not part of the plugin. It never seeds a permission row itself: that is the plugin's job, and
 * the stage checks that it happened.
 *
 *   users <users_file>              members member1 (Registered) and mod1 (Moderators), with passwords written to the file
 *   group <title>                   a usergroup like Registered, created after the install; prints its gid
 *   user <name> <gid>               a user in the group; prints the uid
 *   rows                            the global permission rows (showcase_id 0), every column, ordered by group
 *   save-row <gid> <col> <value>    an ACP-like save of one global row: updates it, or inserts it with the defaults first
 *   delete-row <gid>                deletes the global row of a group (a group whose row is missing)
 *   setting <name> <value>          a plugin setting, rebuilt
 *   table-exists <table>           whether a table exists
 *   enable <route>...               switches the showcases on and rebuilds the caches
 *   defaults                        the default of every permission column (TABLES_DATA)
 *   entry <route> <subject>         one approved entry of the showcase, by user 1; prints its entry id, public id and slug
 *   attachment <route> <entry_id>   one PNG attachment of the entry, written to the uploads folder; prints its name
 *   types <route>...                the PNG attachment type for the showcases, and the uploads folder of each
 */

define('IN_MYBB', 1);
define('IN_ADMINCP', 1);
define('NO_ONLINE', 1);

$_SERVER['REMOTE_ADDR'] ??= '127.0.0.1';
$_SERVER['QUERY_STRING'] ??= '';

require './inc/init.php';

use const MyShowcase\Plugin\Core\TABLES_DATA;
use MyShowcase\Enum\CacheDataTypes;
use MyShowcase\Service\CacheManager;

global $db, $cache;

$command = $argv[1] ?? '';
$out = static fn(mixed $value) => print(json_encode($value, JSON_UNESCAPED_SLASHES) . "\n");
// The plugin's classes load only while the plugin is active: the commands that need them create the manager themselves.
$rebuild = static function () use ($db, $cache): void {
    $cacheManager = new CacheManager($db, $cache);
    foreach ([CacheDataTypes::Config, CacheDataTypes::Permissions, CacheDataTypes::AttachmentTypes, CacheDataTypes::Fields, CacheDataTypes::FieldData] as $type) {
        $cacheManager->update($type);
    }
};
$identifiers = ['permission_id', 'showcase_id', 'group_id', 'moderator_id', 'user_id'];
$permissionColumns = static function () use ($identifiers): array {
    $columns = [];
    foreach (TABLES_DATA['myshowcase_permissions'] as $name => $definition) {
        if (!in_array($name, $identifiers, true)) {
            $columns[$name] = $definition['default'] ?? 0;
        }
    }

    return $columns;
};
// The time columns: integers on SQLite, datetimes on MySQL and PostgreSQL.
$when = static fn() => str_contains((string)$db->type, 'sqlite') ? TIME_NOW : gmdate('Y-m-d H:i:s', TIME_NOW);
$showcaseId = static function (string $route) use ($db): int {
    return (int)$db->fetch_field($db->simple_select('myshowcase_config', 'showcase_id', "route_identifier='" . $db->escape_string($route) . "'"), 'showcase_id');
};

switch ($command) {
    case 'users':
        require_once MYBB_ROOT . 'inc/datahandlers/user.php';
        $users = [];
        foreach (['member1' => 2, 'mod1' => 3] as $username => $group) {
            $password = bin2hex(random_bytes(10));
            $handler = new UserDataHandler('insert');
            $handler->set_data(['username' => $username, 'password' => $password, 'password2' => $password,
                'email' => "{$username}@example.localhost", 'email2' => "{$username}@example.localhost",
                'usergroup' => $group, 'regip' => '127.0.0.1']);
            if (!$handler->validate_user()) {
                fwrite(STDERR, implode('; ', $handler->get_friendly_errors()) . "\n");
                exit(1);
            }
            $users[$username] = ['uid' => (int)$handler->insert_user()['uid'], 'group' => $group, 'password' => $password];
        }
        file_put_contents($argv[2], json_encode($users));
        chmod($argv[2], 0600);
        $out(['users' => array_keys($users)]);
        break;

    case 'group':
        $registered = $db->fetch_array($db->simple_select('usergroups', '*', "gid='2'"));
        $row = array_filter($registered, 'is_string', ARRAY_FILTER_USE_KEY);
        unset($row['gid']);
        $row['title'] = $argv[2];
        $gid = (int)$db->insert_query('usergroups', array_map(static fn($v) => $db->escape_string((string)$v), $row));
        $cache->update_usergroups();
        $out(['gid' => $gid]);
        break;

    case 'user':
        require_once MYBB_ROOT . 'inc/datahandlers/user.php';
        $password = bin2hex(random_bytes(10));
        $handler = new UserDataHandler('insert');
        $handler->set_data(['username' => $argv[2], 'password' => $password, 'password2' => $password,
            'email' => "{$argv[2]}@example.localhost", 'email2' => "{$argv[2]}@example.localhost",
            'usergroup' => (int)$argv[3], 'regip' => '127.0.0.1']);
        if (!$handler->validate_user()) {
            fwrite(STDERR, implode('; ', $handler->get_friendly_errors()) . "\n");
            exit(1);
        }
        $uid = (int)$handler->insert_user()['uid'];
        $out(['uid' => $uid, 'password' => $password]);
        break;

    case 'rows':
        $rows = [];
        $query = $db->simple_select('myshowcase_permissions', '*', "showcase_id='0'", ['order_by' => 'group_id']);
        while ($row = $db->fetch_array($query)) {
            $rows[(string)$row['group_id']] = array_filter($row, 'is_string', ARRAY_FILTER_USE_KEY);
        }
        $out($rows);
        break;

    case 'save-row':
        $gid = (int)$argv[2];
        $column = $argv[3];
        $value = (int)$argv[4];
        $existing = $db->fetch_field($db->simple_select('myshowcase_permissions', 'permission_id', "showcase_id='0' AND group_id='{$gid}'"), 'permission_id');
        if ($existing) {
            $db->update_query('myshowcase_permissions', [$column => $value], "permission_id='" . (int)$existing . "'");
        } else {
            $data = array_merge(['showcase_id' => 0, 'group_id' => $gid], $permissionColumns());
            $data[$column] = $value;
            $db->insert_query('myshowcase_permissions', $data);
        }
        $rebuild();
        $out(['saved' => $column, 'group' => $gid, 'value' => $value]);
        break;

    case 'delete-row':
        $db->delete_query('myshowcase_permissions', "showcase_id='0' AND group_id='" . (int)$argv[2] . "'");
        $out(['deleted' => (int)$argv[2]]);
        break;

    case 'setting':
        $db->update_query('settings', ['value' => $db->escape_string($argv[3])], "name='" . $db->escape_string($argv[2]) . "'");
        rebuild_settings();
        $out(['setting' => $argv[2], 'value' => $argv[3]]);
        break;

    case 'table-exists':
        $out(['table' => $argv[2], 'exists' => $db->table_exists($argv[2])]);
        break;

    case 'showcase-set':
        $db->update_query('myshowcase_config', [$argv[3] => $db->escape_string($argv[4])], "route_identifier='" . $db->escape_string($argv[2]) . "'");
        $rebuild();
        $out(['route' => $argv[2], $argv[3] => $argv[4]]);
        break;

    case 'wol':
        // the who's-online location of a showcase page, built by the plugin's hook (activity: list or view; entry id optional)
        if (getenv('URL_MODE_SEO') === '1') {
            $_SERVER['SEO_SUPPORT'] = 1;
        }
        if (getenv('URL_MODE_MARKER') === '1') {
            $_SERVER['SHOWCASE_SEO_SUPPORT'] = 1;
        }
        require_once MYBB_ROOT . 'inc/plugins/myshowcase/forum_hooks.php';
        $row = $db->fetch_array($db->simple_select('myshowcase_config', 'showcase_id, name, route_identifier', "route_identifier='" . $db->escape_string($argv[2]) . "'"));
        $activity = ['activity' => $argv[3] === 'view' ? 'myshowcase_main_table_head_view' : 'myshowcase_list', 'myshowcase_id' => (int)$row['showcase_id'],
            'myshowcase_name' => $row['name'], 'myshowcase_mainfile' => $row['route_identifier']];
        if ($argv[3] === 'view') {
            $activity['entry_id'] = (string)($argv[4] ?? '1');
        }
        $activity['location'] = '';
        $plugin = ['user_activity' => $activity, 'location_name' => ''];
        $plugin = \MyShowcase\Hooks\Forum\build_friendly_wol_location_end($plugin);
        $out(['location' => $plugin['location_name']]);
        break;

    case 'showcase-value':
        $out(['value' => (string)$db->fetch_field($db->simple_select('myshowcase_config', $argv[3], "route_identifier='" . $db->escape_string($argv[2]) . "'"), $argv[3])]);
        break;

    case 'enable':
        foreach (array_slice($argv, 2) as $route) {
            $db->update_query('myshowcase_config', ['is_enabled' => 1], "route_identifier='" . $db->escape_string($route) . "'");
        }
        $rebuild();
        $out(['enabled' => array_slice($argv, 2)]);
        break;

    case 'defaults':
        $out($permissionColumns());
        break;

    case 'entry':
        $route = $argv[2];
        $sid = $showcaseId($route);
        $public = substr(md5($argv[3] . microtime()), 0, 10);
        // the columns the collection requires (NOT NULL) get a value; a gallery entry's title is its subject
        $columns = [
            'public_id' => $public, 'entry_slug_custom' => 'fresh', 'subject' => $db->escape_string($argv[3]),
            'user_id' => 1, 'status' => 1, 'dateline' => $when(), 'update_dateline' => $when(), 'post_hash' => $public,
            'category' => 1,
        ];
        if ($route === 'gallery') {
            $columns += ['title' => $db->escape_string($argv[3]), 'image' => '', 'caption' => ''];
        }
        $query = $db->simple_select('myshowcase_fields', 'field_key, field_type', "collection_id=(SELECT collection_id FROM " . $db->table_prefix . "myshowcase_config WHERE showcase_id='{$sid}')");
        while ($field = $db->fetch_array($query)) {
            if (!isset($columns[$field['field_key']])) {
                $columns[$field['field_key']] = preg_match('/int/i', (string)$field['field_type']) ? 0 : '';
            }
        }
        $entryId = (int)$db->insert_query("myshowcase_data{$sid}", $columns);
        $out(['entry_id' => $entryId, 'public_id' => $public, 'slug' => 'fresh']);
        break;

    case 'file-url':
        // the public URL an export writes for an attachment (decision D238): the plain form, whatever the mode
        $route = $argv[2];
        $ctx = (new \MyShowcase\Util\BuildShowcase())->getShowcase($showcaseId($route))->context;
        $access = new \MyShowcase\Service\AttachmentAccess($ctx);
        $out(['url' => $access->publicUrl($argv[3], $argv[4])]);
        break;

    case 'own-url':
        // the attachment an import reads from a file URL of this board (decisions D52, D58), or null
        $route = $argv[2];
        $ctx = (new \MyShowcase\Util\BuildShowcase())->getShowcase($showcaseId($route))->context;
        $access = new \MyShowcase\Service\AttachmentAccess($ctx);
        $url = \MyShowcase\Util\ExternalUrl::parse($argv[3], $access->ownPorts($argv[3]));
        $own = $access->ownAttachment($url);
        $out(['attachment_id' => $own === null ? null : (int)$own['attachment_id']]);
        break;

    case 'comment':
        // one approved comment on an entry, by user 1, with the given message (default: a fixed text)
        $route = $argv[2];
        $sid = $showcaseId($route);
        $entryId = (int)$argv[3];
        $public = substr(md5('comment' . $entryId . microtime()), 0, 10);
        $id = (int)$db->insert_query('myshowcase_comments', [
            'public_id' => $public, 'showcase_id' => $sid, 'entry_id' => $entryId, 'post_hash' => $public, 'user_id' => 1,
            'username' => 'admin', 'message' => $db->escape_string($argv[4] ?? 'Url mode comment'), 'dateline' => $when(), 'update_dateline' => $when(),
            'status' => 1,
        ]);
        $out(['comment_id' => $id, 'public_id' => $public]);
        break;

    case 'attachment':
        $route = $argv[2];
        $sid = $showcaseId($route);
        $entryId = (int)$argv[3];
        $folder = rtrim((string)$db->fetch_field($db->simple_select('myshowcase_config', 'attachments_uploads_path', "showcase_id='{$sid}'"), 'attachments_uploads_path'), '/');
        if (!is_dir($folder)) {
            mkdir($folder, 0777, true);
        }
        $name = 'fresh-' . $entryId . '.png';
        // the field of a file field named by its key (the showcase's collection); 0 for an attachment of no field
        $fieldId = 0;
        if (isset($argv[7]) && $argv[7] !== '') {
            $collectionId = (int)$db->fetch_field($db->simple_select('myshowcase_config', 'collection_id', "showcase_id='{$sid}'"), 'collection_id');
            $fieldId = (int)$db->fetch_field($db->simple_select('myshowcase_fields', 'field_id', "collection_id='{$collectionId}' AND field_key='" . $db->escape_string($argv[7]) . "'"), 'field_id');
        }
        $stored = 'fresh_' . $entryId . '_' . bin2hex(random_bytes(4)) . '.attach';
        copy($argv[4], $folder . '/' . $stored);
        $public = substr(md5($stored), 0, 10);
        $id = (int)$db->insert_query('myshowcase_attachments', [
            'public_id' => $public, 'showcase_id' => $sid, 'entry_id' => $entryId, 'user_id' => (int)($argv[5] ?? 1), 'post_hash' => '',
            'file_name' => $stored, 'mime_type' => 'image/png', 'file_size' => filesize($folder . '/' . $stored),
            'attachment_name' => $name, 'status' => 1, 'dateline' => $when(), 'update_dateline' => $when(),
            'comment_id' => (int)($argv[6] ?? 0), 'thumbnail_name' => '', 'field_id' => $fieldId,
        ]);
        $out(['attachment_id' => $id, 'public_id' => $public, 'name' => $name]);
        break;

    case 'types':
        $ids = [];
        foreach (array_slice($argv, 2) as $route) {
            $ids[] = $showcaseId($route);
            $folder = MYBB_ROOT . 'uploads/myshowcase-fresh';
            if (!is_dir($folder)) {
                mkdir($folder, 0777, true);
            }
            $db->update_query('myshowcase_config', ['attachments_uploads_path' => $db->escape_string($folder)], "route_identifier='" . $db->escape_string($route) . "'");
        }
        $png = $db->fetch_field($db->simple_select('attachtypes', 'atid', "extension='png'"), 'atid');
        $row = ['name' => 'PNG Image', 'mimetype' => 'image/png', 'extension' => 'png', 'maxsize' => 10240,
            'icon' => 'images/attachtypes/image.png', 'enabled' => 1, 'forcedownload' => 0, 'groups' => '-1',
            'forums' => '-1', 'avatarfile' => 0, 'myshowcase_ids' => implode(',', $ids)];
        if ($png) {
            $db->update_query('attachtypes', $row, "atid='" . (int)$png . "'");
        } else {
            $db->insert_query('attachtypes', $row);
        }
        (new CacheManager($db, $cache))->update(CacheDataTypes::AttachmentTypes);
        $rebuild();
        $out(['types' => $ids]);
        break;

    case 'password-rows':
        // the access log rows of the showcase's password gate: the stored time as the engine returns it, and is_valid (D285)
        $rows = [];
        $query = $db->simple_select('myshowcase_access_logs', 'access_log_id, dateline, is_valid', '', ['order_by' => 'access_log_id']);
        while ($row = $db->fetch_array($query)) {
            $rows[] = ['id' => (int)$row['access_log_id'], 'dateline' => (string)$row['dateline'], 'is_valid' => (int)$row['is_valid']];
        }
        $out(['rows' => $rows]);
        break;

    case 'password-row':
        // one access log row written with the given time (a 'Y-m-d H:i:s' string in UTC, as the gate writes it), validity and user (0 for a guest)
        $sid = $showcaseId($argv[2]);
        $db->insert_query('myshowcase_access_logs', ['showcase_id' => $sid, 'user_id' => (int)($argv[5] ?? 0), 'dateline' => $db->escape_string($argv[3]), 'is_valid' => (int)$argv[4]]);
        $out(['inserted' => $argv[3]]);
        break;

    case 'password-rows-clear':
        // deletes the access log rows of a showcase (a test starts from none)
        $db->delete_query('myshowcase_access_logs', "showcase_id='" . $showcaseId($argv[2]) . "'");
        $out(['cleared' => $argv[2]]);
        break;

    case 'failed-rows':
        // guest attempts that failed, from one IP address (a packed address as the gate records it), now
        $sid = $showcaseId($argv[2]);
        $packed = my_inet_pton($argv[3]);
        $hex = bin2hex($packed);
        $literal = str_contains((string)$db->type, 'sqlite') ? "X'{$hex}'" : (str_contains((string)$db->type, 'pg') ? "decode('{$hex}', 'hex')" : "UNHEX('{$hex}')");
        for ($i = 0; $i < (int)$argv[4]; $i++) {
            $db->write_query("INSERT INTO {$db->table_prefix}myshowcase_access_logs (showcase_id, user_id, dateline, is_valid, ipaddress) VALUES ('{$sid}', 0, '" . gmdate('Y-m-d H:i:s', TIME_NOW) . "', 0, {$literal})");
        }
        $out(['failed' => (int)$argv[4], 'ip' => $argv[3]]);
        break;

    case 'guest-cookie':
        // the grant cookie the plugin itself makes for a guest of a showcase: the route's showcase (or the id given), an expiry
        // (absolute time), the IP the grant is bound to ('' without the IP check)
        $sid = isset($argv[5]) ? (int)$argv[5] : $showcaseId($argv[2]);
        $row = $db->fetch_array($db->simple_select('myshowcase_config', 'password', "showcase_id='{$sid}'"));
        $secret = \MyShowcase\Util\PasswordGrant::secretFrom($GLOBALS['cache']);
        $out(['cookie' => \MyShowcase\Util\PasswordGrant::value($secret, $sid, (int)$argv[3], (string)$row['password'], (string)$argv[4])]);
        break;

    case 'moderator-row':
        // the moderator row of a user on a showcase: replaces any row of that user, with the entries and comments flags (B7)
        $sid = $showcaseId($argv[2]);
        $uid = (int)$argv[3];
        $db->delete_query('myshowcase_moderators', "showcase_id='{$sid}' AND group_id='0' AND user_id='{$uid}'");
        $id = (int)$db->insert_query('myshowcase_moderators', ['showcase_id' => $sid, 'user_id' => $uid, 'group_id' => 0,
            'entries_can_view' => (int)$argv[4], 'can_manage_comments' => (int)$argv[5]]);
        $out(['moderator_id' => $id]);
        break;

    case 'attachment-status':
        // the status of an attachment of the showcase (B6): 1 approved, 0 awaiting approval
        $sid = $showcaseId($argv[2]);
        $db->update_query('myshowcase_attachments', ['status' => (int)$argv[4]], "showcase_id='{$sid}' AND attachment_id='" . (int)$argv[3] . "'");
        $out(['attachment_id' => (int)$argv[3], 'status' => (int)$argv[4]]);
        break;

    case 'set-ip':
        // the stored address of an entry, packed as the plugin stores it (my_inet_pton), for the moderator's IP link (D281).
        // The packed bytes are written as a literal of each engine (a blob literal, UNHEX or decode), not as an escaped string.
        $sid = $showcaseId($argv[2]);
        $hex = bin2hex(my_inet_pton($argv[4]));
        $literal = str_contains((string)$db->type, 'sqlite') ? "X'{$hex}'"
            : (str_contains((string)$db->type, 'pg') ? "decode('{$hex}', 'hex')" : "UNHEX('{$hex}')");
        $db->write_query("UPDATE {$db->table_prefix}myshowcase_data{$sid} SET ipaddress = {$literal} WHERE entry_id = '" . (int)$argv[3] . "'");
        $out(['entry_id' => (int)$argv[3], 'ip' => $argv[4], 'stored' => strlen($hex) / 2]);
        break;

    default:
        fwrite(STDERR, "unknown command: {$command}\n");
        exit(2);
}
