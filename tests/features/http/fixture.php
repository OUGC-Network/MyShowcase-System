<?php
/**
 * Board-side steps of the features suite, run from the board root:  php <this file> <command> [args]
 *
 *   setup <users_file>         users, a moderated group, explicit permissions, attachment types, uploads folder,
 *                              enabled showcases, MyBB error log; writes the generated passwords to <users_file>
 *   grants                     the explicit permissions of setup again (every other permission row is deleted)
 *   clear-permissions          delete every permission row
 *   permission <group> <column> <value>   change one global group permission
 *   media <dir>                test images (jpg, png, gif, webp)
 *   task                       run the MyShowcase task (pruning)
 *   delete-entry <route> <entry_id>   delete an entry the way the pruning task does
 *   delete-user <username>     delete the user and its content, as the ACP does with "delete content"
 *   delete-showcase <route>    delete the showcase as the ACP does
 *   template-set <route> <prefix>  set the template set of the showcase
 */

declare(strict_types=1);

define('IN_MYBB', 1);
define('IN_ADMINCP', 1);
define('NO_ONLINE', 1);

// What a forum request (the task runner, the ACP) has and the command line has not.
$_SERVER['REMOTE_ADDR'] ??= '127.0.0.1';
$_SERVER['QUERY_STRING'] ??= '';

require './inc/init.php';

$mybb->user = ['uid' => 0, 'usergroup' => 1, 'additionalgroups' => '', 'displaygroup' => 1, 'username' => ''];

use MyShowcase\Enum\CacheDataTypes;
use MyShowcase\Service\CacheManager;
use MyShowcase\Util\BuildShowcase;

use function MyShowcase\Plugin\Functions\showcaseDelete;

use const MyShowcase\Plugin\Core\TABLES_DATA;

$cacheManager = new CacheManager($db, $cache);
$out = static fn(mixed $value) => print(json_encode($value, JSON_UNESCAPED_SLASHES) . "\n");
$command = $argv[1] ?? '';

$showcaseId = static function (string $route) use ($db): int {
    return (int)$db->fetch_field($db->simple_select('myshowcase_config', 'showcase_id', "route_identifier='{$db->escape_string($route)}'"), 'showcase_id');
};

$rebuild = static function () use ($cacheManager): void {
    foreach ([CacheDataTypes::Config, CacheDataTypes::Permissions, CacheDataTypes::AttachmentTypes, CacheDataTypes::Fields, CacheDataTypes::FieldData] as $type) {
        $cacheManager->update($type);
    }
};

// Every permission set explicitly: the column defaults are not relied on.
$applyGrants = static function (int $moderatedGroup) use ($db): void {
    $view = ['main_can_view', 'entries_can_view', 'comments_can_view', 'attachments_can_view', 'attachments_can_download', 'can_search_entries'];
    $member = array_merge($view, ['entries_can_post', 'entries_can_update', 'comments_can_post', 'comments_can_update', 'attachments_can_upload', 'attachments_can_update']);
    $moderator = array_merge($member, [
        'entries_can_view_unapproved', 'entries_can_view_deleted', 'entries_can_open', 'entries_can_close', 'entries_can_sticky',
        'entries_can_unsticky', 'entries_can_approve', 'entries_can_unapprove', 'entries_can_copy', 'entries_can_soft_delete',
        'entries_can_restore', 'entries_can_delete', 'comments_can_view_unapproved', 'comments_can_view_deleted',
        'comments_can_approve', 'comments_can_unapprove', 'comments_can_soft_delete', 'comments_can_restore', 'comments_can_delete',
        'comments_can_post_in_closed_entries', 'attachments_can_view_unapproved', 'can_bypass_moderation',
    ]);
    $grants = [1 => $view, 2 => $member, 3 => $moderator, 4 => $moderator, $moderatedGroup => array_merge($member, ['entries_moderate_new', 'comments_moderate_new'])];

    $db->delete_query('myshowcase_permissions');
    foreach ($grants as $groupId => $columns) {
        $row = ['showcase_id' => 0, 'group_id' => $groupId];
        foreach (TABLES_DATA['myshowcase_permissions'] as $column => $definition) {
            if (!is_array($definition) || !isset($definition['type']) || !empty($definition['auto_increment']) ||
                in_array($column, ['showcase_id', 'group_id', 'moderator_id', 'user_id'], true)) {
                continue;
            }
            $row[$column] = in_array($column, $columns, true) ? 1 : 0;
        }
        $db->insert_query('myshowcase_permissions', $row);
    }
};

$moderatedGroupId = static fn(): int => (int)$db->fetch_field($db->simple_select('usergroups', 'gid', "title='Moderated members'"), 'gid');

