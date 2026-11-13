<?php
/**
 * TEST-ONLY MyBB plugin of the abnormal-end test (abort_test.py). Never placed under Upload/ and never shipped: the test
 * installs it into its temporary board only (copy and activate).
 *
 * The test writes the marker file msc_abort.json into the board root:
 *   {"point": "<hook name without the prefix>", "after": N, "mode": "exit" | "fatal", "count": 0}
 * The request counts the calls of that hook and ends the request on the Nth call: with exit (the request ends normally
 * as PHP sees it, shutdown functions run), or with a fatal error (the memory limit is lowered and exhausted, which PHP
 * cannot catch). With no marker file the plugin does nothing.
 *
 * Hooks used:
 *  - entry_validate_insert_update_start: called once per imported entry, before its insert. The 2nd call comes after the
 *    first entry's file was stored AND recorded by the import transaction (the case the shutdown guard must clean up).
 *  - attachment_upload_file: called inside the attachment pipeline right after the file was copied, before the import
 *    records it (the window of decision D70, reported, not repaired).
 */

if (!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.');
}

$plugins->add_hook('myshowcase_system_entry_validate_insert_update_start', 'msc_test_abort_at_entry');
$plugins->add_hook('myshowcase_system_attachment_upload_file', 'msc_test_abort_at_file');

function msc_test_abort_info(): array
{
    return [
        'name' => 'MyShowcase abnormal-end test',
        'description' => 'Test only: ends the request on the Nth call of a hook.',
        'website' => '',
        'author' => 'test',
        'authorsite' => '',
        'version' => '0',
        'compatibility' => '*',
        'codename' => 'msc_test_abort',
    ];
}

function msc_test_abort_activate(): void
{
}

function msc_test_abort_deactivate(): void
{
}

function msc_test_abort_at_entry($manager): void
{
    msc_test_abort_step('entry_validate_insert_update_start');
}

function msc_test_abort_at_file($manager): void
{
    msc_test_abort_step('attachment_upload_file');
}

function msc_test_abort_step(string $point): void
{
    $path = MYBB_ROOT . 'msc_abort.json';

    if (!is_file($path)) {
        return;
    }

    $state = json_decode((string)file_get_contents($path), true);

    if (!is_array($state) || ($state['point'] ?? '') !== $point) {
        return;
    }

    $state['count'] = (int)($state['count'] ?? 0) + 1;

    file_put_contents($path, json_encode($state));

    if ($state['count'] !== (int)$state['after']) {
        return;
    }

    if (($state['mode'] ?? 'exit') === 'fatal') {
        ini_set('memory_limit', '8M');

        $held = [];

        while (true) {
            $held[] = str_repeat('x', 1048576);
        }
    }

    exit;
}
