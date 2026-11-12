<?php

declare(strict_types=1);

namespace MyShowcase\Controller;

use MyShowcase\Enum\ContentStatuses;
use MyShowcase\Enum\Routes;
use MyShowcase\Enum\UserPermissions;
use MyShowcase\Repository\ExportsRepository;
use MyShowcase\Service\EntriesExporter;
use MyShowcase\Service\ExportException;
use MyShowcase\Service\ExportsManager;
use MyShowcase\Service\ExportsStorage;
use MyShowcase\Util\ImportLimits;
use MyShowcase\Util\PluginLibraryLoader;
use MyShowcase\Util\XmlFile;
use PluginLibrary;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

use function MyShowcase\Plugin\Functions\hooksRun;

/**
 * Export of entries (design doc, sections 4, 5, 8 and 9): the form and the user's stored exports, generating one,
 * its detail page, the download and the deletion. Everything needs the can-export permission of the showcase, and an
 * export is only ever visible to its creator: every lookup of a stored export is made by id AND creator.
 *
 * Nothing that changes data answers a GET: generating and deleting are POSTs checked with my_post_key.
 */
class ExportController extends BaseController
{
    private ExportsRepository $exports_repository;

    private ExportsManager $exports_manager;

    private ExportsStorage $storage;

    private EntriesExporter $exporter;

    /**
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError
     */
    public function main(array $form = []): string
    {
        $library = $this->start();

        if (is_string($library)) {
            return $this->libraryProblemPage($library);
        }

        return $this->mainPage($form);
    }

    /**
     * Generates an export from the posted form.
     *
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError
     */
    public function create(): string
    {
        $library = $this->start();

        if (is_string($library)) {
            return $this->libraryProblemPage($library);
        }

        verify_post_check($this->context->mybb->get_input('my_post_key'));

        $input = $this->context->mybb->input;

        $user_id = (int)$this->context->current_user->uid;

        $showcase_id = (int)$this->context->showcase->showcase_id;

        $flood_seconds = (int)$this->context->showcase->import_export_flood_seconds;

        if ($flood_seconds > 0) {
            $recent = $this->exports_repository->queryExport([
                "user_id='{$user_id}'",
                "showcase_id='{$showcase_id}'",
                "created_at>'" . gmdate('Y-m-d H:i:s', TIME_NOW - $flood_seconds) . "'",
            ]);

            if ($recent !== []) {
                $wait = $flood_seconds - (TIME_NOW - (int)strtotime($recent['created_at'] . ' UTC'));

                return $this->mainPage(
                    $input,
                    [$this->context->language->sprintf(
                        $this->context->language->myshowcase_import_export_export_flood,
                        max(1, $wait)
                    )]
                );
            }
        }

        $maximum_stored = (int)$this->context->showcase->exports_per_user_maximum;

        if ($maximum_stored > 0 && $this->storedExportsCount() >= $maximum_stored) {
            return $this->mainPage(
                $input,
                [$this->context->language->sprintf(
                    $this->context->language->myshowcase_import_export_export_cap_reached,
                    $maximum_stored
                )]
            );
        }

        $rules = $this->exporter->rulesFromInput($input);

        try {
            $built = $this->exporter->build($rules, new XmlFile($library));

            $export_id = $this->exports_manager->createExport(
                [
                    'showcase_id' => $showcase_id,
                    'collection_id' => (int)$this->context->showcase->collection_id,
                    'user_id' => $user_id,
                    'route_identifier' => (string)$this->context->showcase->route_identifier,
                    'entry_count' => $built['entry_count'],
                    'rules' => $built['rules'],
                ],
                $built['xml'],
                TIME_NOW
            );
        } catch (ExportException $exception) {
            return $this->mainPage($input, [$this->exportErrorText($exception)]);
        }

        $message = $this->context->language->sprintf(
            $this->context->language->myshowcase_import_export_export_done,
            $built['entry_count']
        );

        if ((int)($input['limit'] ?? 0) > $rules['limit_cap']) {
            $message .= ' ' . $this->context->language->sprintf(
                $this->context->language->myshowcase_import_export_export_limit_clamped,
                $rules['limit_cap']
            );
        }

        $this->redirectTo(Routes::ExportView, $message, ['export_id' => $export_id]);
    }

    /**
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError
     */
    public function view(int $export_id): string
    {
        $library = $this->start();

        if (is_string($library)) {
            return $this->libraryProblemPage($library);
        }

        $export = $this->ownExport($export_id);

        add_breadcrumb(
            $this->context->language->myshowcase_import_export_title_export,
            $this->context->showcase->showcase_url . $this->context->routing->generateUrl(Routes::ExportMain)
        );

        return $this->context->render_manager->templateGet('pageExportView', [
            'export' => $this->presentExport($export),
            'url' => ['export_main' => $this->context->routing->generateUrl(Routes::ExportMain)],
        ]);
    }

