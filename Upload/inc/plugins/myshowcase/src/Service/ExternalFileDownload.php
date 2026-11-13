<?php

declare(strict_types=1);

namespace MyShowcase\Service;

/**
 * A file the fetcher downloaded: its path in the temp area, its size and the URL it was finally read from. It is not yet
 * checked (see ExternalFileAcceptance).
 */
final class ExternalFileDownload
{
    public function __construct(
        public readonly string $path,
        public readonly int $bytes,
        public readonly string $final_url,
    ) {
    }
}
