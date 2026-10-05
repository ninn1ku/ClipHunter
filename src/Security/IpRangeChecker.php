<?php

declare(strict_types=1);

namespace ClipHunter\Security;

/**
 * Decides whether an IP address is a public, globally routable unicast address.
 *
 * Anything private, loopback, link-local (incl. cloud metadata 169.254.169.254), CGNAT,
 * multicast, reserved, documentation or transition-mechanism space is rejected.
 */
final class IpRangeChecker
{
    /** IPv4 special-purpose ranges (IANA registry + RFC 6598 CGNAT). */
    private const BLOCKED_V4 = [
        '0.0.0.0/8',          // "this network"
        '10.0.0.0/8',         // private
        '100.64.0.0/10',      // carrier-grade NAT
        '127.0.0.0/8',        // loopback
        '169.254.0.0/16',     // link-local, cloud metadata
        '172.16.0.0/12',      // private
        '192.0.0.0/24',       // IETF protocol assignments
        '192.0.2.0/24',       // TEST-NET-1
        '192.31.196.0/24',    // AS112
        '192.52.193.0/24',    // AMT
        '192.88.99.0/24',     // 6to4 relay anycast
        '192.168.0.0/16',     // private
        '192.175.48.0/24',    // AS112 direct delegation
        '198.18.0.0/15',      // benchmarking
        '198.51.100.0/24',    // TEST-NET-2
        '203.0.113.0/24',     // TEST-NET-3
        '224.0.0.0/4',        // multicast
        '240.0.0.0/4',        // reserved + broadcast
    ];

    /** IPv6 special-purpose ranges. */
    private const BLOCKED_V6 = [
        '::/128',             // unspecified
        '::1/128',            // loopback
        '::ffff:0:0/96',      // IPv4-mapped
        '::/96',              // IPv4-compatible (deprecated)
        '64:ff9b::/96',       // NAT64 well-known prefix
        '64:ff9b:1::/48',     // local-use NAT64
        '100::/64',           // discard-only
        '2001::/23',          // IETF protocol assignments (Teredo, ORCHID, benchmarking...)
        '2001:db8::/32',      // documentation
        '2002::/16',          // 6to4
        '3fff::/20',          // documentation
        '5f00::/16',          // SRv6 SIDs
        'fc00::/7',           // unique local
        'fe80::/10',          // link-local
        'fec0::/10',          // site-local (deprecated)
        'ff00::/8',           // multicast
    ];

    public static function isPublic(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        // IPv4-mapped/-compatible IPv6 addresses are rejected outright via BLOCKED_V6: real DNS
        // answers never need them, and they are a classic way to smuggle 127.0.0.1 past filters.
        return !self::inAny($packed, strlen($packed) === 16 ? self::BLOCKED_V6 : self::BLOCKED_V4);
    }

    /**
     * @param list<string> $cidrs
     */
    private static function inAny(string $packed, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if (self::inCidr($packed, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private static function inCidr(string $packed, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr, 2);
        $networkPacked = inet_pton($network);
        if ($networkPacked === false || strlen($networkPacked) !== strlen($packed)) {
            return false;
        }

        $bits = (int) $bits;
        $fullBytes = intdiv($bits, 8);
        if (strncmp($packed, $networkPacked, $fullBytes) !== 0) {
            return false;
        }

        $remaining = $bits % 8;
        if ($remaining === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remaining)) & 0xFF;

        return (ord($packed[$fullBytes]) & $mask) === (ord($networkPacked[$fullBytes]) & $mask);
    }
}
