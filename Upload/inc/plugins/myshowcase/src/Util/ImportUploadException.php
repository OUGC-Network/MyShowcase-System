<?php

declare(strict_types=1);

namespace MyShowcase\Util;

use RuntimeException;

/**
 * An uploaded import file that is refused before it is parsed. The reason is one of ImportUpload::REASON_*; the message
 * never contains a path.
 */
final class ImportUploadException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('The uploaded file was refused: ' . $reason);
    }
}
