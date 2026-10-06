<?php

declare(strict_types=1);

namespace ClipHunter\Tests\Integration;

use ClipHunter\Storage\Cleaner;
use ClipHunter\Storage\StoragePaths;
use ClipHunter\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * Watch-room media prepared by the worker: status polling, inline delivery with Range,
 * retention, and isolation from the /api/downloads endpoints.
 */
final class WatchMediaFlowTest extends TestCase
{
    private const SAMPLE = __DIR__ . '/../fixtures/media/sample.mp4';

    public function testPreparedFileBecomesReadyAndIsServedInline(): void
    {
        $app = new TestApp();
        $mediaId = $app->queueWatchMedia();

        self::assertSame(['mediaId' => $mediaId, 'status' => 'queued', 'queuePosition' => 0, 'percent' => null, 'error' => null, 'optionId' => 'v240', 'label' => '240p'], $this->mediaStatus($app, $mediaId));

        $app->runQueuedJobs();

        self::assertSame(['mediaId' => $mediaId, 'status' => 'ready', 'queuePosition' => null, 'percent' => 100.0, 'error' => null, 'optionId' => 'v240', 'label' => '240p'], $this->mediaStatus($app, $mediaId));
        $file = $app->request('GET', '/api/watch/media/' . $mediaId . '/file');
        self::assertSame(200, $file->getStatusCode());
        self::assertSame('video/mp4', $file->getHeaderLine('Content-Type'));
        self::assertSame('inline', $file->getHeaderLine('Content-Disposition'));
        self::assertSame('nosniff', $file->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('private, max-age=3600', $file->getHeaderLine('Cache-Control'));
        self::assertSame('bytes', $file->getHeaderLine('Accept-Ranges'));
        self::assertSame((string) filesize(self::SAMPLE), $file->getHeaderLine('Content-Length'));
        self::assertSame(file_get_contents(self::SAMPLE), (string) $file->getBody());
    }

    public function testDevelopmentDeliveryHonoursASingleRange(): void
    {
        $app = new TestApp();
        $mediaId = $app->queueWatchMedia();
        $app->runQueuedJobs();
        $size = (int) filesize(self::SAMPLE);
        $url = '/api/watch/media/' . $mediaId . '/file';

        $head = $app->request('GET', $url, headers: ['Range' => 'bytes=0-99']);
        $tail = $app->request('GET', $url, headers: ['Range' => 'bytes=-10']);
        $beyond = $app->request('GET', $url, headers: ['Range' => 'bytes=' . $size . '-']);

        self::assertSame(206, $head->getStatusCode());
        self::assertSame('bytes 0-99/' . $size, $head->getHeaderLine('Content-Range'));
        self::assertSame('100', $head->getHeaderLine('Content-Length'));
        self::assertSame(substr((string) file_get_contents(self::SAMPLE), 0, 100), (string) $head->getBody());

        self::assertSame(206, $tail->getStatusCode());
        self::assertSame(substr((string) file_get_contents(self::SAMPLE), -10), (string) $tail->getBody());

        self::assertSame(416, $beyond->getStatusCode());
        self::assertSame('bytes */' . $size, $beyond->getHeaderLine('Content-Range'));
    }

    public function testProductionDeliveryHandsTheFileToNginx(): void
    {
        $app = new TestApp(['FILE_DELIVERY' => 'xaccel']);
        $mediaId = $app->queueWatchMedia();
        $app->runQueuedJobs();

        $file = $app->request('GET', '/api/watch/media/' . $mediaId . '/file', headers: ['Range' => 'bytes=0-1']);

        self::assertSame(200, $file->getStatusCode(), 'Nginx answers the Range itself');
        self::assertSame('/_files/' . $mediaId . '/media.mp4', $file->getHeaderLine('X-Accel-Redirect'));
        self::assertSame('inline', $file->getHeaderLine('Content-Disposition'));
        self::assertSame('', (string) $file->getBody());
    }

    public function testFileIsNotServedBeforeItIsReady(): void
    {
        $app = new TestApp();
        $mediaId = $app->queueWatchMedia();

        $file = $app->request('GET', '/api/watch/media/' . $mediaId . '/file');

        self::assertSame(409, $file->getStatusCode());
        self::assertSame('FILE_NOT_READY', TestApp::errorCode($file));
    }

    public function testFailedPreparationIsReportedWithACode(): void
    {
        $app = new TestApp();
        $mediaId = $app->queueWatchMedia();
        $paths = $app->container->get(StoragePaths::class);
        $raw = (string) file_get_contents($paths->jobFile($mediaId));
        file_put_contents($paths->jobFile($mediaId), str_replace('v=ok', 'v=dlfail', $raw));

        $app->runQueuedJobs();
        $status = $this->mediaStatus($app, $mediaId);

        self::assertSame('failed', $status['status']);
        self::assertSame('DOWNLOAD_FAILED', is_array($status['error']) ? $status['error']['code'] : null);
    }

    public function testWatchFilesAreUnreachableThroughTheDownloadEndpoints(): void
    {
        $app = new TestApp();
        $mediaId = $app->queueWatchMedia();
        $app->runQueuedJobs();

        $status = $app->request('GET', '/api/downloads/' . $mediaId);
        $file = $app->request('GET', '/api/downloads/' . $mediaId . '/file');
        $delete = $app->request('DELETE', '/api/downloads/' . $mediaId, headers: ['Origin' => TestApp::APP_URL]);

        foreach ([$status, $file, $delete] as $response) {
            self::assertSame(404, $response->getStatusCode());
            self::assertSame('JOB_NOT_FOUND', TestApp::errorCode($response));
        }
        self::assertFileExists($app->container->get(StoragePaths::class)->downloadDir($mediaId) . '/media.mp4', 'a room member cannot delete the file');
        self::assertSame('ready', $this->mediaStatus($app, $mediaId)['status']);
    }

    public function testDownloadsAreUnreachableThroughTheWatchEndpoints(): void
    {
        $app = new TestApp();
        $jobId = $app->queueDownload();
        $app->runQueuedJobs();

        foreach (['', '/file'] as $suffix) {
            $response = $app->request('GET', '/api/watch/media/' . $jobId . $suffix);
            self::assertSame(404, $response->getStatusCode());
            self::assertSame('MEDIA_NOT_FOUND', TestApp::errorCode($response));
        }
        self::assertSame('NOT_FOUND', TestApp::errorCode($app->request('GET', '/api/watch/media/..%2F..%2Fetc%2Fpasswd')));
        self::assertSame('MEDIA_NOT_FOUND', TestApp::errorCode($app->request('GET', '/api/watch/media/' . str_repeat('a', 32))));
    }

    public function testWatchedFilesAreKeptUntilTheWatchRetentionPeriod(): void
    {
        $app = new TestApp();
        $app->clock->now = time(); // the cleaner compares against real file mtimes
        $mediaId = $app->queueWatchMedia();
        $app->runQueuedJobs();
        $cleaner = $app->container->get(Cleaner::class);
        $paths = $app->container->get(StoragePaths::class);

        // Members keep polling (keep-alive) every 5 minutes: the idle timeout never fires.
        for ($minute = 5; $minute <= 355; $minute += 5) {
            $app->clock->advance(5 * 60);
            self::assertSame('ready', $this->mediaStatus($app, $mediaId)['status'], "minute $minute");
            $cleaner->run();
        }
        self::assertFileExists($paths->downloadDir($mediaId) . '/media.mp4', 'past FILE_RETENTION_MIN for downloads');

        $app->clock->advance(6 * 60); // 361 minutes in total, past WATCH_FILE_RETENTION_MIN=360
        self::assertSame('expired', $this->mediaStatus($app, $mediaId)['status'], 'expired even before the cleaner runs');
        $stats = $cleaner->run();

        self::assertSame(1, $stats['expired_files']);
        self::assertDirectoryDoesNotExist($paths->downloadDir($mediaId));
        self::assertSame(410, $app->request('GET', '/api/watch/media/' . $mediaId . '/file')->getStatusCode());
    }

    public function testFilesNobodyUsesAreRemovedAfterTheIdleTimeout(): void
    {
        $app = new TestApp();
        $app->clock->now = time();
        $mediaId = $app->queueWatchMedia();
        $app->runQueuedJobs();
        $cleaner = $app->container->get(Cleaner::class);
        $paths = $app->container->get(StoragePaths::class);

        $app->clock->advance(9 * 60);
        $cleaner->run();
        self::assertFileExists($paths->downloadDir($mediaId) . '/media.mp4');

        $app->clock->advance(2 * 60); // 11 minutes without a status poll or a file request (WATCH_IDLE_TTL_MIN=10)
        $stats = $cleaner->run();

        self::assertSame(1, $stats['expired_files']);
        self::assertDirectoryDoesNotExist($paths->downloadDir($mediaId));
        self::assertSame('expired', $this->mediaStatus($app, $mediaId)['status']);
    }

    public function testStreamingTheFileCountsAsUse(): void
    {
        $app = new TestApp();
        $app->clock->now = time();
        $mediaId = $app->queueWatchMedia();
        $app->runQueuedJobs();

        $app->clock->advance(8 * 60);
        $app->request('GET', '/api/watch/media/' . $mediaId . '/file', headers: ['Range' => 'bytes=0-9']);
        $app->clock->advance(8 * 60);
        $app->container->get(Cleaner::class)->run();

        self::assertFileExists($app->container->get(StoragePaths::class)->downloadDir($mediaId) . '/media.mp4');
    }

    public function testFilesOfOccupiedRoomsAreKeptWithoutAnyKeepAlive(): void
    {
        $app = new TestApp();
        $app->clock->now = time();
        $mediaId = $app->queueWatchMedia(); // 240p, with 144p as the other variant
        $variant = TestApp::decode($app->request('POST', '/api/watch/media/' . $mediaId . '/variants', ['optionId' => 'v144'], ['Origin' => TestApp::APP_URL]))['mediaId'];
        self::assertIsString($variant);
        $app->runQueuedJobs();
        $cleaner = $app->container->get(Cleaner::class);
        $paths = $app->container->get(StoragePaths::class);

        // Six hours of a room playing the 240p file, with nobody polling: the rooms service list keeps both variants.
        for ($minute = 5; $minute <= 360; $minute += 5) {
            $app->clock->advance(5 * 60);
            $this->publishInUse($app, [$mediaId]);
            $cleaner->run();
        }
        self::assertFileExists($paths->downloadDir($mediaId) . '/media.mp4');
        self::assertFileExists($paths->downloadDir($variant) . '/media.mp4', 'every variant of a watched video stays');
        self::assertSame('ready', $this->mediaStatus($app, $mediaId)['status'], 'past WATCH_FILE_RETENTION_MIN as well');

        // Everyone left: the id drops out of the list, and the idle timeout applies again.
        $this->publishInUse($app, []);
        $app->clock->advance(11 * 60);
        $this->publishInUse($app, []);
        $cleaner->run();

        self::assertDirectoryDoesNotExist($paths->downloadDir($mediaId));
        self::assertDirectoryDoesNotExist($paths->downloadDir($variant));
    }

    public function testAStaleListFromTheRoomsServiceIsIgnored(): void
    {
        $app = new TestApp();
        $app->clock->now = time();
        $mediaId = $app->queueWatchMedia();
        $app->runQueuedJobs();
        $this->publishInUse($app, [$mediaId]);

        $app->clock->advance(11 * 60); // the rooms service stopped writing 11 minutes ago
        $app->container->get(Cleaner::class)->run();

        self::assertDirectoryDoesNotExist($app->container->get(StoragePaths::class)->downloadDir($mediaId));
    }

    public function testMembersCanPrepareAnotherQualityOnce(): void
    {
        $app = new TestApp();
        $mediaId = $app->queueWatchMedia('hd');

        $variants = TestApp::decode($app->request('GET', '/api/watch/media/' . $mediaId . '/variants'))['variants'];
        self::assertIsArray($variants);
        self::assertSame(['v1080', 'v720', 'v480', 'v360'], array_column($variants, 'optionId'), 'up to WATCH_MAX_HEIGHT=1080');
        self::assertSame([null, $mediaId, null, null], array_column($variants, 'mediaId'));
        $current = $variants[1];
        self::assertIsArray($current);
        self::assertSame('queued', $current['status']);

        $post = fn (string $option) => $app->request('POST', '/api/watch/media/' . $mediaId . '/variants', ['optionId' => $option], ['Origin' => TestApp::APP_URL]);
        $first = $post('v480');
        $again = $post('v480');
        $same = $post('v720');

        self::assertSame(202, $first->getStatusCode(), (string) $first->getBody());
        $variantId = TestApp::decode($first)['mediaId'];
        self::assertIsString($variantId);
        self::assertNotSame($mediaId, $variantId);
        self::assertSame('v480', TestApp::decode($first)['optionId']);
        self::assertSame($variantId, TestApp::decode($again)['mediaId'], 'prepared only once');
        self::assertSame($mediaId, TestApp::decode($same)['mediaId'], 'the current variant is the media itself');
        self::assertSame('INVALID_OPTION', TestApp::errorCode($post('v2160')), 'above WATCH_MAX_HEIGHT');
        self::assertSame('INVALID_OPTION', TestApp::errorCode($post('bestvideo')), 'raw yt-dlp formats are never accepted');

        $listed = TestApp::decode($app->request('GET', '/api/watch/media/' . $variantId . '/variants'))['variants'];
        self::assertIsArray($listed);
        self::assertSame([null, $mediaId, $variantId, null], array_column($listed, 'mediaId'), 'any variant lists them all');
        self::assertSame(404, $app->request('GET', '/api/watch/media/' . str_repeat('a', 32) . '/variants')->getStatusCode());
    }

    /**
     * Writes storage/rooms/media-in-use.json like the rooms service does.
     *
     * @param list<string> $refs
     */
    private function publishInUse(TestApp $app, array $refs): void
    {
        $file = $app->container->get(StoragePaths::class)->roomsMediaInUseFile();
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0700, true);
        }
        file_put_contents($file, json_encode(['version' => 1, 'updatedAt' => $app->clock->now * 1000, 'refs' => $refs], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function mediaStatus(TestApp $app, string $mediaId): array
    {
        return TestApp::decode($app->request('GET', '/api/watch/media/' . $mediaId));
    }
}
