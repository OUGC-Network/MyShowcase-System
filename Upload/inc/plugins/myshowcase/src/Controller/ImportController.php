<?php

declare(strict_types=1);

namespace MyShowcase\Controller;

use MyShowcase\Enum\FieldHtmlTypes;
use MyShowcase\Enum\Routes;
use MyShowcase\Enum\UserPermissions;
use MyShowcase\Service\EntriesImporter;
use MyShowcase\Service\ImportReport;
use MyShowcase\Util\ImportLimits;
use MyShowcase\Util\ImportSettings;
use MyShowcase\Util\ImportUpload;
use MyShowcase\Util\ImportUploadException;
use MyShowcase\Util\PluginLibraryLoader;
use MyShowcase\Util\XmlFile;
use PluginLibrary;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

/**
 * Import of entries from an export of the same board (design doc, sections 3, 6, 8, 11 and 12).
 *
 * The page needs the EntriesCanImport permission of the showcase and the right to post entries (the validator needs it
 * too). Nothing is stored: the upload is read from PHP's temporary file, checked, and removed on every path; the report
 * is the response to the POST. A GET never changes anything and shows only the form.
 */
class ImportController extends BaseController
{
    /** Action names of the two buttons of the form. */
    public const ACTION_VALIDATE = 'validate';

    public const ACTION_IMPORT = 'import';

    /** A group that must solve a captcha to post cannot import: the validator would ask for one per entry. */
    private const PROBLEM_CAPTCHA = 'captcha_group';

    private EntriesImporter $importer;

    /**
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError
     */
    public function main(): string
    {
        $library = $this->start();

        if (is_string($library)) {
            return $this->libraryProblemPage($library);
        }

        return $this->formPage();
    }

    /**
     * Validates or imports the posted file, and shows the report.
     *
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError
     */
    public function submit(): string
    {
        $library = $this->start();

        if (is_string($library)) {
            return $this->libraryProblemPage($library);
        }

        $file = $_FILES['entries_file'] ?? null;

        // A request over post_max_size arrives with no fields at all, not even the post key: that is a file that is too
        // large, and it is said before the key is checked.
        if (!is_array($file) && $this->requestExceedsPostLimit()) {
            return $this->formPage([$this->uploadErrorText(ImportUpload::REASON_TOO_LARGE)]);
        }

        verify_post_check($this->context->mybb->get_input('my_post_key'));

        $upload = new ImportUpload(ImportLimits::effectiveCap(), null);

        try {
            if ($flood = $this->floodProblem()) {
                return $this->formPage([$flood]);
            }

            $contents = $upload->read(is_array($file) ? $file : null);

            $report = $this->importer->run(
                $contents,
                new XmlFile($library),
                (string)($file['name'] ?? ''),
                $this->context->mybb->get_input('action') === self::ACTION_VALIDATE
            );
        } catch (ImportUploadException $exception) {
            return $this->formPage([$this->uploadErrorText($exception->reason)]);
        } finally {
            $upload->discard(is_array($file) ? $file : null);
        }

        return $this->formPage([], $report);
    }

    /**
     * Everything an import action starts with: the showcase password, the user, the permissions, the library and the
     * group's captcha rule.
     *
     * @return PluginLibrary|string the library, or the problem that stops the import
     */
    private function start(): PluginLibrary|string
    {
        $this->verifyPassword();

        if ($this->context->current_user->uid < 1 ||
            !$this->context->current_user->hasPermission(UserPermissions::EntriesCanImport) ||
            !$this->context->current_user->hasPermission(UserPermissions::EntriesCanPost)) {
            $this->errorNoPermission();
        }

        $this->importer = new EntriesImporter($this->context);

        add_breadcrumb(
            $this->context->showcase->name,
            $this->context->showcase->showcase_url . $this->context->routing->generateUrl(Routes::Main)
        );

        $this->context->language->load('datahandler_myshowcase', false, true);

        if ($this->context->current_user->hasPermission(UserPermissions::MainRequireCaptcha)) {
            return self::PROBLEM_CAPTCHA;
        }

        return (new PluginLibraryLoader())->load();
    }

    private function requestExceedsPostLimit(): bool
    {
        $limit = ImportLimits::bytesOf((string)ini_get('post_max_size'));

        return $limit > 0 && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $limit;
    }

    /**
     * The import flood check: one import per showcase and user in import_export_flood_seconds (0 disables it).
     */
    private function floodProblem(): ?string
    {
        $seconds = (int)$this->context->showcase->import_export_flood_seconds;

        if ($seconds <= 0) {
            return null;
        }

        $last = $this->lastImportTime();

        if ($last === 0 || TIME_NOW - $last >= $seconds) {
            return null;
        }

        return $this->context->language->sprintf(
            $this->context->language->myshowcase_import_export_error_flood,
            max(1, $seconds - (TIME_NOW - $last))
        );
    }

    private function lastImportTime(): int
    {
        $query = $this->context->database->simple_select(
            'myshowcase_logs',
            'log_time',
            "showcase_id='" . (int)$this->context->showcase->showcase_id . "' AND type_id='" .
            EntriesImporter::LOG_TYPE_IMPORT . "' AND user_id='" . (int)$this->context->current_user->uid . "'",
            ['order_by' => 'log_time', 'order_dir' => 'DESC', 'limit' => 1]
        );

        $time = $this->context->database->fetch_field($query, 'log_time');

        return $time ? (int)strtotime($time . ' UTC') : 0;
    }

