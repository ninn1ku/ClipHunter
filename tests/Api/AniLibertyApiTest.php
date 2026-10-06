<?php

declare(strict_types=1);

namespace ClipHunter\Tests\Api;

use ClipHunter\Tests\Support\FakeJsonFetcher;
use ClipHunter\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

final class AniLibertyApiTest extends TestCase
{
    private const EPISODE_1 = 'a2eaa868-41e2-486d-81f0-c2f124f82803';

    public function testReleaseListsItsEpisodesWithoutStreamUrls(): void
    {
        $response = (new TestApp())->request('GET', '/api/watch/aniliberty/releases/10335');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $release = TestApp::decode($response)['release'];
        self::assertIsArray($release);
        self::assertSame(['test-anime', 'https://aniliberty.top/anime/releases/release/test-anime'], [$release['alias'], $release['pageUrl']]);
        self::assertIsArray($release['episodes']);
        self::assertCount(3, $release['episodes']);
        self::assertSame(
            ['id' => self::EPISODE_1, 'label' => '1', 'name' => 'Первая серия', 'durationSec' => 1430, 'previewUrl' => 'https://aniliberty.top/storage/releases/episodes/previews/10335/1/t.webp', 'playable' => true],
            $release['episodes'][0],
        );
        self::assertStringNotContainsString('m3u8', (string) $response->getBody());
    }

    public function testEpisodeCarriesItsStreamsForThePlayer(): void
    {
        $response = (new TestApp())->request('GET', '/api/watch/aniliberty/episodes/' . self::EPISODE_1);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $episode = TestApp::decode($response)['episode'];
        self::assertIsArray($episode);
        self::assertSame([
            ['height' => 720, 'label' => '720p', 'url' => 'https://cache.libria.fun/videos/media/ts/10335/1/720/y.m3u8?countryIso=RU'],
            ['height' => 480, 'label' => '480p', 'url' => 'https://cache.libria.fun/videos/media/ts/10335/1/480/x.m3u8?countryIso=RU'],
        ], $episode['streams']);
        self::assertSame(1430, $episode['durationSec']);
    }

    public function testSearchReturnsReleasesAndValidatesTheQuery(): void
    {
        $app = new TestApp();
        $release = FakeJsonFetcher::fixture('release.json');
        $app->aniliberty->responses['/api/v1/app/search/releases?query=%D1%82%D0%B5%D1%81%D1%82'] = [$release];

        $ok = $app->request('GET', '/api/watch/aniliberty/search?q=' . rawurlencode('  тест '));
        $short = $app->request('GET', '/api/watch/aniliberty/search?q=a');
        $missing = $app->request('GET', '/api/watch/aniliberty/search');

        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());
        $results = TestApp::decode($ok)['results'];
        self::assertIsArray($results);
        self::assertIsArray($results[0]);
        self::assertSame(10335, $results[0]['id']);
        self::assertArrayNotHasKey('episodes', $results[0]);
        self::assertSame(400, $short->getStatusCode());
        self::assertSame(400, $missing->getStatusCode());
    }

    public function testBlockedReleasesAreNotShown(): void
    {
        $app = new TestApp();
        $blocked = FakeJsonFetcher::fixture('release.json');
        $blocked['is_blocked_by_geo'] = true;
        $app->aniliberty->responses['/api/v1/anime/releases/10335'] = $blocked;

        $response = $app->request('GET', '/api/watch/aniliberty/releases/10335');

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('GEO_RESTRICTED', self::errorCode($response));
    }

    public function testUnknownReleaseIsNotFoundAndMalformedIdsAreNotRouted(): void
    {
        $app = new TestApp();

        self::assertSame('VIDEO_UNAVAILABLE', self::errorCode($app->request('GET', '/api/watch/aniliberty/releases/42')));
        self::assertSame(404, $app->request('GET', '/api/watch/aniliberty/releases/..%2F..%2Fetc')->getStatusCode());
        self::assertSame(404, $app->request('GET', '/api/watch/aniliberty/episodes/not-a-uuid')->getStatusCode());
        self::assertSame(['https://aniliberty.top/api/v1/anime/releases/42'], $app->aniliberty->requested);
    }

    public function testAllEndpointsShareOneRateLimit(): void
    {
        $app = new TestApp(['RATE_LIMIT_WATCH_ANIME' => '2/600']);

        $app->request('GET', '/api/watch/aniliberty/releases/10335');
        $app->request('GET', '/api/watch/aniliberty/episodes/' . self::EPISODE_1);
        $limited = $app->request('GET', '/api/watch/aniliberty/releases/10335');

        self::assertSame(429, $limited->getStatusCode());
        self::assertCount(2, $app->aniliberty->requested);
    }

    public function testReleaseLinkStartsARoomWithItsFirstEpisode(): void
    {
        $app = new TestApp();

        $response = $app->watchSource('https://aniliberty.top/anime/releases/release/test-anime/episodes');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = TestApp::decode($response);
        $source = $body['source'];
        self::assertIsArray($source);
        self::assertSame(['aniliberty', '10335:' . self::EPISODE_1, 'AniLiberty', 'Тестовый тайтл <b> — серия 1', 1430], [$source['kind'], $source['ref'], $source['platform'], $source['title'], $source['durationSec']]);
        $ticket = $body['ticket'];
        self::assertIsString($ticket);
        $payload = json_decode((string) base64_decode(strtr(explode('.', $ticket)[0], '-_', '+/'), true), true);
        self::assertIsArray($payload);
        self::assertSame(['aniliberty', '10335:' . self::EPISODE_1], [$payload['kind'], $payload['ref']]);
        self::assertStringNotContainsString('m3u8', $ticket === '' ? '' : (string) base64_decode(strtr(explode('.', $ticket)[0], '-_', '+/'), true));
    }

    public function testEpisodeLinkPicksThatEpisodeAndFileModeIsRefused(): void
    {
        $app = new TestApp();

        $episode = $app->watchSource('https://anilibria.top/anime/video/episode/' . self::EPISODE_1);
        $file = $app->watchSource('https://aniliberty.top/anime/video/episode/' . self::EPISODE_1, 'file');
        $analyze = $app->analyze('https://aniliberty.top/anime/video/episode/' . self::EPISODE_1);
        $catalog = $app->watchSource('https://aniliberty.top/anime/catalog');

        self::assertSame(200, $episode->getStatusCode(), (string) $episode->getBody());
        self::assertSame('UNSUPPORTED_SOURCE', self::errorCode($file), 'AniLiberty is not a yt-dlp source');
        self::assertSame('UNSUPPORTED_SOURCE', self::errorCode($analyze));
        self::assertSame('INVALID_URL', self::errorCode($catalog));
    }

    private static function errorCode(\Psr\Http\Message\ResponseInterface $response): ?string
    {
        $error = TestApp::decode($response)['error'] ?? null;

        return is_array($error) && is_string($error['code'] ?? null) ? $error['code'] : null;
    }
}
