<?php

declare(strict_types=1);

namespace ClipHunter\Tests\Unit\AniLiberty;

use ClipHunter\AniLiberty\AniLibertyClient;
use ClipHunter\Exception\ApiException;
use ClipHunter\Media\PlatformRegistry;
use ClipHunter\Security\UrlValidator;
use ClipHunter\Tests\Support\FakeJsonFetcher;
use ClipHunter\Tests\Support\FakeResolver;
use PHPUnit\Framework\TestCase;

final class AniLibertyClientTest extends TestCase
{
    private const API = 'https://aniliberty.top/api/v1';
    private const EPISODE_1 = 'a2eaa868-41e2-486d-81f0-c2f124f82803';

    public function testParsesAReleaseAndKeepsOnlySafeData(): void
    {
        $release = $this->client()->fetchRelease(10335);

        self::assertSame(10335, $release->id);
        self::assertSame('test-anime', $release->alias);
        self::assertSame('Тестовый тайтл <b>', $release->title, 'control characters are removed, markup stays text');
        self::assertSame('https://aniliberty.top/storage/releases/posters/10335/thumb.webp', $release->posterUrl);
        self::assertSame('https://aniliberty.top/anime/releases/release/test-anime', $release->pageUrl);
        self::assertFalse($release->blocked);

        self::assertSame(['1', '2', '2.5'], array_map(static fn ($e): string => $e->label(), $release->episodes), 'sorted, malformed ids dropped');
        [$first, $second, $half] = $release->episodes;
        self::assertSame([720 => 'https://cache.libria.fun/videos/media/ts/10335/1/720/y.m3u8?countryIso=RU', 480 => 'https://cache.libria.fun/videos/media/ts/10335/1/480/x.m3u8?countryIso=RU'], $first->streams);
        self::assertSame(['start' => 90, 'stop' => 180], $first->opening);
        self::assertNull($first->ending);
        self::assertSame([], $second->streams, 'foreign host, plain http and a custom port are all dropped');
        self::assertNull($second->previewUrl, 'path traversal in an image path is dropped');
        self::assertSame([480 => 'https://cache.libria.fun/videos/media/ts/10335/2.5/480/z.m3u8'], $half->streams, 'only .m3u8 playlists');
        self::assertSame($first, $release->firstPlayableEpisode());
    }

    public function testReleaseBlockedByTheCopyrightHolderIsMarked(): void
    {
        $data = FakeJsonFetcher::fixture('release.json');
        $data['is_blocked_by_copyrights'] = true;

        self::assertTrue($this->client(['/api/v1/anime/releases/10335' => $data])->fetchRelease(10335)->blocked);
    }

    public function testFetchesAnEpisodeWithItsRelease(): void
    {
        ['episode' => $episode, 'release' => $release] = $this->client()->fetchEpisode(self::EPISODE_1);

        self::assertSame(self::EPISODE_1, $episode->id);
        self::assertSame(10335, $release->id);
        self::assertSame(1430, $episode->durationSec);
    }

    public function testAnEpisodeOfAnotherReleaseIsRejected(): void
    {
        $responses = FakeJsonFetcher::fixtureResponses();
        $responses['/api/v1/anime/releases/episodes/' . self::EPISODE_1]['release_id'] = 999;

        try {
            $this->client($responses)->fetchEpisode(self::EPISODE_1);
            self::fail('accepted an episode of another release');
        } catch (ApiException $e) {
            self::assertSame('EXTRACTOR_FAILED', $e->errorCode->value);
        }
    }

    public function testMalformedIdentifiersNeverReachTheApi(): void
    {
        $fetcher = new FakeJsonFetcher(FakeJsonFetcher::fixtureResponses());
        $client = $this->client(fetcher: $fetcher);

        foreach (['../../etc/passwd', 'A2EAA868-41E2-486D-81F0-C2F124F82803', ''] as $bad) {
            try {
                $client->fetchEpisode($bad);
                self::fail('accepted ' . $bad);
            } catch (ApiException $e) {
                self::assertSame('INVALID_URL', $e->errorCode->value);
            }
        }
        foreach (['Test Anime', 'x/../y', '-alias'] as $bad) {
            try {
                $client->fetchRelease($bad);
                self::fail('accepted ' . $bad);
            } catch (ApiException $e) {
                self::assertSame('INVALID_URL', $e->errorCode->value);
            }
        }
        self::assertSame([], $fetcher->requested);
    }

    public function testApiHostMustBeAllowlistedAndPublic(): void
    {
        $fetcher = new FakeJsonFetcher(FakeJsonFetcher::fixtureResponses());

        foreach (
            [
                ['https://evil.example.com/api/v1', null],
                ['http://aniliberty.top/api/v1', null],
                [self::API, ['10.0.0.5']],
            ] as [$api, $ips]
        ) {
            $client = $this->client(fetcher: $fetcher, api: $api, resolver: new FakeResolver($ips === null ? [] : ['aniliberty.top' => $ips]));
            try {
                $client->fetchRelease(10335);
                self::fail('accepted ' . $api);
            } catch (ApiException $e) {
                self::assertSame('UNSUPPORTED_SOURCE', $e->errorCode->value, $api);
            }
        }
        self::assertSame([], $fetcher->requested);
    }

    public function testSearchSkipsMalformedEntriesAndIsBounded(): void
    {
        $release = FakeJsonFetcher::fixture('release.json');
        unset($release['episodes']);
        $list = array_fill(0, 30, $release);
        $list[0] = ['id' => 'x'];
        $client = $this->client(['/api/v1/app/search/releases?query=%D1%82%D0%B5%D1%81%D1%82' => $list]);

        $results = $client->search('тест');

        self::assertCount(AniLibertyClient::MAX_SEARCH_RESULTS, $results);
        self::assertSame([], $results[0]->episodes);
    }

    /**
     * @param ?array<string, array<array-key, mixed>> $responses
     */
    private function client(?array $responses = null, ?FakeJsonFetcher $fetcher = null, string $api = self::API, ?FakeResolver $resolver = null): AniLibertyClient
    {
        return new AniLibertyClient(
            $fetcher ?? new FakeJsonFetcher($responses ?? FakeJsonFetcher::fixtureResponses()),
            new UrlValidator(PlatformRegistry::fromFile(dirname(__DIR__, 3) . '/config/platforms.php'), $resolver ?? new FakeResolver()),
            $api,
            ['aniliberty.top', 'anilibria.top', 'api.anilibria.app'],
            ['libria.fun'],
            'https://aniliberty.top',
        );
    }
}
