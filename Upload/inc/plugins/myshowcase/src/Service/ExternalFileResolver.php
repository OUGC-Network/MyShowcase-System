<?php

declare(strict_types=1);

namespace MyShowcase\Service;

/**
 * The addresses of a host name (A and AAAA records). Production: DnsExternalFileResolver. Tests: a fake.
 */
interface ExternalFileResolver
{
    /** @return string[] the addresses the name has now, or [] when it has none */
    public function addresses(string $host): array;
}
