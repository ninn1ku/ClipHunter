<?php

declare(strict_types=1);

namespace ClipHunter\Tests\Api;

use ClipHunter\Tests\Support\TestApp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnalyzeApiTest extends TestCase
{
    public function testAnalyzeReturnsSanitisedMetadataAndOptions(): void
    {
        $app = new TestApp();

        $response = $app->analyze('https://www.youtube.com/watch?v=hd');
        $body = TestApp::decode($response);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertIsString($body['analysisId']);
        self::assertMatchesRegularExpression('~^[a-f0-9]{32}$~', $body['analysisId']);
        self::assertSame('2027-01-15T08:30:00Z', $body['expiresAt']);
        self::assertSame([
            'platform' => 'YouTube',
            'title' => 'Synthetic HD video',
            'uploader' => 'ClipHunter Tests',
            'durationSec' => 600,
            'thumbnailUrl' => 'https://i.ytimg.com/vi/hd000000001/maxresdefault.jpg',
            'webpageUrl' => 'https://www.youtube.com/watch?v=hd',
        ], $body['video']);

        $options = $body['options'];
        self::assertIsArray($options);
        self::assertSame(['v1440', 'v1080', 'v720', 'v480', 'v360', 'a-m4a', 'a-mp3'], array_column($options, 'id'));
        self::assertSame(['id' => 'v1080', 'kind' => 'video', 'label' => '1080p', 'container' => 'mp4', 'height' => 1080, 'sizeBytes' => 129_700_000, 'sizeIsApprox' => true], $options[1]);
    }

    public function testRealYoutubeFixtureProducesLowResolutionTiers(): void
    {
        $body = TestApp::decode((new TestApp())->analyze('https://youtu.be/x?v=ok'));

        self::assertIsArray($body['options']);
        self::assertSame(['v240', 'v144', 'a-m4a', 'a-mp3'], array_column($body['options'], 'id'));
        self::assertIsArray($body['video']);
        self::assertSame('Me at the zoo', $body['video']['title']);
        self::assertSame(19, $body['video']['durationSec']);
    }

    public function testVerticalRedditVideoUsesTheShorterSideForTiers(): void
    {
        $body = TestApp::decode((new TestApp())->analyze('https://www.reddit.com/r/videos/comments/1/?v=reddit'));

        self::assertIsArray($body['options']);
        self::assertSame(['v360', 'v240', 'v144', 'a-m4a', 'a-mp3'], array_column($body['options'], 'id'));
    }

    public function testMaliciousMetadataIsNeutralised(): void
    {
        $response = (new TestApp())->analyze('https://www.youtube.com/watch?v=xss');
        $body = TestApp::decode($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($body['video']);
        self::assertSame('<img src=x onerror=alert(1)> gpj.exe', $body['video']['title']);
        self::assertSame('../../etc/passwd', $body['video']['uploader'], 'kept as display text only, never a path');
        self::assertNull($body['video']['thumbnailUrl']);
        self::assertNull($body['video']['durationSec']);
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function failures(): iterable
    {
        yield 'private' => ['private', 422, 'VIDEO_PRIVATE'];
        yield 'unavailable' => ['unavailable', 422, 'VIDEO_UNAVAILABLE'];
        yield 'age gate' => ['age', 422, 'LOGIN_REQUIRED'];
        yield 'geo' => ['geo', 422, 'GEO_RESTRICTED'];
        yield 'bot check' => ['bot', 502, 'SOURCE_TEMPORARILY_BLOCKED'];
        yield 'not a video page' => ['unsupported', 422, 'UNSUPPORTED_SOURCE'];
        yield 'unknown failure' => ['weird', 502, 'EXTRACTOR_FAILED'];
        yield 'live' => ['live', 422, 'LIVE_NOT_SUPPORTED'];
        yield 'playlist' => ['playlist', 422, 'PLAYLIST_NOT_SUPPORTED'];
        yield 'too long' => ['long', 422, 'VIDEO_TOO_LONG'];
        yield 'drm' => ['drm', 422, 'VIDEO_UNAVAILABLE'];
        yield 'garbage output' => ['garbage', 502, 'EXTRACTOR_FAILED'];
        yield 'json list' => ['list', 422, 'NO_FORMATS'];
        yield 'huge output' => ['huge', 502, 'EXTRACTOR_FAILED'];
    }

    #[DataProvider('failures')]
    public function testYtDlpFailuresMapToClearErrors(string $scenario, int $status, string $code): void
    {
        $response = (new TestApp())->analyze('https://www.youtube.com/watch?v=' . $scenario);
        $raw = (string) $response->getBody();

        self::assertSame($status, $response->getStatusCode(), $raw);
        self::assertSame($code, TestApp::errorCode($response));
        self::assertStringNotContainsString('ERROR:', $raw, 'yt-dlp stderr must not reach clients');
        self::assertStringNotContainsString('fake-yt-dlp', $raw);
    }

    public function testTimeoutKillsYtDlpAndReturns504(): void
    {
        $app = new TestApp(['ANALYZE_TIMEOUT_SEC' => '5']);
        $started = microtime(true);

        $response = $app->analyze('https://www.youtube.com/watch?v=sleep');

        self::assertSame(504, $response->getStatusCode());
        self::assertSame('ANALYZE_TIMEOUT', TestApp::errorCode($response));
        self::assertLessThan(15, microtime(true) - $started);
    }

    public function testRateLimitPerClient(): void
    {
        $app = new TestApp(['RATE_LIMIT_ANALYZE' => '2/600']);

        self::assertSame(200, $app->analyze('https://www.youtube.com/watch?v=hd')->getStatusCode());
        self::assertSame(400, $app->analyze('not a url')->getStatusCode(), 'invalid input still counts');
        $limited = $app->analyze('https://www.youtube.com/watch?v=hd');

        self::assertSame(429, $limited->getStatusCode());
        self::assertSame('RATE_LIMITED', TestApp::errorCode($limited));
        self::assertSame('600', $limited->getHeaderLine('Retry-After'));

        // Another client is unaffected.
        self::assertSame(200, $app->analyze('https://www.youtube.com/watch?v=hd', ['REMOTE_ADDR' => '198.51.100.20'])->getStatusCode());

        // The window resets.
        $app->clock->advance(600);
        self::assertSame(200, $app->analyze('https://www.youtube.com/watch?v=hd')->getStatusCode());
    }

    public function testRejectsCrossSiteRequests(): void
    {
        $app = new TestApp();

        $foreignOrigin = $app->request('POST', '/api/analyze', ['url' => 'https://youtu.be/x'], ['Origin' => 'https://evil.example']);
        $crossSite = $app->request('POST', '/api/analyze', ['url' => 'https://youtu.be/x'], ['Sec-Fetch-Site' => 'cross-site']);

        self::assertSame(403, $foreignOrigin->getStatusCode());
        self::assertSame('FORBIDDEN_ORIGIN', TestApp::errorCode($foreignOrigin));
        self::assertSame(403, $crossSite->getStatusCode());
    }

    public function testRequiresAJsonObjectBody(): void
    {
        $app = new TestApp();

        $form = $app->request('POST', '/api/analyze', null, ['Content-Type' => 'application/x-www-form-urlencoded']);
        $notObject = $app->request('POST', '/api/analyze', ['https://youtu.be/x']);
        $missing = $app->request('POST', '/api/analyze', ['link' => 'https://youtu.be/x']);
        $wrongType = $app->request('POST', '/api/analyze', ['url' => ['https://youtu.be/x']]);

        self::assertSame(415, $form->getStatusCode());
        self::assertSame('INVALID_JSON', TestApp::errorCode($notObject));
        self::assertSame('INVALID_REQUEST', TestApp::errorCode($missing));
        self::assertSame('INVALID_REQUEST', TestApp::errorCode($wrongType));
    }

    public function testRejectsOversizedBodies(): void
    {
        $response = (new TestApp())->request('POST', '/api/analyze', ['url' => str_repeat('a', 5000)]);

        self::assertSame(413, $response->getStatusCode());
    }

    public function testUnsafeUrlNeverReachesYtDlp(): void
    {
        $app = new TestApp();
        $argvFile = tempnam(sys_get_temp_dir(), 'argv');
        self::assertIsString($argvFile);
        unlink($argvFile);
        $_ENV['FAKE_YTDLP_ARGV_FILE'] = $argvFile; // Symfony Process forwards $_ENV, not putenv()

        try {
            $response = $app->analyze('http://169.254.169.254/latest/meta-data/');
        } finally {
            unset($_ENV['FAKE_YTDLP_ARGV_FILE']);
        }

        self::assertSame(400, $response->getStatusCode());
        self::assertFileDoesNotExist($argvFile);
    }

    public function testYtDlpReceivesSafeArgumentsWithTheUrlAfterTheSeparator(): void
    {
        $app = new TestApp();
        $argvFile = tempnam(sys_get_temp_dir(), 'argv');
        self::assertIsString($argvFile);
        $_ENV['FAKE_YTDLP_ARGV_FILE'] = $argvFile; // Symfony Process forwards $_ENV, not putenv()

        try {
            $app->analyze('youtube.com/watch?v=hd&--exec=touch%20/tmp/pwned');
        } finally {
            unset($_ENV['FAKE_YTDLP_ARGV_FILE']);
        }

        $argv = json_decode((string) file_get_contents($argvFile), true);
        unlink($argvFile);
        self::assertIsArray($argv);

        self::assertContains('--ignore-config', $argv);
        self::assertContains('--no-plugin-dirs', $argv);
        $ies = array_search('--use-extractors', $argv, true);
        self::assertIsInt($ies);
        self::assertSame('youtube,youtube:clip', $argv[$ies + 1]);
        self::assertSame(['--', 'https://youtube.com/watch?v=hd&--exec=touch%20/tmp/pwned'], array_slice($argv, -2));
        self::assertNotContains('--exec', $argv);
    }
}
