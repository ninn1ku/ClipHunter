<?php

declare(strict_types=1);

namespace ClipHunter\Tests\Unit\Watch;

use ClipHunter\Watch\VkVideoId;
use ClipHunter\Watch\WatchSourceKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VkVideoIdTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function videoUrls(): iterable
    {
        yield 'vkvideo community' => ['https://vkvideo.ru/video-22822305_456241864', '-22822305_456241864', 0];
        yield 'vkvideo user' => ['https://vkvideo.ru/video12345_456239017', '12345_456239017', 0];
        yield 'vk.com' => ['https://vk.com/video-22822305_456241864', '-22822305_456241864', 0];
        yield 'vk.ru mobile' => ['https://m.vk.ru/video-22822305_456241864', '-22822305_456241864', 0];
        yield 'trailing slash' => ['https://vkvideo.ru/video-22822305_456241864/', '-22822305_456241864', 0];
        yield 'in playlist' => ['https://vkvideo.ru/playlist/-22822305_7/video-22822305_456241864', '-22822305_456241864', 0];
        yield 'clip' => ['https://vk.com/clip-22822305_456241864', '-22822305_456241864', 0];
        yield 'z overlay' => ['https://vk.com/video?z=video-22822305_456241864%2Fpl_cat_trends', '-22822305_456241864', 0];
        yield 'z over a wall' => ['https://vk.com/wall-1_2?z=video-22822305_456241864', '-22822305_456241864', 0];
        yield 'embed' => ['https://vk.com/video_ext.php?oid=-22822305&id=456241864&hd=2', '-22822305_456241864', 0];
        yield 'embed with hash' => ['https://vkvideo.ru/video_ext.php?oid=1&id=2&hash=abcdef0123456789', '1_2', 0];
        yield 'start t' => ['https://vkvideo.ru/video-22822305_456241864?t=1m30s', '-22822305_456241864', 90];
        yield 'start seconds' => ['https://vkvideo.ru/video-22822305_456241864?t=45', '-22822305_456241864', 45];
        yield 'garbage t ignored' => ['https://vkvideo.ru/video-22822305_456241864?t=x', '-22822305_456241864', 0];
        yield 'list param kept out' => ['https://vkvideo.ru/video-22822305_456241864?list=ln-abcdef', '-22822305_456241864', 0];
    }

    #[DataProvider('videoUrls')]
    public function testParsesOwnerAndVideoId(string $url, string $ref, int $start): void
    {
        $video = VkVideoId::fromUrl($url);

        self::assertNotNull($video);
        self::assertSame($ref, $video->ref());
        self::assertSame($start, $video->startSec);
        self::assertMatchesRegularExpression(WatchSourceKind::Vk->refPattern(), $video->ref());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherUrls(): iterable
    {
        yield 'wall post' => ['https://vk.com/wall-22822305_123'];
        yield 'profile' => ['https://vk.com/durov'];
        yield 'video catalogue' => ['https://vkvideo.ru/'];
        yield 'zero owner' => ['https://vkvideo.ru/video0_1'];
        yield 'zero id' => ['https://vkvideo.ru/video-1_0'];
        yield 'garbage after id' => ['https://vkvideo.ru/video-1_2abc'];
        yield 'path traversal' => ['https://vkvideo.ru/video-1_2/../../etc'];
        yield 'embed without id' => ['https://vk.com/video_ext.php?oid=-1'];
        yield 'embed with array oid' => ['https://vk.com/video_ext.php?oid[]=-1&id=2'];
        yield 'huge number' => ['https://vkvideo.ru/video-1_12345678901234567890'];
    }

    #[DataProvider('otherUrls')]
    public function testReturnsNullForOtherLinks(string $url): void
    {
        self::assertNull(VkVideoId::fromUrl($url));
    }
}
