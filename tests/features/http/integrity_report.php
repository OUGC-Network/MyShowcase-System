<?php

declare(strict_types=1);

/*
 * The read-only integrity report (B9b). Run from the board root; it never writes, never deletes, and prints one JSON object:
 *
 *   orphans  (the harness requires zero for these five):
 *     attachments_without_parent   an attachment whose entry is missing, whose comment is missing, or that has neither an entry, a
 *                                  comment nor a post hash (an upload that was never attached)
 *     field_values_orphans         a field value whose field row or whose entry row is missing
 *     attachments_without_file     an attachment row whose file is not in the showcase's uploads folder (searched recursively)
 *     files_without_row            a file in an uploads folder that no attachment row names (as file or thumbnail)
 *     history_without_parent       an attachment or comment history row whose parent row is missing
 *   information (reported, not required to be zero):
 *     moderators_without_showcase  a moderator row whose showcase is missing
 *     columns_not_in_tables_data   a column present on the board but absent from TABLES_DATA, per table
 *
 *   php fresh_integrity_report.php
 */

define('IN_MYBB', 1);
define('IN_ADMINCP', 1);
define('NO_ONLINE', 1);

$_SERVER['REMOTE_ADDR'] ??= '127.0.0.1';
$_SERVER['QUERY_STRING'] ??= '';

require './inc/init.php';

use const MyShowcase\Plugin\Core\TABLES_DATA;

global $db;

$prefix = $db->table_prefix;

$ids = static function (string $table, string $column, string $where = '') use ($db, $prefix): array {
    $found = [];
    $query = $db->simple_select($table, $column, $where);

    while ($row = $db->fetch_array($query)) {
        $found[(string)$row[$column]] = true;
    }

    return $found;
};

$showcases = [];
$query = $db->simple_select('myshowcase_config', 'showcase_id, attachments_uploads_path');

while ($row = $db->fetch_array($query)) {
    $showcases[(int)$row['showcase_id']] = (string)$row['attachments_uploads_path'];
}

$entryIds = [];

foreach (array_keys($showcases) as $showcaseId) {
    $table = "myshowcase_data{$showcaseId}";

    $entryIds[$showcaseId] = $db->table_exists($table) ? $ids($table, 'entry_id') : [];
}

$commentIds = $ids('myshowcase_comments', 'comment_id');
$attachmentIds = $ids('myshowcase_attachments', 'attachment_id');
$fieldIds = $ids('myshowcase_fields', 'field_id');

$orphans = ['attachments_without_parent' => [], 'field_values_orphans' => [], 'attachments_without_file' => [],
    'files_without_row' => [], 'history_without_parent' => []];

$names = [];
$query = $db->simple_select('myshowcase_attachments', 'attachment_id, showcase_id, entry_id, comment_id, post_hash, file_name, thumbnail_name');

while ($row = $db->fetch_array($query)) {
    $sid = (int)$row['showcase_id'];
    $entry = (int)$row['entry_id'];
    $comment = (int)$row['comment_id'];
    // A file is named by the rows of every showcase: the showcases may share one uploads folder, so a file of another
    // showcase is not an orphan of this one.
    $names[basename((string)$row['file_name'])] = true;

    if (!empty($row['thumbnail_name'])) {
        $names[basename((string)$row['thumbnail_name'])] = true;
    }

    $missingEntry = $entry > 0 && !isset($entryIds[$sid][(string)$entry]);
    $missingComment = $comment > 0 && !isset($commentIds[(string)$comment]);
    $unattached = $entry === 0 && $comment === 0 && (string)$row['post_hash'] === '';

    if ($missingEntry || $missingComment || $unattached) {
        $orphans['attachments_without_parent'][] = (int)$row['attachment_id'];
    }

    $directory = $showcases[$sid] ?? '';
    $root = $directory !== '' ? (is_dir($directory) ? $directory : MYBB_ROOT . $directory) : '';

    if ($root === '' || !is_dir($root) || (string)$row['file_name'] === '') {
        continue;
    }

    $found = false;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getFilename() === basename((string)$row['file_name'])) {
            $found = true;
            break;
        }
    }

    if (!$found) {
        $orphans['attachments_without_file'][] = (int)$row['attachment_id'];
    }
}

$query = $db->simple_select('myshowcase_field_values', 'field_value_id, showcase_id, entry_id, field_id');

while ($row = $db->fetch_array($query)) {
    $sid = (int)$row['showcase_id'];

    if (!isset($fieldIds[(string)$row['field_id']]) || !isset($entryIds[$sid][(string)$row['entry_id']])) {
        $orphans['field_values_orphans'][] = (int)$row['field_value_id'];
    }
}

$scanned = [];

foreach ($showcases as $sid => $directory) {
    $root = $directory !== '' ? (is_dir($directory) ? $directory : MYBB_ROOT . $directory) : '';

    // Each uploads folder is scanned once, however many showcases share it.
    if ($root === '' || !is_dir($root) || isset($scanned[realpath($root)])) {
        continue;
    }

    $scanned[realpath($root)] = true;

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->isFile() && !isset($names[$file->getFilename()])) {
            $orphans['files_without_row'][] = $file->getPathname();
        }
    }
}

$query = $db->simple_select('myshowcase_attachments_history', 'attachment_history_id, attachment_id');

while ($row = $db->fetch_array($query)) {
    if (!isset($attachmentIds[(string)$row['attachment_id']])) {
        $orphans['history_without_parent'][] = 'attachment_history:' . (int)$row['attachment_history_id'];
    }
}

$query = $db->simple_select('myshowcase_comments_history', 'comment_history_id, comment_id');

while ($row = $db->fetch_array($query)) {
    if (!isset($commentIds[(string)$row['comment_id']])) {
        $orphans['history_without_parent'][] = 'comment_history:' . (int)$row['comment_history_id'];
    }
}

$information = ['moderators_without_showcase' => [], 'columns_not_in_tables_data' => []];

$query = $db->simple_select('myshowcase_moderators', 'moderator_id, showcase_id');

while ($row = $db->fetch_array($query)) {
    if (!isset($showcases[(int)$row['showcase_id']])) {
        $information['moderators_without_showcase'][] = (int)$row['moderator_id'];
    }
}

foreach (TABLES_DATA as $table => $definition) {
    if (!$db->table_exists($table)) {
        continue;
    }

    $columns = array_values(array_map(static fn($field) => is_array($field) ? (string)($field['Field'] ?? $field['name'] ?? '') : (string)$field, array_values($db->show_fields_from($table))));
    $extra = array_values(array_diff($columns, array_keys($definition)));

    if ($extra) {
        $information['columns_not_in_tables_data'][$table] = $extra;
    }
}

$kinds = [];

foreach ($orphans as $name => $items) {
    $kinds[$name] = ['count' => count($items), 'sample' => array_slice($items, 0, 5)];
}

echo json_encode(['orphans' => $kinds, 'information' => [
    'moderators_without_showcase' => count($information['moderators_without_showcase']),
    'columns_not_in_tables_data' => $information['columns_not_in_tables_data'],
]], JSON_UNESCAPED_SLASHES) . "\n";
