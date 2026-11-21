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
        // Per area (B7, decision P1): the entries flag moderates entries, the comments flag moderates comments
        $db->insert_query('myshowcase_moderators', ['showcase_id' => $showcase, 'user_id' => $groupId('Moderators'), 'group_id' => 1,
            'entries_can_view' => 1, 'can_manage_comments' => 1]);

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
    case 'field-values':
        // decision D61: the rows of a multiple-choice field are removed with their entry, option, field and showcase. The
        // ids are ones no board uses. The second showcase has a row of the second field, which survives the deletes of the first
        // field and of the first showcase (a field's values go with the field, in every showcase that has it).
        $values = new \MyShowcase\Repository\FieldValuesRepository($db);
        $showcase = 987654;
        $other = 987655;
        $field = 987654;
        $other_field = 987655;
        $rowsOf = static function (int $showcase_id, string $where = '1=1') use ($db): int {
            $row = $db->fetch_array($db->simple_select('myshowcase_field_values', 'COUNT(*) AS n', "showcase_id='{$showcase_id}' AND {$where}"));

            return (int)$row['n'];
        };
        $values->replace($showcase, 1, $field, ['1', '2']);
        $values->replace($showcase, 2, $field, ['2']);
        $values->replace($showcase, 1, $other_field, ['5']);
        $values->replace($other, 1, $other_field, ['5']);
        $start = $rowsOf($showcase);
        $values->deleteForEntry($showcase, 1);
        $afterEntry = $rowsOf($showcase);
        $values->replace($showcase, 1, $field, ['1', '2']);
        $values->deleteForOption($field, '2');
        $afterOption = $rowsOf($showcase, "value_id='2'");
        $values->deleteForField($field);
        $afterField = $rowsOf($showcase);
        $values->deleteForShowcase($showcase);
        $out([
            'table' => $db->table_exists('myshowcase_field_values'),
            'start' => $start,
            'after_entry' => $afterEntry,
            'option_rows_left' => $afterOption,
            'after_field' => $afterField,
            'after_showcase' => $rowsOf($showcase),
            'other_showcase_kept' => $rowsOf($other),
        ]);
        $values->deleteForShowcase($other);
        break;
    case 'field-rules':
        // decisions D184, D187 and D180: the rules of the field form, read through FieldService::validate() for the FieldKit
        // fields (the entries of the matrix exist by now): a checkbox must be text, a select can not change its
        // several-values setting while an entry holds a value, and a new select may take several values.
        $service = new \MyShowcase\Service\FieldService($cacheManager);
        $collection = (int)$db->fetch_field($db->simple_select('myshowcase_collections', 'collection_id', "name='FieldKit'"), 'collection_id');
        $fieldOf = static function (string $key) use ($db, $collection): int {
            return (int)$db->fetch_field($db->simple_select('myshowcase_fields', 'field_id', "collection_id='{$collection}' AND field_key='{$key}'"), 'field_id');
        };
        $keysOf = static function (array $errors): array {
            return array_values(array_map(static fn(array $error): string => $error['key'], $errors));
        };
        $checkboxInt = $keysOf($service->validate(['field_key' => 'fk_checkbox', 'html_type' => 'checkbox', 'field_type' => 'int', 'allow_multiple_values' => 1], $collection, $fieldOf('fk_checkbox')));
        $checkboxText = $keysOf($service->validate(['field_key' => 'fk_checkbox', 'html_type' => 'checkbox', 'field_type' => 'text', 'allow_multiple_values' => 1], $collection, $fieldOf('fk_checkbox')));
        $selectToggle = $keysOf($service->validate(['field_key' => 'fk_select', 'html_type' => 'select', 'field_type' => 'int', 'allow_multiple_values' => 1], $collection, $fieldOf('fk_select')));
        $selectNew = $keysOf($service->validate(['field_key' => 'fk_select_new', 'html_type' => 'select', 'field_type' => 'text', 'allow_multiple_values' => 1], $collection, 0));
        $radioInt = $keysOf($service->validate(['field_key' => 'fk_radio', 'html_type' => 'radio', 'field_type' => 'int', 'allow_multiple_values' => 1], $collection, $fieldOf('fk_radio')));
        $out([
            'checkbox_int_refused' => in_array('myshowcase_admin_error_multiple_column_type', $checkboxInt, true),
            'checkbox_text_accepted' => !array_intersect(['myshowcase_admin_error_multiple_column_type', 'myshowcase_admin_error_multiple_toggle_in_use'], $checkboxText),
            'select_toggle_refused' => in_array('myshowcase_admin_error_multiple_toggle_in_use', $selectToggle, true),
            'select_new_accepted' => !array_intersect(['myshowcase_admin_error_multiple_column_type', 'myshowcase_admin_error_multiple_toggle_in_use'], $selectNew),
            'radio_ignores_flag' => !array_intersect(['myshowcase_admin_error_multiple_column_type', 'myshowcase_admin_error_multiple_toggle_in_use'], $radioInt),
        ]);
        break;
    case 'multi-values':
        // decisions D61 and D193: the rows of a multiple-choice field go with its option and its field (the plugin's delete
        // functions), and a write that is rolled back leaves none. The first entry of FieldKit is used and its values are
        // restored at the end.
        $values = new \MyShowcase\Repository\FieldValuesRepository($db);
        $one = static function (string $sql) use ($db): string {
            $query = $db->query($sql);
            $row = $db->fetch_array($query);

            return $row ? (string)reset($row) : '';
        };
        $showcase = (int)$one("SELECT showcase_id FROM {$db->table_prefix}myshowcase_config WHERE route_identifier='fieldkit'");
        $collection = (int)$one("SELECT collection_id FROM {$db->table_prefix}myshowcase_collections WHERE name='FieldKit'");
        $entry = (int)$one("SELECT entry_id FROM {$db->table_prefix}myshowcase_data{$showcase} ORDER BY entry_id LIMIT 1");
        $fieldOf = static fn(string $key): int => (int)$one("SELECT field_id FROM {$db->table_prefix}myshowcase_fields WHERE collection_id='{$collection}' AND field_key='{$key}'");
        $multi = $fieldOf('fk_select_multiple');
        $valuesOf = static fn(int $field): array => $values->valuesOf($showcase, $entry, $field);
        $original = $valuesOf($multi);
        $originalCache = $one("SELECT fk_select_multiple FROM {$db->table_prefix}myshowcase_data{$showcase} WHERE entry_id='{$entry}'");

        // (a) the option is deleted: the rows that name it go, the others stay
        $db->insert_query('myshowcase_field_data', [
            'collection_id' => $collection, 'field_id' => $multi, 'field_key' => 'fk_select_multiple', 'value_id' => '77',
            'value' => 'Temporary', 'display_order' => 99, 'allowed_groups_fill' => 'all', 'allowed_groups_view' => 'all',
        ]);
        $option = (int)$one("SELECT field_data_id FROM {$db->table_prefix}myshowcase_field_data WHERE field_id='{$multi}' AND value_id='77'");
        $values->replace($showcase, $entry, $multi, ['1', '77']);
        \MyShowcase\Plugin\Functions\fieldDataDelete($option);
        $afterOption = $valuesOf($multi);

        // (b) a write that is rolled back leaves no rows of its own
        $values->replace($showcase, $entry, $multi, ['2']);
        $before = $valuesOf($multi);
        $values->begin();
        $values->replace($showcase, $entry, $multi, ['3']);
        $values->rollback();
        $afterRollback = $valuesOf($multi);

        // (c) the field is deleted: its rows go, the rows of the other field stay
        $db->insert_query('myshowcase_fields', [
            'collection_id' => $collection, 'field_key' => 'fk_tmp_multi', 'html_type' => 'checkbox', 'field_type' => 'text',
            'is_enabled' => 1, 'allow_multiple_values' => 1, 'allowed_groups_fill' => 'all', 'allowed_groups_view' => 'all',
        ]);
        $temporary = $fieldOf('fk_tmp_multi');
        $values->replace($showcase, $entry, $temporary, ['1']);
        $values->replace($showcase, $entry, $multi, ['2']);
        \MyShowcase\Plugin\Functions\fieldsDelete($temporary);
        $afterField = $values->valuesOf($showcase, $entry, $temporary);
        $otherAfterField = $valuesOf($multi);

        // restore the values of the first entry
        $values->replace($showcase, $entry, $multi, $original);
        $db->update_query("myshowcase_data{$showcase}", ['fk_select_multiple' => $db->escape_string((string)$originalCache)], "entry_id='{$entry}'");

        $out([
            'option_rows' => $afterOption,
            'rolled_back_kept' => $before === ['2'] && $afterRollback === ['2'],
            'field_rows_after_delete' => $afterField,
            'other_field_kept' => $otherAfterField === ['2'],
        ]);
        break;
    case 'multi-sort':
        // decision D181: a multiple-choice field is not a sort order; a single-choice field is. The repository's sort column
        // is read through reflection, with the three properties it reads.
        $repository = new \MyShowcase\Repository\EntriesRepository($db, $mybb);
        $sort = new \ReflectionMethod(\MyShowcase\Repository\EntriesRepository::class, 'sortColumn');
        $sort->setAccessible(true);
        $field = static fn(string $key, string $html, int $allow): object => (object)['field_key' => $key, 'html_type' => $html, 'allow_multiple_values' => $allow];
        $collection = new \MyShowcase\Collection\Collection([$field('fk_select_multiple', 'select', 1), $field('fk_checkbox', 'checkbox', 1), $field('fk_select', 'select', 0)]);
        $out([
            'multiple_select' => $sort->invoke($repository, 'fk_select_multiple', $collection),
            'checkbox' => $sort->invoke($repository, 'fk_checkbox', $collection),
            'single_select' => $sort->invoke($repository, 'fk_select', $collection),
        ]);
        break;
    case 'failure-plugin-activate':
        // TEST ONLY (decision D69): lists the test plugin msc_test_failure in the plugin cache (the file is copied by run.py)
        $list = $cache->read('plugins');
        $list = is_array($list) ? $list : [];
        $list['active'] = array_values(array_unique(array_merge($list['active'] ?? [], ['msc_test_failure'])));
        $cache->update('plugins', $list);
        $out(['active' => in_array('msc_test_failure', $list['active'], true)]);
        break;
    case 'failure-write':
        // TEST ONLY: the flag file that makes the form write fail (see msc_test_failure.php)
        $flag = rtrim(MYBB_ROOT, '/') . '/msc_test_fail_write';
        if ($argv[2] === 'on') {
            file_put_contents($flag, '1');
        } elseif (is_file($flag)) {
            unlink($flag);
        }
        $out(['failure_write' => is_file($flag)]);
        break;
    case 'rebuild':
        $rebuild();
        $out(['rebuilt' => true]);
        break;

    default:
        fwrite(STDERR, "unknown command\n");
        exit(2);
}
