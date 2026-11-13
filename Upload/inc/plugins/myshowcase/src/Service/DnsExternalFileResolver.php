<?php

declare(strict_types=1);

namespace MyShowcase\Service;

/**
 * The production resolver: the A and AAAA records of a name, through the system's resolver.
 */
final class DnsExternalFileResolver implements ExternalFileResolver
{
    public function addresses(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if (!is_array($records)) {
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $addresses[] = (string)$record['ip'];
            } elseif (isset($record['ipv6'])) {
                $addresses[] = (string)$record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}
