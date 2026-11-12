<?php
/**
 * MyShowcase Plugin for MyBB - Main Plugin
 * Copyright 2012 CommunityPlugins.com, All Rights Reserved
 *
 * Website: https://github.com/Sama34/MyShowcase-System
 * License: Creative Commons Attribution-NonCommerical ShareAlike 3.0
 * http://creativecommons.org/licenses/by-nc-sa/3.0/legalcode
 * File: \admin\modules\myshowcase\json_sync.php
 *
 * Link, create, apply, detach and export for the JSON sync. Every action checks the post key; the module permission
 * "json_sync" is checked by the ACP before this file runs.
 */

declare(strict_types=1);

use MyShowcase\Service\CacheManager;
use MyShowcase\Service\JsonSync\JsonExporter;
use MyShowcase\Service\JsonSync\JsonFiles;
use MyShowcase\Service\JsonSync\JsonStatus;
use MyShowcase\Service\JsonSync\JsonSync;
use MyShowcase\Service\JsonSync\JsonUi;
use MyShowcase\Service\ShowcaseService;

use function MyShowcase\Plugin\Functions\collectionGet;
use function MyShowcase\Plugin\Functions\loadLanguage;
use function MyShowcase\Plugin\Functions\showcaseGet;

use const MyShowcase\Plugin\Core\TABLES_DATA;

if (!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.');
}

global $lang, $cache, $db, $mybb, $page;

loadLanguage();

$cacheManager = new CacheManager($db, $cache);

$sync = new JsonSync($cacheManager);

$listUrl = 'index.php?module=myshowcase-summary';

$action = $mybb->get_input('action');

$type = $mybb->get_input('type');

$id = $mybb->get_input('id', MyBB::INPUT_INT);

$mode = $mybb->get_input('mode');

$file = $mybb->get_input('file');

if (!in_array($type, ['collection', 'showcase', ''], true)) {
    $type = '';
}

if (!verify_post_check($mybb->get_input('my_post_key'), true)) {
    flash_message($lang->myshowcase_admin_error_invalid_post_key, 'error');

    admin_redirect($listUrl);
}

$backUrl = $type === 'collection' ? 'index.php?module=myshowcase-fields' : $listUrl;

$columns = static fn(string $table): array => array_keys(array_filter(
    TABLES_DATA[$table],
    static fn($definition) => is_array($definition) && isset($definition['type'])
));

/** The row being worked on, or null. */
$loadRow = static function () use ($type, $id, $columns): ?array {
    if ($type === 'collection') {
        $rows = collectionGet(["collection_id='{$id}'"], $columns('myshowcase_collections'));
    } else {
        $rows = showcaseGet(["showcase_id='{$id}'"], $columns('myshowcase_config'), [], false);
    }

    return $rows[$id] ?? null;
};

$fail = static function (string $key) use ($backUrl): void {
    flash_message(JsonUi::text($key), 'error');

    admin_redirect($backUrl);
};

$page->add_breadcrumb_item($lang->myshowcase_system, $listUrl);

$page->add_breadcrumb_item(JsonUi::text('json_sync'), JsonUi::url(['action' => 'create'], false));

/** Table of the steps of a plan, in order, with the warnings highlighted. */
$renderPlan = static function (array $plan, array $results = []): void {
    $table = new Table();

    $table->construct_header('#', ['width' => '2%', 'class' => 'align_center']);

    $table->construct_header(JsonUi::text('plan'));

    if ($results) {
        $table->construct_header(JsonUi::text('result'), ['width' => '10%', 'class' => 'align_center']);
    }

    if (!$plan['steps']) {
        $table->construct_cell(JsonUi::text('plan_nothing'), ['colspan' => $results ? 3 : 2]);

        $table->construct_row();
    }

    foreach ($plan['steps'] as $index => $step) {
        $table->construct_cell((string)($index + 1), ['class' => 'align_center']);

        $cell = htmlspecialchars($step['description']);

        foreach ($step['warnings'] as $warning) {
            $cell .= '<div style="margin-top:3px;padding:2px 6px;background:#fff3cd;color:#664d03;">'
                . htmlspecialchars(JsonUi::text('warning')) . ': ' . htmlspecialchars($warning) . '</div>';
        }

        $table->construct_cell($cell);

        if ($results) {
            $table->construct_cell(htmlspecialchars($results[$step['id']] ?? ''), ['class' => 'align_center']);
        }

        $table->construct_row();
    }

    $table->output(JsonUi::text('plan'));

    foreach ($plan['warnings'] as $warning) {
        echo '<div style="margin:6px 0;padding:4px 8px;background:#fff3cd;color:#664d03;">'
            . htmlspecialchars(JsonUi::text('warning')) . ': ' . htmlspecialchars($warning) . '</div>';
    }
};

