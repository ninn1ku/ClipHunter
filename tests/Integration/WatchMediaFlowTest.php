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

        self::assertSame(['mediaId' => $mediaId, 'status' => 'queued', 'queuePosition' => 0, 'percent' => null, 'error' => null], $this->mediaStatus($app, $mediaId));

        $app->runQueuedJobs();

        self::assertSame(['mediaId' => $mediaId, 'status' => 'ready', 'queuePosition' => null, 'percent' => 100.0, 'error' => null], $this->mediaStatus($app, $mediaId));
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

    public function testWatchFilesAreKeptForTheWatchRetentionPeriod(): void
    {
        $app = new TestApp();
        $app->clock->now = time(); // the cleaner compares against real file mtimes
        $mediaId = $app->queueWatchMedia();
        $app->runQueuedJobs();
        $cleaner = $app->container->get(Cleaner::class);
        $paths = $app->container->get(StoragePaths::class);

        $app->clock->advance(31 * 60); // past FILE_RETENTION_MIN for downloads
        $cleaner->run();
        self::assertFileExists($paths->downloadDir($mediaId) . '/media.mp4');
        self::assertSame('ready', $this->mediaStatus($app, $mediaId)['status']);

        $app->clock->advance(330 * 60); // 361 minutes in total, past WATCH_FILE_RETENTION_MIN=360
        self::assertSame('expired', $this->mediaStatus($app, $mediaId)['status'], 'expired even before the cleaner runs');
        $stats = $cleaner->run();

        self::assertSame(1, $stats['expired_files']);
        self::assertDirectoryDoesNotExist($paths->downloadDir($mediaId));
        self::assertSame(410, $app->request('GET', '/api/watch/media/' . $mediaId . '/file')->getStatusCode());
    }

    /**
     * @return array<string, mixed>
     */
    private function mediaStatus(TestApp $app, string $mediaId): array
    {
        return TestApp::decode($app->request('GET', '/api/watch/media/' . $mediaId));
    }
}
