<?php

declare(strict_types=1);

namespace MyShowcase\Service;

use MyShowcase\Util\ExternalUrl;

/**
 * One HTTP(S) request to one address, the body streamed into a file. It never follows a redirect (the fetcher does, and
 * checks every hop), sends no cookie and no credential, and fails with a reason code rather than a message.
 *
 * Production: CurlExternalFileTransport. Tests: a fake that writes a given body.
 */
interface ExternalFileTransport
{
    /**
     * @param string $address the address the connection must use (the one the fetcher validated)
     * @param string $target_path the file the body is written to
     * @param int $max_bytes the body size limit: a larger body stops the transfer with the reason 'size'
     * @param int $total_seconds the time limit of this request
     */
    public function request(ExternalUrl $url, string $address, string $target_path, int $max_bytes, int $total_seconds): ExternalFileTransportResult;
}