switch ($command) {
    case 'grants':
        $applyGrants($moderatedGroupId());
        $rebuild();
        $out(['grants' => 'applied']);
        break;
    case 'clear-permissions':
        $db->delete_query('myshowcase_permissions');
        $rebuild();
        $out(['permissions' => 'cleared']);
        break;
    case 'setup':
        require_once MYBB_ROOT . 'inc/datahandlers/user.php';

        // A group whose new entries and comments wait for approval.
        $moderatedGroup = (int)$db->fetch_field($db->simple_select('usergroups', 'gid', "title='Moderated members'"), 'gid');
        if (!$moderatedGroup) {
            $registered = $db->fetch_array($db->simple_select('usergroups', '*', "gid='2'"));
            $registered = array_filter($registered, 'is_string', ARRAY_FILTER_USE_KEY);
            unset($registered['gid']);
            $registered['title'] = 'Moderated members';
            $registered['type'] = 2;
            $moderatedGroup = (int)$db->insert_query('usergroups', array_map(static fn($v) => $db->escape_string((string)$v), $registered));
            $cache->update_usergroups();
        }

        $users = [];
        foreach (['member1' => 2, 'member2' => 2, 'mod1' => 3, 'modq1' => $moderatedGroup, 'leaver' => 2] as $username => $group) {
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
        file_put_contents($argv[2], json_encode($users));
        chmod($argv[2], 0600);

        // Every permission set explicitly: the column defaults are not relied on.
        $applyGrants($moderatedGroup);

        // Images for every showcase.
        if (!$db->fetch_field($db->simple_select('attachtypes', 'atid', "extension='webp'"), 'atid')) {
            $db->insert_query('attachtypes', ['name' => 'WebP Image', 'mimetype' => 'image/webp', 'extension' => 'webp', 'maxsize' => 10240,
                'icon' => 'images/attachtypes/image.png', 'enabled' => 1, 'forcedownload' => 0, 'groups' => '-1', 'forums' => '-1', 'avatarfile' => 0]);
        }
        $ids = implode(',', array_keys(iterator_to_array((function () use ($db) {
            $q = $db->simple_select('myshowcase_config', 'showcase_id');
            while ($r = $db->fetch_array($q)) {
                yield (int)$r['showcase_id'] => true;
            }
        })())));
        $db->update_query('attachtypes', ['myshowcase_ids' => $ids, 'maxsize' => 10240], "extension IN ('jpg','jpeg','jpe','png','gif','webp')");

        @mkdir(MYBB_ROOT . 'uploads/myshowcase', 0777, true);
        @mkdir(MYBB_ROOT . 'uploads/avatars', 0777, true);
        $db->update_query('myshowcase_config', [
            'is_enabled' => 1,
            'showcase_url' => $db->escape_string($mybb->settings['bburl'] . '/showcase.php'),
            'attachments_uploads_path' => 'uploads/myshowcase',
        ]);

        $db->update_query('settings', ['value' => 'log'], "name='errorlogmedium'");
        $db->update_query('settings', ['value' => $db->escape_string(MYBB_ROOT . 'error.log')], "name='errorloglocation'");
        $db->update_query('settings', ['value' => E_ALL], "name='errortypemedium'");
        rebuild_settings();

        $rebuild();
        $out(['moderated_group' => $moderatedGroup, 'users' => array_map(static fn(array $u): array => ['uid' => $u['uid'], 'group' => $u['group']], $users)]);
        break;
    case 'permission':
        [, , $groupId, $column, $value] = $argv;
        $db->update_query('myshowcase_permissions', [$db->escape_string($column) => (int)$value], "showcase_id='0' AND group_id='" . (int)$groupId . "'");
        $rebuild();
        $out([$column => (int)$value]);
        break;
    case 'media':
        $dir = $argv[2];
        @mkdir($dir, 0777, true);
        $image = static function (int $w, int $h, array $rgb) {
            $im = imagecreatetruecolor($w, $h);
            imagefill($im, 0, 0, imagecolorallocate($im, ...$rgb));
            imagefilledrectangle($im, (int)($w / 4), (int)($h / 4), (int)($w / 2), (int)($h / 2), imagecolorallocate($im, 255, 255, 255));

            return $im;
        };
        imagejpeg($image(800, 600, [200, 40, 40]), "{$dir}/photo.jpg");
        imagejpeg($image(640, 480, [40, 40, 200]), "{$dir}/second.jpg");
        imagepng($image(600, 400, [40, 160, 40]), "{$dir}/graphic.png");
        imagegif($image(400, 300, [160, 160, 40]), "{$dir}/static.gif");
        // MyBB makes no thumbnail of a WebP image.
        imagewebp($image(640, 480, [40, 160, 160]), "{$dir}/image.webp");
        $out(array_values(array_diff(scandir($dir), ['.', '..'])));
        break;
    case 'task':
        require_once MYBB_ROOT . 'inc/functions_task.php';
        require_once MYBB_ROOT . 'inc/tasks/myshowcase.php';
        task_myshowcase(['tid' => 0, 'title' => 'MyShowcase', 'logging' => 0]);
        $out(['task' => 'run']);
        break;
    case 'delete-entry':
        // What the pruning task does for each old entry, outside a forum request.
        $showcase = (new BuildShowcase())->getShowcase($showcaseId($argv[2]));
        $out(['deleted' => $showcase->context->entries_manager->deleteEntry((int)$argv[3])]);
        break;
    case 'delete-user':
        require_once MYBB_ROOT . 'inc/datahandlers/user.php';
        $user = get_user_by_username($argv[2]);
        $handler = new UserDataHandler('delete');
        $result = $handler->delete_user([(int)$user['uid']], 1);
        $out(['deleted_users' => $result['deleted_users'] ?? null]);
        break;
    case 'delete-showcase':
        $sid = $showcaseId($argv[2]);
        showcaseDelete($sid);
        $rebuild();
        $out(['deleted_showcase' => $sid]);
        break;
    case 'template-set':
        $db->update_query('myshowcase_config', ['custom_template_prefix' => $db->escape_string($argv[3])], "showcase_id='" . $showcaseId($argv[2]) . "'");
        $rebuild();
        $out(['custom_template_prefix' => $argv[3]]);
        break;
    default:
        fwrite(STDERR, "unknown command\n");
        exit(1);
}