    /**
     * Sends the file. Found by id and creator, the permission is checked again, and the file must still match its
     * recorded hash.
     */
    public function download(int $export_id): never
    {
        $library = $this->start();

        if (is_string($library)) {
            $this->error($this->context->language->{'myshowcase_import_export_error_' . $library});
        }

        $export = $this->ownExport($export_id);

        $path = $export['file_deleted'] ? null : $this->storage->pathOf((string)$export['file_name']);

        if ($path === null || !hash_equals((string)$export['file_hash'], (string)hash_file('sha256', $path))) {
            $this->error($this->context->language->myshowcase_import_export_error_not_found, 404);
        }

        $time = (int)strtotime($export['created_at'] . ' UTC');

        $download_name = 'myshowcase-' . preg_replace('/[^a-z0-9_-]/', '', strtolower((string)$this->context->showcase->route_identifier)) .
            '-' . gmdate('Ymd-His', $time) . '.xml';

        header('Content-Type: application/xml; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $download_name . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');

        readfile($path);

        exit;
    }

    public function delete(int $export_id): never
    {
        $library = $this->start();

        if (is_string($library)) {
            $this->error($this->context->language->{'myshowcase_import_export_error_' . $library});
        }

        // Deleting changes data: only a POST with a valid key does it, never a link.
        if ($this->context->mybb->request_method !== 'post') {
            $this->error($this->context->language->myshowcase_import_export_error_post_required, 405);
        }

        verify_post_check($this->context->mybb->get_input('my_post_key'));

        $this->exports_manager->deleteExport($this->ownExport($export_id));

        $this->redirectTo(
            Routes::ExportMain,
            $this->context->language->myshowcase_import_export_export_deleted
        );
    }

    /**
     * Everything an export action starts with: the showcase password, the user and the permission, and the library.
     *
     * @return PluginLibrary|string the library, or the problem that stops the export
     */
    private function start(): PluginLibrary|string
    {
        $this->verifyPassword();

        if ($this->context->current_user->uid < 1 ||
            !$this->context->current_user->hasPermission(UserPermissions::EntriesCanExport)) {
            $this->errorNoPermission();
        }

        $this->exports_repository = new ExportsRepository($this->context->database);

        $this->storage = new ExportsStorage();

        $this->exports_manager = new ExportsManager($this->exports_repository, $this->storage);

        $this->exporter = new EntriesExporter($this->context);

        $this->breadcrumbs();

        $this->context->language->load('datahandler_myshowcase', false, true);

        return (new PluginLibraryLoader())->load();
    }

    private function breadcrumbs(): void
    {
        add_breadcrumb(
            $this->context->showcase->name,
            $this->context->showcase->showcase_url . $this->context->routing->generateUrl(Routes::Main)
        );
    }

    /**
     * A stored export of the current user in this showcase. Anything else, another user's export included, is "not found".
     *
     * @return array<string, mixed>
     */
    private function ownExport(int $export_id): array
    {
        $export = $this->exports_repository->queryExport([
            "export_id='{$export_id}'",
            "user_id='" . (int)$this->context->current_user->uid . "'",
            "showcase_id='" . (int)$this->context->showcase->showcase_id . "'",
        ]);

        if ($export === []) {
            $this->error($this->context->language->myshowcase_import_export_error_not_found, 404);
        }

        return $export;
    }

    private function storedExportsCount(): int
    {
        return $this->exports_repository->queryExportCount([
            "user_id='" . (int)$this->context->current_user->uid . "'",
            "showcase_id='" . (int)$this->context->showcase->showcase_id . "'",
            "file_deleted='0'",
        ]);
    }

    /**
     * @param array<string, mixed> $form what the form had, to show it again after an error
     * @param string[] $errors
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError
     */
    private function mainPage(array $form = [], array $errors = []): string
    {
        $this->context->render_manager->error_messages = array_merge(
            $this->context->render_manager->error_messages,
            $errors
        );

        $rules = $this->exporter->rulesFromInput($form);

        $field_choices = [];

        foreach ($this->exporter->selectableKeys() as $key) {
            $field_choices[$key] = $this->keyLabel($key);
        }

        $exports = [];

        foreach (
            $this->exports_repository->queryExports(
                [
                    "user_id='" . (int)$this->context->current_user->uid . "'",
                    "showcase_id='" . (int)$this->context->showcase->showcase_id . "'",
                ],
                [],
                ['order_by' => 'created_at DESC, export_id', 'order_dir' => 'DESC']
            ) as $export
        ) {
            $exports[] = $this->presentExport($export);
        }

        hooksRun('controller_export_main', $this);

        return $this->context->render_manager->templateGet('pageExport', [
            'form' => [
                'limit' => $rules['limit'],
                'limit_cap' => $rules['limit_cap'],
                'order_by' => $rules['order_by'],
                'order_dir' => $rules['order_dir'],
                'selected' => $form === [] ? array_keys($field_choices) : $rules['fields'],
                'options' => $rules['options'],
                'sort_choices' => $this->exporter->sortChoices(),
                'field_choices' => $field_choices,
                'can_include_awaiting' => $this->context->entries_manager->canViewStatus(ContentStatuses::AwaitingApproval),
                'can_include_deleted' => $this->context->entries_manager->canViewStatus(ContentStatuses::SoftDeleted),
                'is_moderator' => $this->context->entries_manager->moderatesShowcase(),
            ],
            'exports' => $exports,
            'size_cap' => get_friendly_size(ImportLimits::sizeCap()),
            'storage_inside_web_root' => $this->storage->isInsideWebRoot(),
            'url' => ['export_main' => $this->context->routing->generateUrl(Routes::ExportMain)],
            'stored_count' => $this->storedExportsCount(),
            'stored_maximum' => (int)$this->context->showcase->exports_per_user_maximum,
        ]);
    }

