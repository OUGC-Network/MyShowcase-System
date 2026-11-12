<?php

declare(strict_types=1);

namespace MyShowcase\Util;

use Random\RandomException;

class UuidUtil
{
    /**
     * Whether $value has exactly the shape generateUuidV4() produces: 36 characters, lowercase hex in the 8-4-4-4-12
     * groups, version 4 and variant 10. Used to accept a post hash that comes back from a form.
     */
    public function isValidUuidV4(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
    }

    /**
     * @throws RandomException
     */
    //https://stackoverflow.com/a/15875555
    public function generateUuidV4(): string
    {
        $data = random_bytes(16);

        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100

        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * RFC 9562 version 7: the first 48 bits are the Unix time in milliseconds, so values sort by creation time
     * (to the millisecond); the rest is random apart from the version and variant bits.
     *
     * @throws RandomException
     */
    public function generateUuidV7(): string
    {
        $milliseconds = (int)floor(microtime(true) * 1000);

        $data = hex2bin(str_pad(dechex($milliseconds), 12, '0', STR_PAD_LEFT)) . random_bytes(10);

        $data[6] = chr(ord($data[6]) & 0x0f | 0x70); // set version to 0111

        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}