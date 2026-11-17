<?php
/**
 * TEST ONLY (decision D69): copied by tests/fields/http/run.py into the board's inc/plugins/ and listed in the plugin cache by the
 * fixture. It is never part of the plugin (Upload/). While the flag file msc_test_fail_write exists in the board root, the form
 * write fails after its child rows are written: the hook after the entry is saved answers with error(), which exits without
 * writing a log line.
 */

if (!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.');
}

$plugins->add_hook('myshowcase_system_entry_validate_insert_update_end', 'msc_test_failure_write');

function msc_test_failure_write(): void
{
    if (is_file(MYBB_ROOT . 'msc_test_fail_write')) {
        error('forced failure of the write (test)');
    }
}
