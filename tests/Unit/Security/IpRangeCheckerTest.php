<?php

declare(strict_types=1);

namespace ClipHunter\Tests\Unit\Security;

use ClipHunter\Security\IpRangeChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IpRangeCheckerTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function nonPublic(): iterable
    {
        foreach ([
            '0.0.0.0', '0.1.2.3', '10.0.0.1', '10.255.255.255', '100.64.0.1', '100.127.255.254',
            '127.0.0.1', '127.255.255.255', '169.254.169.254', '169.254.0.1', '172.16.0.1', '172.31.255.255',
            '192.0.0.1', '192.0.2.10', '192.88.99.1', '192.168.1.1', '198.18.0.1', '198.19.255.255',
            '198.51.100.7', '203.0.113.9', '224.0.0.1', '239.255.255.250', '240.0.0.1', '255.255.255.255',
            '::', '::1', '::ffff:127.0.0.1', '::ffff:8.8.8.8', '::127.0.0.1', '64:ff9b::7f00:1',
            '2001::1', '2001:db8::1', '2002:7f00:1::', 'fc00::1', 'fd12:3456::1', 'fe80::1', 'fec0::1', 'ff02::1',
            'not-an-ip', '', '999.1.1.1', '1.2.3',
        ] as $ip) {
            yield $ip === '' ? '(empty)' : $ip => [$ip];
        }
    }

    #[DataProvider('nonPublic')]
    public function testRejectsNonPublicAddresses(string $ip): void
    {
        self::assertFalse(IpRangeChecker::isPublic($ip));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function public(): iterable
    {
        foreach (['8.8.8.8', '1.1.1.1', '142.250.74.46', '193.233.198.66', '172.15.255.255', '172.32.0.1',
            '100.63.255.255', '100.128.0.1', '2a00:1450:4001:82a::200e', '2606:4700::6810:84e5'] as $ip) {
            yield $ip => [$ip];
        }
    }

    #[DataProvider('public')]
    public function testAcceptsPublicAddresses(string $ip): void
    {
        self::assertTrue(IpRangeChecker::isPublic($ip));
    }
}
