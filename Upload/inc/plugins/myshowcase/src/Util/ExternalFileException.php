<?php

declare(strict_types=1);

namespace MyShowcase\Util;

use RuntimeException;

/**
 * A file that an import could not take from its URL (decision D53). The reason is a code, never a message from the
 * network: a message never carries a resolved address, a response body, a header or a path.
 *
 * Reasons: scheme, port, userinfo, host, characters, length, fragment, private_address, dns, tls, connect, redirects,
 * size, time, status, type, content, budget, disabled, curl_missing, unknown.
 */
final class ExternalFileException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
