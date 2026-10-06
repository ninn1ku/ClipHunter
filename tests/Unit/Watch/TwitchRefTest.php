<?php

declare(strict_types=1);

namespace ClipHunter\Tests\Unit\Watch;

use ClipHunter\Watch\TwitchRef;
use ClipHunter\Watch\WatchSourceKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TwitchRefTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, int, bool}>
     */
    public static function playableUrls(): iterable
    {
        yield 'recording' => ['https://www.twitch.tv/videos/2345678901', 'video:2345678901', 0, false];
        yield 'recording with time' => ['https://www.twitch.tv/videos/2345678901?t=1h2m3s', 'video:2345678901', 3723, false];
        yield 'recording mobile' => ['https://m.twitch.tv/videos/2345678901/', 'video:2345678901', 0, false];
        yield 'recording with filter params' => ['https://twitch.tv/videos/2345678901?filter=archives&sort=time', 'video:2345678901', 0, false];
        yield 'channel' => ['https://www.twitch.tv/shroud', 'channel:shroud', 0, true];
        yield 'channel upper case' => ['https://www.twitch.tv/Shroud/', 'channel:shroud', 0, true];
        yield 'channel with underscore' => ['https://twitch.tv/a_b_c', 'channel:a_b_c', 0, true];
    }

    #[DataProvider('playableUrls')]
    public function testParsesRecordingsAndChannels(string $url, string $ref, int $start, bool $live): void
    {
        $twitch = TwitchRef::fromUrl($url);

        self::assertNotNull($twitch);
        self::assertSame($ref, $twitch->ref);
        self::assertSame($start, $twitch->startSec);
        self::assertSame($live, $twitch->isLive());
        self::assertMatchesRegularExpression(WatchSourceKind::Twitch->refPattern(), $twitch->ref);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherUrls(): iterable
    {
        yield 'clip on clips host' => ['https://clips.twitch.tv/AwkwardHelplessSalamanderSwiftRage'];
        yield 'clip on channel' => ['https://www.twitch.tv/shroud/clip/AwkwardHelplessSalamanderSwiftRage'];
        yield 'channel videos page' => ['https://www.twitch.tv/shroud/videos'];
        yield 'directory' => ['https://www.twitch.tv/directory'];
        yield 'home' => ['https://www.twitch.tv/'];
        yield 'login too short' => ['https://www.twitch.tv/ab'];
        yield 'login with dash' => ['https://www.twitch.tv/a-b-c'];
        yield 'video id zero' => ['https://www.twitch.tv/videos/0'];
        yield 'video id not numeric' => ['https://www.twitch.tv/videos/v123'];
        yield 'player host' => ['https://player.twitch.tv/?video=v123'];
    }

    #[DataProvider('otherUrls')]
    public function testReturnsNullForClipsAndOtherPages(string $url): void
    {
        self::assertNull(TwitchRef::fromUrl($url));
    }
}
