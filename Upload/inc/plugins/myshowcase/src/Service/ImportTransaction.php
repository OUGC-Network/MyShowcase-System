<?php

declare(strict_types=1);

namespace MyShowcase\Service;

use RuntimeException;
use Throwable;

/**
 * The write side of an import: one transaction around every entry, and a compensating delete behind it (design doc,
 * section D and decision T27).
 *
 * MyBB has no transaction API on any engine, so the statements are sent as they are: START TRANSACTION on MySQL,
 * BEGIN on PostgreSQL and SQLite. The entry tables are InnoDB on MySQL (the plugin converts them on activation), so the
 * ROLLBACK undoes the inserts. Where a rollback cannot undo a row (a table that is not transactional, or a row written
 * after the database has already committed), the inserted entry ids are deleted directly, by id, never through the
 * controller's delete path (which fires events). A shutdown function does the same when the request ends before COMMIT
 * (an exit on a MySQL error, a fatal error): PHP closes the connection then, which ends an open transaction anyway.
 *
 * Every row an import writes carries the import's batch hash as its post_hash, so the same delete also finds a row whose
 * id was not recorded because the request ended between its insert and the record.
 *
 * The files an import stores (an attachment and its thumbnail) are recorded here too, and rollback() and the shutdown
 * guard remove them with the rows (decision D59). Only a file directly in the showcase's upload folder is removed, never
 * a link, never a path elsewhere.
 */
final class ImportTransaction
{
    private const BEGIN = [
        SchemaManager::ENGINE_MYSQL => 'START TRANSACTION',
        SchemaManager::ENGINE_PGSQL => 'BEGIN',
        SchemaManager::ENGINE_SQLITE => 'BEGIN',
    ];

    private bool $open = false;

    private bool $finished = false;

    private bool $guard_registered = false;

    /** @var int[] */
    private array $inserted = [];

    /** @var int[] */
    private array $attachments = [];

    /** @var string[] the paths of the files stored by this import */
    private array $files = [];

    /**
     * @param string $engine SchemaManager::ENGINE_* of the board's database
     * @param string $uploads_path the showcase's attachment upload folder; the only folder whose files are removed
     */
    public function __construct(
        private readonly object $database,
        private readonly int $showcase_id,
        private readonly string $batch_hash,
        private readonly string $engine,
        private readonly string $uploads_path = '',
    ) {
    }

    /**
     * @throws RuntimeException when a transaction is already open (the import never nests one)
     */
    public function begin(): void
    {
        if ($this->open) {
            throw new RuntimeException('An import transaction is already open.');
        }

        $this->database->write_query(self::BEGIN[$this->engine] ?? self::BEGIN[SchemaManager::ENGINE_MYSQL]);

        $this->open = true;

        if (!$this->guard_registered) {
            $this->guard_registered = true;

            register_shutdown_function([$this, 'abandonIfOpen']);
        }
    }

    /** Remembers an entry the transaction inserted, for the compensating delete. */
    public function record(int $entry_id): void
    {
        $this->inserted[] = $entry_id;
    }

    /** Remembers an attachment the transaction inserted (its row is removed with the entries). */
    public function recordAttachment(int $attachment_id): void
    {
        $this->attachments[] = $attachment_id;
    }

    /** Remembers a file the import stored (an attachment or its thumbnail), removed by rollback and by the shutdown guard. */
    public function recordFile(string $path): void
    {
        $this->files[] = $path;
    }

    /**
     * @throws Throwable when the COMMIT fails; the caller then rolls back
     */
    public function commit(): void
    {
        if (!$this->open) {
            throw new RuntimeException('No import transaction is open.');
        }

        $this->database->write_query('COMMIT');

        $this->open = false;

        $this->finished = true;

        $this->inserted = [];

        // The stored files stay: the import is complete.
        $this->files = [];
    }

    /** Undoes everything the import wrote, whether the transaction is still open or not. */
    public function rollback(): void
    {
        try {
            if ($this->open) {
                $this->open = false;

                $this->database->write_query('ROLLBACK');
            }

            $this->deleteWritten();
        } finally {
            $this->deleteFiles();

            $this->finished = true;
        }
    }

    /**
     * Shutdown guard: a request that ends before COMMIT has written nothing that should stay, and stored no file that
     * should stay (a timeout or a fatal error does not reach rollback()).
     */
    public function abandonIfOpen(): void
    {
        if ($this->finished || (!$this->open && $this->inserted === [] && $this->files === [])) {
            return;
        }

        try {
            if ($this->open) {
                $this->open = false;

                $this->database->write_query('ROLLBACK');
            }

            $this->deleteWritten();
        } catch (Throwable) {
            // The request is ending; the connection closes now and an open transaction ends with it.
        } finally {
            $this->deleteFiles();
        }

        $this->finished = true;
    }

    /**
     * Removes the recorded files that lie directly in the upload folder. A link is never followed, a missing file is
     * ignored, and a path anywhere else is never touched.
     */
    private function deleteFiles(): void
    {
        $folder = $this->uploads_path === '' ? false : realpath($this->uploads_path);

        foreach (array_unique($this->files) as $path) {
            if ($folder === false || is_link($path) || !is_file($path)) {
                continue;
            }

            $directory = realpath(dirname($path));

            if ($directory !== $folder) {
                continue;
            }

            @unlink($path);
        }

        $this->files = [];
    }

    /**
     * Deletes the rows of this import: the recorded ids, and any row carrying the batch hash.
     */
    private function deleteWritten(): void
    {
        $table = 'myshowcase_data' . $this->showcase_id;

        $conditions = ["post_hash='" . $this->escape($this->batch_hash) . "'"];

        if ($this->inserted !== []) {
            $conditions[] = 'entry_id IN (' . implode(',', array_map('intval', array_unique($this->inserted))) . ')';
        }

        $this->database->delete_query($table, implode(' OR ', $conditions));

        if ($this->attachments !== []) {
            $this->database->delete_query(
                'myshowcase_attachments',
                'attachment_id IN (' . implode(',', array_map('intval', array_unique($this->attachments))) . ')'
            );
        }

        $this->inserted = [];

        $this->attachments = [];
    }

    private function escape(string $value): string
    {
        return $this->database->escape_string($value);
    }
}
