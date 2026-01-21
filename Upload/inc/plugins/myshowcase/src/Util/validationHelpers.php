<?php

declare(strict_types=1);

namespace MyShowcase\Util;

readonly class validationHelpers
{
    public function validateIpAddress(string $ipaddress, bool $reject_private_reserved = false): bool
    {
        return !empty($ipaddress) && filter_var(
                $ipaddress,
                FILTER_VALIDATE_IP,
                $reject_private_reserved ? FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE : 0
            );
    }
}