<?php

declare(strict_types=1);

namespace ClipHunter\Tests\Unit\Watch;

use ClipHunter\Exception\ApiException;
use ClipHunter\Watch\YouTubeId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class YouTubeIdTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function videoUrls(): iterable
    {
        yield 'watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ', 0];
        yield 'watch with extra params' => ['https://youtube.com/watch?feature=share&v=dQw4w9WgXcQ&list=PL123', 'dQw4w9WgXcQ', 0];
        yield 'mobile' => ['https://m.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ', 0];
        yield 'music' => ['https://music.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ', 0];
        yield 'short link' => ['https://youtu.be/dQw4w9WgXcQ', 'dQw4w9WgXcQ', 0];
        yield 'short link with si' => ['https://youtu.be/dQw4w9WgXcQ?si=abcdef', 'dQw4w9WgXcQ', 0];
        yield 'shorts' => ['https://www.youtube.com/shorts/aBcD_eF-123', 'aBcD_eF-123', 0];
        yield 'live' => ['https://www.youtube.com/live/aBcD_eF-123?feature=share', 'aBcD_eF-123', 0];
        yield 'embed' => ['https://www.youtube.com/embed/dQw4w9WgXcQ', 'dQw4w9WgXcQ', 0];
        yield 'nocookie embed' => ['https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?start=42', 'dQw4w9WgXcQ', 42];
        yield 't seconds' => ['https://youtu.be/dQw4w9WgXcQ?t=90', 'dQw4w9WgXcQ', 90];
        yield 't with s suffix' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=90s', 'dQw4w9WgXcQ', 90];
        yield 't h m s' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=1h2m3s', 'dQw4w9WgXcQ', 3723];
        yield 't m s' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=2m5s', 'dQw4w9WgXcQ', 125];
        yield 'garbage t ignored' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=abc', 'dQw4w9WgXcQ', 0];
        yield 'negative t ignored' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=-5', 'dQw4w9WgXcQ', 0];
        yield 'huge t ignored' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=999999', 'dQw4w9WgXcQ', 0];
    }

    #[DataProvider('videoUrls')]
    public function testParsesVideoIdAndStart(string $url, string $id, int $start): void
    {
        $video = YouTubeId::fromUrl($url);

        self::assertSame($id, $video->id);
        self::assertSame($start, $video->startSec);
        self::assertSame('https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg', $video->thumbnailUrl());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rejectedUrls(): iterable
    {
        yield 'playlist only' => ['https://www.youtube.com/playlist?list=PL590L5WQmH8fJ54F369BLDSqIwcs-TCfs', 'PLAYLIST_NOT_SUPPORTED'];
        yield 'watch with list and bad id' => ['https://www.youtube.com/watch?v=short&list=PL123', 'PLAYLIST_NOT_SUPPORTED'];
        yield 'channel' => ['https://www.youtube.com/@somechannel', 'INVALID_URL'];
        yield 'channel videos' => ['https://www.youtube.com/channel/UC38IQsAvIsxxjztdMZQtwHA/videos', 'INVALID_URL'];
        yield 'home' => ['https://www.youtube.com/', 'INVALID_URL'];
        yield 'id too short' => ['https://youtu.be/dQw4w9WgXc', 'INVALID_URL'];
        yield 'id too long' => ['https://youtu.be/dQw4w9WgXcQQ', 'INVALID_URL'];
        yield 'bad characters' => ['https://www.youtube.com/watch?v=dQw4w9Wg%2FcQ', 'INVALID_URL'];
        yield 'array v' => ['https://www.youtube.com/watch?v[]=dQw4w9WgXcQ', 'INVALID_URL'];
        yield 'clip' => ['https://www.youtube.com/clip/UgkxU2HSeGL_NvmDJ-nQJrlLwllwMDBdGZFs', 'INVALID_URL'];
        yield 'shorts without id' => ['https://www.youtube.com/shorts/', 'INVALID_URL'];
        yield 'other host' => ['https://notyoutube.com/watch?v=dQw4w9WgXcQ', 'INVALID_URL'];
    }

    #[DataProvider('rejectedUrls')]
    public function testRejectsNonVideoLinks(string $url, string $code): void
    {
        try {
            YouTubeId::fromUrl($url);
            self::fail('Expected rejection of ' . $url);
        } catch (ApiException $e) {
            self::assertSame($code, $e->errorCode->value);
        }
    }
}
