<?php

declare(strict_types=1);

namespace MyShowcase\Util;

/**
 * Whether an address may be contacted by an import (decision D53, section 5.8.2). An import contacts public addresses
 * only. Refused: loopback, private, link-local (including the cloud metadata address), unique local, carrier-grade NAT,
 * 0.0.0.0/8, multicast, reserved, documentation, benchmarking and broadcast ranges; and the IPv4 address inside an
 * IPv4-mapped, IPv4-compatible or NAT64 IPv6 address, which is classified as that IPv4 address.
 *
 * 6to4 (2002::/16) and the local-use NAT64 prefix (64:ff9b:1::/48) are refused whatever they contain. Only 2000::/3 is
 * public IPv6 space.
 */
final class AddressClass
{
    /** IPv4 ranges that are not public, as network and prefix length. */
    private const IPV4_NOT_PUBLIC = [
        ['0.0.0.0', 8],
        ['10.0.0.0', 8],
        ['100.64.0.0', 10],
        ['127.0.0.0', 8],
        ['169.254.0.0', 16],
        ['172.16.0.0', 12],
        ['192.0.0.0', 24],
        ['192.0.2.0', 24],
        ['192.88.99.0', 24],
        ['192.168.0.0', 16],
        ['198.18.0.0', 15],
        ['198.51.100.0', 24],
        ['203.0.113.0', 24],
        ['224.0.0.0', 4],
        ['240.0.0.0', 4],
    ];

    /** IPv6 ranges that are not public, as network and prefix length (the embedded forms are handled before these). */
    private const IPV6_NOT_PUBLIC = [
        ['100::', 64],
        ['2001::', 23],
        ['2001:db8::', 32],
        ['2002::', 16],
        ['64:ff9b:1::', 48],
        ['fc00::', 7],
        ['fe80::', 10],
        ['fec0::', 10],
        ['ff00::', 8],
    ];

    public static function isPublic(string $address): bool
    {
        $binary = @inet_pton($address);

        if ($binary === false) {
            return false;
        }

        if (strlen($binary) === 4) {
            return self::isPublicIpv4($binary);
        }

        // IPv4-mapped (::ffff:a.b.c.d), IPv4-compatible (::a.b.c.d, which includes :: and ::1) and NAT64 (64:ff9b::/96)
        // addresses are the IPv4 address they carry.
        if (self::within($binary, self::bin('::ffff:0:0'), 96)) {
            return self::isPublicIpv4(substr($binary, 12));
        }

        if (self::within($binary, self::bin('::'), 96) || self::within($binary, self::bin('64:ff9b::'), 96)) {
            return self::isPublicIpv4(substr($binary, 12));
        }

        foreach (self::IPV6_NOT_PUBLIC as [$network, $bits]) {
            if (self::within($binary, self::bin($network), $bits)) {
                return false;
            }
        }

        return self::within($binary, self::bin('2000::'), 3);
    }

    private static function isPublicIpv4(string $binary): bool
    {
        foreach (self::IPV4_NOT_PUBLIC as [$network, $bits]) {
            if (self::within($binary, self::bin($network), $bits)) {
                return false;
            }
        }

        return true;
    }

    private static function bin(string $address): string
    {
        return (string)inet_pton($address);
    }

    /** Whether the first $bits bits of $address equal those of $network (both packed, of the same length). */
    private static function within(string $address, string $network, int $bits): bool
    {
        if (strlen($address) !== strlen($network)) {
            return false;
        }

        $whole = intdiv($bits, 8);

        if (substr($address, 0, $whole) !== substr($network, 0, $whole)) {
            return false;
        }

        $rest = $bits % 8;

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($address[$whole]) & $mask) === (ord($network[$whole]) & $mask);
    }
}
