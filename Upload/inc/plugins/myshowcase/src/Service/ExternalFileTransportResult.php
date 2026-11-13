<?php

declare(strict_types=1);

namespace MyShowcase\Service;

/**
 * What one request returned: the HTTP status, the Location of a redirect, the number of body bytes written, and the reason
 * code of a transfer that failed before a response was complete (null when it did not fail).
 */
final class ExternalFileTransportResult
{
    public function __construct(
        public readonly int $status,
        public readonly ?string $location = null,
        public readonly int $bytes = 0,
        public readonly ?string $error = null,
    ) {
    }
}
