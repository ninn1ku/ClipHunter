<?php

declare(strict_types=1);

namespace ClipHunter\Tests\Unit\AniLiberty;

use ClipHunter\AniLiberty\AniLibertyLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AniLibertyLinkTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string, ?string}>
     */
    public static function links(): iterable
    {
        yield 'release' => ['https://aniliberty.top/anime/releases/release/gensou-suikoden', 'gensou-suikoden', null];
        yield 'release episodes tab' => ['https://anilibria.top/anime/releases/release/one-piece/episodes', 'one-piece', null];
        yield 'episode' => ['https://aniliberty.top/anime/video/episode/a2eaa868-41e2-486d-81f0-c2f124f82803', null, 'a2eaa868-41e2-486d-81f0-c2f124f82803'];
        yield 'catalog' => ['https://aniliberty.top/anime/catalog', null, null];
        yield 'release other tab' => ['https://aniliberty.top/anime/releases/release/one-piece/members', null, null];
        yield 'bad alias' => ['https://aniliberty.top/anime/releases/release/One_Piece', null, null];
        yield 'bad episode id' => ['https://aniliberty.top/anime/video/episode/123', null, null];
        yield 'queue' => ['https://aniliberty.top/anime/video/queue/a2eaa868-41e2-486d-81f0-c2f124f82803', null, null];
    }

    #[DataProvider('links')]
    public function testParsesReleaseAndEpisodeLinks(string $url, ?string $alias, ?string $episodeId): void
    {
        $link = AniLibertyLink::fromUrl($url);

        if ($alias === null && $episodeId === null) {
            self::assertNull($link);

            return;
        }
        self::assertNotNull($link);
        self::assertSame([$alias, $episodeId], [$link->releaseAlias, $link->episodeId]);
    }
}
