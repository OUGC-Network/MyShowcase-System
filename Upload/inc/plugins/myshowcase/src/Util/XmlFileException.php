<?php

declare(strict_types=1);

namespace MyShowcase\Util;

use RuntimeException;

/**
 * Why XmlFile could not read a file. The reason is one of the REASON_* codes so a caller can pick its own language
 * string; the message is a plain English description for logs and for callers that do not translate it.
 */
class XmlFileException extends RuntimeException
{
    public const REASON_DOCTYPE = 'doctype';

    public const REASON_SYNTAX = 'syntax';

    public const REASON_NOT_EXPORT = 'not_export';

    public const REASON_TOO_LARGE = 'too_large';

    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $xml_line = 0,
        public readonly string $xml_source = '',
    ) {
        parent::__construct($message);
    }
}