/** The form that starts a create from a file. */
$renderCreateForm = static function (string $createType, array $errors, array $values) use ($sync, $page): void {
    $page->output_header(JsonUi::text('create_from_json'));

    if ($errors) {
        $page->output_inline_error($errors);
    }

    $form = new Form('index.php?module=myshowcase-json_sync', 'post');

    echo $form->generate_hidden_field('my_post_key', $GLOBALS['mybb']->post_code);

    echo $form->generate_hidden_field('action', 'preview');

    echo $form->generate_hidden_field('mode', 'create');

    echo $form->generate_hidden_field('type', $createType);

    $container = new FormContainer(JsonUi::text('create_from_json'));

    $files = $sync->files()->list($createType);

    $container->output_row(
        JsonUi::text('file'),
        JsonUi::text('create_description'),
        $files
            ? $form->generate_select_box('file', array_combine($files, $files), $values['file'] ?? '', ['id' => 'file'])
            : htmlspecialchars(JsonUi::text('no_files'))
    );

    if ($createType === 'showcase') {
        foreach (['name', 'route_identifier', 'custom_template_prefix'] as $input) {
            $container->output_row(
                JsonUi::text($input),
                '',
                $form->generate_text_box($input, (string)($values[$input] ?? ''), ['id' => $input])
            );
        }
    }

    $container->end();

    $form->output_submit_wrapper([$form->generate_submit_button(JsonUi::text('preview'))]);

    $form->end();

    $page->output_footer();
};

