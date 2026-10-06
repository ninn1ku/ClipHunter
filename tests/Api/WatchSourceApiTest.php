<?php

declare(strict_types=1);

namespace ClipHunter\Tests\Api;

use ClipHunter\Job\JobPurpose;
use ClipHunter\Job\JobRepository;
use ClipHunter\Tests\Support\TestApp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * POST /api/watch/sources.
 */
final class WatchSourceApiTest extends TestCase
{
    public function testYouTubeLinksAreResolvedLocallyWithoutYtDlp(): void
    {
        $app = new TestApp();
        $argvFile = tempnam(sys_get_temp_dir(), 'argv');
        self::assertIsString($argvFile);
        unlink($argvFile);
        $_ENV['FAKE_YTDLP_ARGV_FILE'] = $argvFile; // Symfony Process forwards $_ENV, not putenv()

        try {
            $response = $app->watchSource('https://youtu.be/dQw4w9WgXcQ?t=1m30s');
        } finally {
            unset($_ENV['FAKE_YTDLP_ARGV_FILE']);
        }

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertFileDoesNotExist($argvFile, 'yt-dlp must not run for embeddable YouTube links');
        $body = TestApp::decode($response);
        self::assertSame([
            'videoId' => 'dQw4w9WgXcQ',
            'ref' => 'dQw4w9WgXcQ',
            'kind' => 'youtube',
            'platform' => 'YouTube',
            'title' => null,
            'durationSec' => null,
            'thumbnailUrl' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
            'startSec' => 90,
            'status' => 'ready',
        ], $body['source']);

        $payload = $this->verifiedPayload($app, $body['ticket']);
        self::assertSame('youtube', $payload['kind']);
        self::assertSame('dQw4w9WgXcQ', $payload['ref']);
        self::assertSame(90, $payload['startSec']);
        self::assertSame($app->clock->now + 600, $payload['exp']);
    }

    public function testVkVideoLinksPlayInTheOfficialEmbedWithoutYtDlp(): void
    {
        $app = new TestApp();
        $argvFile = tempnam(sys_get_temp_dir(), 'argv');
        self::assertIsString($argvFile);
        unlink($argvFile);
        $_ENV['FAKE_YTDLP_ARGV_FILE'] = $argvFile;

        try {
            $response = $app->watchSource('https://vkvideo.ru/video-22822305_456241864?t=1m5s');
        } finally {
            unset($_ENV['FAKE_YTDLP_ARGV_FILE']);
        }

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertFileDoesNotExist($argvFile, 'yt-dlp must not run for embeddable VK links');
        $body = TestApp::decode($response);
        $source = $body['source'];
        self::assertIsArray($source);
        self::assertSame(['vk', '-22822305_456241864', 'ready'], [$source['kind'], $source['ref'], $source['status']]);
        $payload = $this->verifiedPayload($app, $body['ticket']);
        self::assertSame(['vk', '-22822305_456241864', 'ВКонтакте', 65], [$payload['kind'], $payload['ref'], $payload['platform'], $payload['startSec']]);
    }

    public function testVkLinksWithoutAVideoIdAndFileModeGoThroughTheWorker(): void
    {
        $app = new TestApp();

        $wall = $app->watchSource('https://vk.com/wall-1_2?v=ok');
        $forced = $app->watchSource('https://vkvideo.ru/video-1_2?v=hd', 'file');

        self::assertSame(202, $wall->getStatusCode(), (string) $wall->getBody());
        self::assertSame(202, $forced->getStatusCode(), (string) $forced->getBody());
        $source = TestApp::decode($forced)['source'];
        self::assertIsArray($source);
        self::assertSame('file', $source['kind']);
    }

    public function testOtherPlatformsArePreparedByTheWorkerAsWatchJobs(): void
    {
        $app = new TestApp();

        $response = $app->watchSource('https://vk.com/wall-1_2?v=ok');

        self::assertSame(202, $response->getStatusCode(), (string) $response->getBody());
        $body = TestApp::decode($response);
        $source = $body['source'];
        self::assertIsArray($source);
        self::assertSame('file', $source['kind']);
        self::assertSame('ВКонтакте', $source['platform']);
        self::assertSame('Me at the zoo', $source['title']);
        self::assertSame(19, $source['durationSec']);
        self::assertSame('queued', $source['status']);
        self::assertIsString($source['mediaId']);

        $job = $app->container->get(JobRepository::class)->find($source['mediaId']);
        self::assertNotNull($job);
        self::assertSame(JobPurpose::Watch, $job->purpose);
        self::assertSame('v240', $job->optionId, 'the best variant of the fixture');

        $payload = $this->verifiedPayload($app, $body['ticket']);
        self::assertSame('file', $payload['kind']);
        self::assertSame($source['mediaId'], $payload['ref']);
        self::assertSame('Me at the zoo', $payload['title']);
    }

    public function testPicksTheLargestVariantWithinWatchMaxHeight(): void
    {
        $app = new TestApp();

        $mediaId = $app->queueWatchMedia('hd');

        self::assertSame('v720', $app->container->get(JobRepository::class)->find($mediaId)?->optionId);
    }

    public function testStartsWithTheSmallestVariantWhenAllExceedTheDefault(): void
    {
        $app = new TestApp(['WATCH_DEFAULT_HEIGHT' => '240']);

        $mediaId = $app->queueWatchMedia('hd');

        self::assertSame('v360', $app->container->get(JobRepository::class)->find($mediaId)?->optionId);
    }

