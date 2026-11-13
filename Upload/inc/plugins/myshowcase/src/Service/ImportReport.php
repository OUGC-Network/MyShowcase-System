<?php

declare(strict_types=1);

namespace MyShowcase\Service;

/**
 * The result of an import, as the import page shows it (design doc, section 11).
 *
 * - COMPLETED: every entry was validated and written, in one transaction;
 * - FAILED: nothing was written. Either the file failed a structural check (problems), or one or more entries were refused
 *   by the validator (entry_failures), or the user is over the entry limit, or the write itself failed;
 * - VALIDATED: "validate only" was asked: every entry was checked, nothing was written.
 *
 * The problems are codes with arguments (see ImportFileCheck); the entry failures carry the validator's own messages,
 * already in the user's language. The object holds no paths and no XML.
 */
final class ImportReport
{
    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const VALIDATED = 'validated';

    /**
     * @param list<array{code: string, args: list<mixed>}> $problems
     * @param list<array{position: int, subject: string, errors: list<string>}> $entry_failures
     * @param list<array{code: string, args: list<mixed>}> $warnings
     */
    public function __construct(
        public readonly string $status,
        public readonly string $file_name,
        public readonly string $showcase_name,
        public readonly int $entries_in_file,
        public readonly int $imported,
        public readonly array $problems = [],
        public readonly array $entry_failures = [],
        public readonly array $warnings = [],
        public readonly int $passed_entries = 0,
    ) {
    }

    /** A report for a file that failed before any entry was looked at. */
    public static function refused(string $file_name, string $showcase_name, array $problems): self
    {
        return new self(self::FAILED, $file_name, $showcase_name, 0, 0, $problems);
    }

    public function failed(): bool
    {
        return $this->status === self::FAILED;
    }

    /**
     * @return array{status: string, file_name: string, showcase_name: string, entries_in_file: int, imported: int, passed_entries: int, problems: list<array{code: string, args: list<mixed>}>, entry_failures: list<array{position: int, subject: string, errors: list<string>}>, warnings: list<array{code: string, args: list<mixed>}>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'file_name' => $this->file_name,
            'showcase_name' => $this->showcase_name,
            'entries_in_file' => $this->entries_in_file,
            'imported' => $this->imported,
            'passed_entries' => $this->passed_entries,
            'problems' => $this->problems,
            'entry_failures' => $this->entry_failures,
            'warnings' => $this->warnings,
        ];
    }
}
