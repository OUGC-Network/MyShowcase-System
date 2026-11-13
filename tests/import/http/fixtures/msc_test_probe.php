<?php
/**
 * TEST-ONLY MyBB plugin of the access matrix. Never placed under Upload/ and never shipped: the matrix installs it into
 * its temporary board only (copy and activate), on the NEW board, to record what AttachmentAccess::decide() answers for
 * the user of each download request. It writes one JSON line per request to msc_probe.jsonl in the board root.
 */

if (!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.');
}

$plugins->add_hook('myshowcase_system_controller_attachments_download_start', 'msc_test_probe_decide');

function msc_test_probe_info(): array
{
    return [
        'name' => 'MyShowcase test probe',
        'description' => 'Test only: records AttachmentAccess::decide() of each download request.',
        'website' => '',
        'author' => 'test',
        'authorsite' => '',
        'version' => '0',
        'compatibility' => '*',
        'codename' => 'msc_test_probe',
    ];
}

function msc_test_probe_activate(): void
{
}

function msc_test_probe_deactivate(): void
{
}

/**
 * @param object $controller the AttachmentsController of the request (its context and attachment_data are public)
 */
function msc_test_probe_decide($controller): void
{
    global $mybb;

    if (!class_exists('MyShowcase\\Service\\AttachmentAccess') || !is_object($controller) || !isset($controller->context)) {
        return;
    }

    $access = new MyShowcase\Service\AttachmentAccess($controller->context);

    $decision = $access->decide($controller->attachment_data, (bool)$controller->is_thumbnail, $access->attachmentTypes());

    $line = [
        'uid' => (int)$controller->context->current_user->uid,
        'attachment_id' => (int)($controller->attachment_data['attachment_id'] ?? 0),
        'thumbnail' => (bool)$controller->is_thumbnail,
        'decision' => $decision,
    ];

    file_put_contents(MYBB_ROOT . 'msc_probe.jsonl', json_encode($line) . "\n", FILE_APPEND | LOCK_EX);
}