    public function testTheSameVideoIsPreparedOnlyOnce(): void
    {
        $app = new TestApp();

        $first = $app->queueWatchMedia();
        $second = $app->queueWatchMedia();

        self::assertSame($first, $second, 'a second room reuses the queued job');
        self::assertCount(1, $app->container->get(JobRepository::class)->queuedIds());
    }

    public function testRoomFilesQueueInsteadOfFailingWhenTheClientHasAnotherJob(): void
    {
        $app = new TestApp(['MAX_ACTIVE_JOBS_PER_IP' => '1']);
        $app->queueDownload(); // an active download from the same client

        $first = $app->watchSource('https://vk.com/wall-1_2?v=ok');
        $second = $app->watchSource('https://vk.com/wall-1_2?v=hd');

        self::assertSame(202, $first->getStatusCode(), (string) $first->getBody());
        self::assertSame(202, $second->getStatusCode(), (string) $second->getBody());
        $source = TestApp::decode($second)['source'];
        self::assertIsArray($source);
        self::assertSame('queued', $source['status']);
        self::assertCount(3, $app->container->get(JobRepository::class)->queuedIds());
    }

    public function testFailsWhenNoVariantFitsWatchMaxHeight(): void
    {
        $response = (new TestApp(['WATCH_MAX_HEIGHT' => '144']))->watchSource('https://vk.com/wall-1_2?v=hd');

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('NO_FORMATS', TestApp::errorCode($response));
    }

    public function testFileModeForcesServerPreparationForYouTube(): void
    {
        $app = new TestApp();

        $response = $app->watchSource('https://www.youtube.com/watch?v=ok', 'file');

        self::assertSame(202, $response->getStatusCode(), (string) $response->getBody());
        $source = TestApp::decode($response)['source'];
        self::assertIsArray($source);
        self::assertSame('file', $source['kind']);
        self::assertSame('YouTube', $source['platform']);
    }

    /**
     * @return iterable<string, array{string, ?string, int, string}>
     */
    public static function rejected(): iterable
    {
        yield 'metadata endpoint' => ['http://169.254.169.254/latest/meta-data/', null, 400, 'INVALID_URL'];
        yield 'localhost' => ['http://localhost/video.mp4', null, 400, 'INVALID_URL'];
        yield 'internal hostname' => ['https://metadata.google.internal/', null, 422, 'UNSUPPORTED_SOURCE'];
        yield 'file scheme' => ['file:///etc/passwd', null, 400, 'INVALID_URL'];
        yield 'direct file' => ['https://example.com/video.mp4', null, 422, 'UNSUPPORTED_SOURCE'];
        yield 'garbage' => ['not a url at all', null, 400, 'INVALID_URL'];
        yield 'youtube channel' => ['https://www.youtube.com/@channel', null, 400, 'INVALID_URL'];
        yield 'youtube playlist' => ['https://www.youtube.com/playlist?list=PL123', null, 422, 'PLAYLIST_NOT_SUPPORTED'];
        yield 'unknown mode' => ['https://youtu.be/dQw4w9WgXcQ', 'stream', 400, 'INVALID_REQUEST'];
        yield 'platform error' => ['https://vk.com/wall-1_2?v=private', null, 422, 'VIDEO_PRIVATE'];
    }

    #[DataProvider('rejected')]
    public function testRejectsUnsafeOrUnsupportedInput(string $url, ?string $mode, int $status, string $code): void
    {
        $response = (new TestApp())->watchSource($url, $mode);

        self::assertSame($status, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame($code, TestApp::errorCode($response));
    }

    public function testCrossSiteRequestsAreRejected(): void
    {
        $response = (new TestApp())->request('POST', '/api/watch/sources', ['url' => 'https://youtu.be/dQw4w9WgXcQ'], ['Origin' => 'https://evil.example']);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('FORBIDDEN_ORIGIN', TestApp::errorCode($response));
    }

    public function testIsRateLimitedPerClient(): void
    {
        $app = new TestApp(['RATE_LIMIT_WATCH_SOURCES' => '2/3600']);

        $app->watchSource('https://youtu.be/dQw4w9WgXcQ');
        $app->watchSource('https://youtu.be/dQw4w9WgXcQ');
        $limited = $app->watchSource('https://youtu.be/dQw4w9WgXcQ');
        $otherClient = $app->watchSource('https://youtu.be/dQw4w9WgXcQ', server: ['REMOTE_ADDR' => '198.51.100.7']);

        self::assertSame(429, $limited->getStatusCode());
        self::assertSame('RATE_LIMITED', TestApp::errorCode($limited));
        self::assertNotSame('', $limited->getHeaderLine('Retry-After'));
        self::assertSame(200, $otherClient->getStatusCode());
    }

    /**
     * Verifies the ticket like the rooms service does and returns its payload.
     *
     * @return array<string, mixed>
     */
    private function verifiedPayload(TestApp $app, mixed $ticket): array
    {
        self::assertIsString($ticket);
        [$body, $signature] = explode('.', $ticket);
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, (string) hex2bin($app->config->roomsSecret), true)), '+/', '-_'), '=');
        self::assertSame($expected, $signature);

        $payload = json_decode((string) base64_decode(strtr($body, '-_', '+/'), true), true);
        self::assertIsArray($payload);
        self::assertSame(1, $payload['v']);

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}
