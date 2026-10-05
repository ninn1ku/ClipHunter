<?php

declare(strict_types=1);

namespace ClipHunter\Tests\Unit\Media;

use ClipHunter\Media\PlatformRegistry;
use PHPUnit\Framework\TestCase;

final class PlatformRegistryTest extends TestCase
{
    private static function registry(): PlatformRegistry
    {
        return PlatformRegistry::fromFile(dirname(__DIR__, 3) . '/config/platforms.php');
    }

    public function testShippedConfigLoads(): void
    {
        self::assertGreaterThanOrEqual(8, count(self::registry()->all()));
    }

    public function testGenericExtractorIsNeverAllowed(): void
    {
        foreach (self::registry()->all() as $platform) {
            foreach ($platform->extractors as $extractor) {
                self::assertStringNotContainsStringIgnoringCase('generic', $extractor, $platform->key);
                self::assertNotSame('all', strtolower($extractor));
                self::assertNotSame('default', strtolower($extractor));
            }
        }
    }

    public function testHostMatchingRequiresADomainBoundary(): void
    {
        $registry = self::registry();

        self::assertSame('youtube', $registry->forHost('youtube.com')?->key);
        self::assertSame('youtube', $registry->forHost('m.youtube.com')?->key);
        self::assertSame('youtube', $registry->forHost('youtu.be')?->key);
        self::assertSame('tiktok', $registry->forHost('vm.tiktok.com')?->key);
        self::assertSame('twitter', $registry->forHost('x.com')?->key);
        self::assertNull($registry->forHost('notyoutube.com'));
        self::assertNull($registry->forHost('youtube.com.evil.example'));
        self::assertNull($registry->forHost('evilx.com'));
    }
}
