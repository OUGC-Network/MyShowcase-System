<?php

declare(strict_types=1);

namespace MyShowcase\Service;

use RuntimeException;

/**
 * Why an export was not made. The reason picks the language string; $entries_that_fit tells, for REASON_TOO_LARGE, how
 * many entries would have fitted in the import size cap.
 */
class ExportException extends RuntimeException
{
    public const REASON_EMPTY = 'export_empty';

    public const REASON_TOO_LARGE = 'export_too_large';

    public const REASON_STORAGE = 'export_storage';

    public function __construct(
        public readonly string $reason,
        public readonly int $entries_that_fit = 0,
    ) {
        parent::__construct($reason);
    }
}
