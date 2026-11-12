<?php

declare(strict_types=1);

namespace MyShowcase\Service;

use MyShowcase\Repository\ExportsRepository;

use const MyShowcase\VERSION;

/**
 * Deleting stored exports and the 30-day retention. The file and the row are always removed together by hand; the
 * scheduled task removes only the file of an old export, keeps the row as history, and drops that row later.
 */
class ExportsManager
{
    public function __construct(
        private readonly ExportsRepository $exports_repository,
        private readonly ExportsStorage $storage,
    ) {
    }

    /**
     * Stores a generated export: a row for the creator, then the file under the name that contains the row's id.
     *
     * @param array{showcase_id: int, collection_id: int, user_id: int, route_identifier: string, entry_count: int, rules: array<string, mixed>} $meta
     * @return int The id of the new export.
     * @throws ExportException when the file cannot be written
     */
    public function createExport(array $meta, string $xml, int $now): int
    {
        $rules = $meta['rules'];

        $export_id = $this->exports_repository->insertExport([
            'showcase_id' => $meta['showcase_id'],
            'collection_id' => $meta['collection_id'],
            'user_id' => $meta['user_id'],
            'created_at' => gmdate('Y-m-d H:i:s', $now),
            // Replaced below; unique until then.
            'file_name' => 'pending-' . bin2hex(random_bytes(16)),
            'entry_count' => $meta['entry_count'],
            'entry_limit' => $rules['limit'],
            'order_by' => $rules['order_by'],
            'order_direction' => $rules['order_dir'],
            // The column is short; the complete list is in the rules.
            'selected_fields' => mb_substr(implode(',', $rules['fields']), 0, 1000),
            'rules' => json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'plugin_version' => VERSION,
        ]);

        $file_name = $this->storage->fileName($export_id, $meta['route_identifier'], $now);

        if (!$this->storage->write($file_name, $xml)) {
            $this->exports_repository->deleteExport($export_id);

            throw new ExportException(ExportException::REASON_STORAGE);
        }

        $this->exports_repository->updateExport($export_id, [
            'file_name' => $file_name,
            'file_size' => strlen($xml),
            'file_hash' => hash('sha256', $xml),
        ]);

        return $export_id;
    }

    /**
     * Deletes one export: its file, then its row.
     *
     * @param array<string, mixed> $export_data A row of myshowcase_exports.
     */
    public function deleteExport(array $export_data): void
    {
        $this->storage->delete((string)$export_data['file_name']);

        $this->exports_repository->deleteExport((int)$export_data['export_id']);
    }

    /**
     * Retention: files of exports created more than $retention_days ago are deleted and their rows marked file_deleted;
     * rows that were marked and are older than twice that are removed. 0 days keeps everything.
     *
     * @return array{files: int, rows: int}
     */
    public function purgeExpired(int $retention_days, int $now): array
    {
        $result = ['files' => 0, 'rows' => 0];

        if ($retention_days < 1) {
            return $result;
        }

        $files_before = gmdate('Y-m-d H:i:s', $now - $retention_days * 86400);

        $rows_before = gmdate('Y-m-d H:i:s', $now - 2 * $retention_days * 86400);

        $expired = iterator_to_array(
            $this->exports_repository->queryExports(
                ["file_deleted='0'", "created_at<'{$files_before}'"],
                ['export_id', 'file_name']
            ),
            false
        );

        foreach ($expired as $export_data) {
            $this->storage->delete((string)$export_data['file_name']);

            $this->exports_repository->updateExport((int)$export_data['export_id'], ['file_deleted' => 1]);

            ++$result['files'];
        }

        $old_rows = iterator_to_array(
            $this->exports_repository->queryExports(
                ["file_deleted='1'", "created_at<'{$rows_before}'"],
                ['export_id']
            ),
            false
        );

        foreach ($old_rows as $export_data) {
            $this->exports_repository->deleteExport((int)$export_data['export_id']);

            ++$result['rows'];
        }

        return $result;
    }
}