    /**
     * An export row ready for the templates: its rules in words, its sizes and dates formatted, its links.
     *
     * @param array<string, mixed> $export
     * @return array<string, mixed>
     */
    private function presentExport(array $export): array
    {
        $rules = json_decode((string)$export['rules'], true) ?: [];

        $sort_choices = $this->exporter->sortChoices();

        $order_by = (string)($export['order_by'] ?: ($rules['order_by'] ?? ''));

        $routing = $this->context->routing;

        $export_id = (int)$export['export_id'];

        return [
            'export_id' => $export_id,
            'created' => my_date('normal', (int)strtotime($export['created_at'] . ' UTC')),
            'entry_count' => (int)$export['entry_count'],
            'size' => get_friendly_size((int)$export['file_size']),
            'file_deleted' => (bool)$export['file_deleted'],
            'rules' => [
                'limit' => (int)($rules['limit'] ?? $export['entry_limit']),
                'limit_cap' => (int)($rules['limit_cap'] ?? 0),
                'order_by' => $sort_choices[$order_by] ?? $order_by,
                'order_dir' => ($rules['order_dir'] ?? $export['order_direction']) === 'asc' ? 'asc' : 'desc',
                'fields' => array_map(
                    fn(string $key): string => $this->keyLabel($key),
                    $rules['fields'] ?? array_filter(explode(',', (string)$export['selected_fields']))
                ),
                'options' => [
                    'include_awaiting_approval' => !empty($rules['options']['include_awaiting_approval']),
                    'include_soft_deleted' => !empty($rules['options']['include_soft_deleted']),
                ],
                'removed_characters' => (int)($rules['removed_characters'] ?? 0),
            ],
            'url' => [
                'view' => $routing->generateUrl(Routes::ExportView, ['export_id' => $export_id]),
                'download' => $routing->generateUrl(Routes::ExportDownload, ['export_id' => $export_id]),
                'delete' => $routing->generateUrl(Routes::ExportDelete, ['export_id' => $export_id]),
            ],
        ];
    }

    private function keyLabel(string $key): string
    {
        $language = $this->context->language;

        if ($key === 'subject') {
            return $language->myshowcase_import_export_field_subject;
        }

        if ($key === 'message') {
            return $language->myshowcase_import_export_field_message;
        }

        foreach ($this->context->custom_fields_collection as $field) {
            if ($field->field_key === $key) {
                return $this->context->entries_manager->fieldLabel($field);
            }
        }

        return $key;
    }

    private function exportErrorText(ExportException $exception): string
    {
        $language = $this->context->language;

        return match ($exception->reason) {
            ExportException::REASON_TOO_LARGE => $language->sprintf(
                $language->myshowcase_import_export_export_too_large,
                my_number_format($exception->entries_that_fit),
                get_friendly_size(ImportLimits::sizeCap())
            ),
            ExportException::REASON_STORAGE => $language->myshowcase_import_export_export_storage,
            default => $language->myshowcase_import_export_export_empty,
        };
    }

    private function libraryProblemPage(string $problem): string
    {
        $language = $this->context->language;

        return $this->context->render_manager->templateGet('pageImportExportMissingLibrary', [
            'library_error' => $problem === PluginLibraryLoader::PROBLEM_VERSION
                ? $language->sprintf(
                    $language->myshowcase_import_export_error_library_version,
                    PluginLibraryLoader::MINIMUM_VERSION
                )
                : $language->myshowcase_import_export_error_library_missing,
        ]);
    }

    /**
     * Redirects with a message to one of the export routes.
     *
     * @param array<string, mixed> $parameters
     */
    private function redirectTo(string $route, string $message, array $parameters = []): never
    {
        redirect(
            $this->context->showcase->showcase_url . $this->context->routing->generateUrl($route, $parameters),
            $message
        );

        exit;
    }
}