switch ($action) {
    case 'create':
        $createType = $type === 'showcase' ? 'showcase' : 'collection';

        $renderCreateForm($createType, [], []);

        break;
    case 'errors':
        if (JsonFiles::typeOf($file) === null) {
            $fail('error_invalid_file');
        }

        $loaded = $sync->load($file);

        $page->output_header(JsonUi::text('json_sync'));

        echo '<h3>' . htmlspecialchars(JsonUi::text('errors_title', $file)) . '</h3>';

        if (!$loaded['errors']) {
            echo '<p>' . htmlspecialchars(JsonUi::text('errors_none')) . '</p>';
        } else {
            $table = new Table();

            foreach ($loaded['errors'] as $error) {
                $table->construct_cell('<code>' . htmlspecialchars($error['path']) . '</code>', ['width' => '30%']);

                $table->construct_cell(htmlspecialchars($error['message']));

                $table->construct_row();
            }

            $table->output(JsonUi::text('show_errors'));
        }

        $page->output_footer();

        break;
    case 'export':
        $row = $loadRow();

        if ($row === null || $type === '') {
            $fail('error_invalid_row');
        }

        $export = $type === 'collection' ? $sync->exportCollection($id) : $sync->exportShowcase($id);

        if ($export === null) {
            $fail('error_invalid_row');
        }

        log_admin_action(['type' => $type, 'id' => $id, 'action' => 'export']);

        header('Content-Type: application/json; charset=utf-8');

        header('Content-Disposition: attachment; filename="' . JsonSync::exportFileName((string)$row['name']) . '"');

        header('X-Content-Type-Options: nosniff');

        echo JsonExporter::encode($export);

        exit;
    case 'detach':
        $row = $loadRow();

        if ($row === null || $type === '' || (string)$row['json_file'] === '') {
            $fail('error_invalid_row');
        }

        if ($mybb->request_method === 'post') {
            if (isset($mybb->input['no'])) {
                admin_redirect($backUrl);
            }

            $sync->detach($type, $id);

            log_admin_action(['type' => $type, 'id' => $id, 'action' => 'detach', 'file' => $row['json_file']]);

            flash_message(JsonUi::text('detached'), 'success');

            admin_redirect($backUrl);
        }

        $page->output_header(JsonUi::text('json_sync'));

        $page->output_confirm_action(
            JsonUi::url(['action' => 'detach', 'type' => $type, 'id' => $id], false),
            JsonUi::text('detach_confirm', (string)$row['name'], (string)$row['json_file'])
        );

        $page->output_footer();

        break;
    case 'preview':
    case 'run':
        $options = [];

        $row = null;

        if ($mode === 'create') {
            if (JsonFiles::typeOf($file) !== $type || $type === '') {
                $fail('error_invalid_file');
            }

            if ($type === 'showcase') {
                $values = [
                    'file' => $file,
                    'name' => trim($mybb->get_input('name')),
                    'route_identifier' => trim($mybb->get_input('route_identifier')),
                    'custom_template_prefix' => ShowcaseService::normalizeTemplatePrefix($mybb->get_input('custom_template_prefix')),
                ];

                $errors = [];

                if ($values['name'] === '' || $values['route_identifier'] === '') {
                    $errors[] = $lang->myshowcase_admin_error_missing_required_fields;
                }

                foreach ((new ShowcaseService($cacheManager))->validate($values) as $error) {
                    $errors[] = $lang->{$error['key']} ?? $error['message'];
                }

                if ($errors) {
                    $renderCreateForm('showcase', array_unique($errors), $values);

                    break;
                }

                $options = array_intersect_key($values, array_flip(['name', 'route_identifier', 'custom_template_prefix']));
            }
        } else {
            $row = $loadRow();

            if ($row === null || $type === '') {
                $fail('error_invalid_row');
            }

            if ($mode === 'apply') {
                $file = (string)$row['json_file'];

                $status = $sync->statusOf($row, $type);

                if (!in_array($status, [JsonStatus::UPDATE_AVAILABLE, JsonStatus::UNVERSIONED_CHANGE], true)) {
                    $fail('error_invalid_action');
                }
            } elseif ($mode === 'link') {
                if ((string)$row['json_file'] !== '' || JsonFiles::typeOf($file) !== $type) {
                    $fail('error_invalid_action');
                }

                if ($type === 'showcase') {
                    $loaded = $sync->load($file);

                    $collection = $loaded['valid'] ? collectionGet(
                        ["collection_id='" . (int)$row['collection_id'] . "'"],
                        ['name', 'json_file']
                    )[(int)$row['collection_id']] ?? null : null;

                    $needed = $loaded['valid'] ? JsonFiles::relativePath('collection', (string)$loaded['data']['collection']) : '';

                    if ($loaded['valid'] && ($collection === null || $collection['json_file'] !== $needed)) {
                        flash_message(JsonUi::text('link_showcase_first', $needed), 'error');

                        admin_redirect($backUrl);
                    }
                }
            } else {
                $fail('error_invalid_action');
            }

            $options = [$type === 'collection' ? 'collection_id' : 'showcase_id' => $id];
        }

        if ($action === 'preview') {
            $plan = $sync->plan($file, $options);

            $page->output_header(JsonUi::text('json_sync'));

            echo '<h3>' . htmlspecialchars($file) . ' → ' . htmlspecialchars(JsonUi::text('version', $plan['version'])) . '</h3>';

            if ($plan['errors']) {
                $page->output_inline_error($plan['errors']);

                $page->output_footer();

                break;
            }

            if ($mode === 'apply' && ($status ?? '') === JsonStatus::UNVERSIONED_CHANGE) {
                echo '<div style="margin:6px 0;padding:4px 8px;background:#ffe5b4;color:#7a3e00;">'
                    . htmlspecialchars(JsonUi::text('unversioned_warning')) . '</div>';
            }

            $renderPlan($plan);

            $form = new Form('index.php?module=myshowcase-json_sync', 'post');

            echo $form->generate_hidden_field('my_post_key', $mybb->post_code);

            foreach (['action' => 'run', 'mode' => $mode, 'type' => $type, 'id' => (string)$id, 'file' => $file] + $options as $name => $value) {
                if (!in_array($name, ['collection_id', 'showcase_id'], true)) {
                    echo $form->generate_hidden_field($name, (string)$value);
                }
            }

            foreach (['name', 'route_identifier', 'custom_template_prefix'] as $input) {
                if (isset($options[$input])) {
                    echo $form->generate_hidden_field($input, (string)$options[$input]);
                }
            }

            $form->output_submit_wrapper([
                $form->generate_submit_button(JsonUi::text('confirm')),
            ]);

            $form->end();

            echo '<p><a href="' . htmlspecialchars($backUrl) . '">' . htmlspecialchars(JsonUi::text('cancel')) . '</a></p>';

            $page->output_footer();

            break;
        }

        // run
        if ($mybb->request_method !== 'post') {
            $fail('error_invalid_action');
        }

        $result = $sync->apply($file, $options);

        log_admin_action([
            'type' => $type, 'id' => $id, 'file' => $file, 'mode' => $mode, 'ok' => $result['ok'] ? 1 : 0,
            'collection_id' => $result['collection_id'], 'showcase_id' => $result['showcase_id'],
        ]);

        $states = [];

        $failedId = $result['failed']['id'] ?? null;

        foreach ($result['plan']['steps'] as $step) {
            $states[$step['id']] = in_array($step['id'], $result['done'], true)
                ? JsonUi::text('step_done')
                : ($step['id'] === $failedId ? JsonUi::text('step_failed') : JsonUi::text('step_not_run'));
        }

        $page->output_header(JsonUi::text('json_sync'));

        if ($result['ok']) {
            echo '<div class="success">' . htmlspecialchars(JsonUi::text('result_ok', (string)count($result['done']))) . '</div>';
        } else {
            $page->output_inline_error([JsonUi::text('result_failed', (string)$result['error'])]);
        }

        $renderPlan($result['plan'], $states);

        echo '<p><a href="' . htmlspecialchars($backUrl) . '">' . htmlspecialchars(JsonUi::text('cancel')) . '</a></p>';

        $page->output_footer();

        break;
    default:
        admin_redirect($listUrl);
}