    /**
     * @param string[] $errors
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError
     */
    private function formPage(array $errors = [], ?ImportReport $report = null): string
    {
        $this->context->render_manager->error_messages = array_merge(
            $this->context->render_manager->error_messages,
            $errors
        );

        return $this->context->render_manager->templateGet('pageImport', [
            'report' => $report === null ? null : $this->presentReport($report),
            'notes' => [
                'size' => get_friendly_size(ImportLimits::effectiveCap()),
            ],
            'external_files' => ImportSettings::externalFilesEnabled(),
            'url' => ['import_main' => $this->context->routing->generateUrl(Routes::ImportMain)],
        ]);
    }


    /**
     * The report with every code and reason turned into the language's sentence.
     *
     * @return array<string, mixed>
     */
    private function presentReport(ImportReport $report): array
    {
        $language = $this->context->language;

        $status = match ($report->status) {
            ImportReport::COMPLETED => $language->sprintf($language->myshowcase_import_export_report_completed, my_number_format($report->imported)),
            ImportReport::VALIDATED => $language->sprintf($language->myshowcase_import_export_report_validated, my_number_format($report->passed_entries)),
            default => $language->myshowcase_import_export_report_failed,
        };

        return [
            'status' => $report->status,
            'status_text' => $status,
            'file_name' => $report->file_name,
            'showcase_name' => $report->showcase_name,
            'entries_in_file' => $report->entries_in_file,
            'imported' => $report->imported,
            'passed_entries' => $report->passed_entries,
            'problems' => array_map(fn(array $problem): string => $this->problemText($problem['code'], $problem['args']), $report->problems),
            'warnings' => array_map(
                fn(array $warning): string => $this->problemText($warning['code'], $warning['args'], self::WARNING_PREFIX),
                $report->warnings
            ),
            // Every value of the file reaches the template as plain text here, and Twig escapes it there. trans() is not
            // used in the template: it returns HTML unescaped, so a subject with markup would be printed as markup.
            'entry_failures' => array_map(
                fn(array $failure): array => [
                    'text' => $language->sprintf(
                        $language->myshowcase_import_export_report_entry,
                        (int)$failure['position'],
                        $failure['subject'] !== '' ? $failure['subject'] : $language->myshowcase_import_export_report_no_subject
                    ),
                    'errors' => array_map('strval', $failure['errors']),
                ],
                $report->entry_failures
            ),
        ];
    }

    /** The language key prefix of a warning (the same codes as a problem, with their own sentences). */
    private const WARNING_PREFIX = 'myshowcase_import_export_warning_';

    /**
     * @param list<mixed> $args
     */
    private function problemText(string $code, array $args, string $prefix = 'myshowcase_import_export_problem_'): string
    {
        $language = $this->context->language;

        $key = $prefix . $code;

        if ($code === 'unknown_field' || $code === 'file_field' || $code === 'not_fillable') {
            // args: the key, the first positions, how many entries use it in all.
            $listed = count(explode(', ', (string)$args[1]));

            $more = (int)$args[2] > $listed
                ? $language->sprintf($language->myshowcase_import_export_problem_more, my_number_format((int)$args[2] - $listed))
                : '';

            return $language->sprintf($language->{$key}, (string)$args[0], (string)$args[1], $more);
        }

        $text = (string)($language->{$key} ?? $language->myshowcase_import_export_problem_unknown);

        return $args === [] ? $text : $language->sprintf($text, ...array_map('strval', $args));
    }

    private function uploadErrorText(string $reason): string
    {
        $language = $this->context->language;

        return match ($reason) {
            ImportUpload::REASON_NO_FILE => $language->myshowcase_import_export_upload_no_file,
            ImportUpload::REASON_TOO_LARGE => $language->sprintf(
                $language->myshowcase_import_export_upload_too_large,
                get_friendly_size(ImportLimits::effectiveCap())
            ),
            ImportUpload::REASON_EMPTY => $language->myshowcase_import_export_upload_empty,
            ImportUpload::REASON_EXTENSION => $language->myshowcase_import_export_upload_extension,
            ImportUpload::REASON_MIME => $language->myshowcase_import_export_upload_mime,
            ImportUpload::REASON_NOT_XML => $language->myshowcase_import_export_upload_not_xml,
            ImportUpload::REASON_MEMORY => $language->myshowcase_import_export_upload_memory,
            default => $language->myshowcase_import_export_upload_failed,
        };
    }

    private function libraryProblemPage(string $problem): string
    {
        $language = $this->context->language;

        if ($problem === self::PROBLEM_CAPTCHA) {
            return $this->formPage([$language->myshowcase_import_export_error_captcha_group]);
        }

        return $this->context->render_manager->templateGet('pageImportExportMissingLibrary', [
            'library_error' => $problem === PluginLibraryLoader::PROBLEM_VERSION
                ? $language->sprintf(
                    $language->myshowcase_import_export_error_library_version,
                    PluginLibraryLoader::MINIMUM_VERSION
                )
                : $language->myshowcase_import_export_error_library_missing,
        ]);
    }
}
