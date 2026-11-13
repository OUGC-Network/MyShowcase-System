<?php

declare(strict_types=1);

namespace MyShowcase\Service;

use MyShowcase\Util\ExternalFileException;
use MyShowcase\Util\ExternalFileLimits;

/**
 * The limits of one import as a whole (decision D53, section 5.8.5): at most 25 files, 100 MB downloaded, and a time
 * budget that keeps the import below PHP's max_execution_time. The clock is injected, so the rules are tested with time
 * that is set by the test.
 */
final class ExternalFileBudget
{
    private int $files = 0;

    private int $bytes = 0;

    private readonly float $deadline;

    /**
     * @param int $time_budget the seconds the import may spend downloading
     * @param float $started the moment the import started (microtime(true))
     */
    public function __construct(int $time_budget, float $started)
    {
        $this->deadline = $started + $time_budget;
    }

    /**
     * Whether an import with this many files may start at all: the file count and the time budget must both fit.
     */
    public static function fits(int $files, int $time_budget): bool
    {
        return $files <= ExternalFileLimits::MAX_FILES && $time_budget >= ExternalFileLimits::MIN_TIME_BUDGET;
    }

    /** @throws ExternalFileException with reason 'time' when the budget is spent */
    public function secondsLeft(float $now): int
    {
        $left = (int)floor($this->deadline - $now);

        if ($left < 1) {
            throw new ExternalFileException('time');
        }

        return min($left, ExternalFileLimits::FILE_TIMEOUT);
    }

    /** @throws ExternalFileException with reason 'budget' when the file would pass the count limit */
    public function reserveFile(): void
    {
        if ($this->files + 1 > ExternalFileLimits::MAX_FILES) {
            throw new ExternalFileException('budget');
        }

        $this->files++;
    }

    /**
     * @throws ExternalFileException with reason 'budget' when the bytes would pass the download budget
     */
    public function addBytes(int $bytes): void
    {
        if ($this->bytes + $bytes > ExternalFileLimits::BUDGET_BYTES) {
            throw new ExternalFileException('budget');
        }

        $this->bytes += $bytes;
    }

    public function files(): int
    {
        return $this->files;
    }

    public function bytes(): int
    {
        return $this->bytes;
    }
}
