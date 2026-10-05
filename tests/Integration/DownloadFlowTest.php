<?php

declare(strict_types=1);

namespace ClipHunter\Tests\Integration;

use ClipHunter\Job\JobRepository;
use ClipHunter\Storage\StoragePaths;
use ClipHunter\Tests\Support\TestApp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end download flow through the HTTP API, the job queue and the runner, using the fake
 * yt-dlp (which writes real tiny media files) and the real ffprobe.
 */
final class DownloadFlowTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/media';

    public function testVideoDownloadCompletesAndIsServed(): void
    {
        $app = new TestApp();

        $jobId = $app->queueDownload('ok', 'v240');
        $queued = $app->jobStatus($jobId);
        self::assertSame('queued', $queued['status']);
        self::assertSame(0, $queued['queuePosition']);

        self::assertSame(1, $app->runQueuedJobs());

        $status = $app->jobStatus($jobId);
        self::assertSame('completed', $status['status'], json_encode($status) ?: '');
        self::assertSame([
            'name' => 'Me at the zoo.mp4',
            'sizeBytes' => filesize(self::FIXTURES . '/sample.mp4'),
            'url' => '/api/downloads/' . $jobId . '/file',
            'expiresAt' => '2027-01-15T08:30:00Z',
        ], $status['file']);
        self::assertIsArray($status['progress']);
        self::assertSame(100.0, $status['progress']['percent']);

        $file = $app->request('GET', '/api/downloads/' . $jobId . '/file');
        self::assertSame(200, $file->getStatusCode());
        self::assertSame('video/mp4', $file->getHeaderLine('Content-Type'));
        self::assertSame('attachment; filename="Me at the zoo.mp4"; filename*=UTF-8\'\'Me%20at%20the%20zoo.mp4', $file->getHeaderLine('Content-Disposition'));
        self::assertSame('nosniff', $file->getHeaderLine('X-Content-Type-Options'));
        self::assertSame(file_get_contents(self::FIXTURES . '/sample.mp4'), (string) $file->getBody());

        // Temp files are gone; the finished file lives under downloads/<id>/ only.
        $paths = $app->container->get(StoragePaths::class);
        self::assertDirectoryDoesNotExist($paths->tmpDir($jobId));
        self::assertFileExists($paths->downloadDir($jobId) . '/media.mp4');
    }

    public function testMp3OptionProducesAnAudioFile(): void
    {
        $app = new TestApp();
        $jobId = $app->queueDownload('ok', 'a-mp3');

        $app->runQueuedJobs();
        $file = $app->request('GET', '/api/downloads/' . $jobId . '/file');

        self::assertSame('completed', $app->jobStatus($jobId)['status']);
        self::assertSame('audio/mpeg', $file->getHeaderLine('Content-Type'));
        self::assertStringContainsString('filename="Me at the zoo.mp3"', $file->getHeaderLine('Content-Disposition'));
    }

    public function testProductionDeliveryHandsTheFileToNginx(): void
    {
        $app = new TestApp(['FILE_DELIVERY' => 'xaccel']);
        $jobId = $app->queueDownload();
        $app->runQueuedJobs();

        $file = $app->request('GET', '/api/downloads/' . $jobId . '/file');

        self::assertSame('/_files/' . $jobId . '/media.mp4', $file->getHeaderLine('X-Accel-Redirect'));
        self::assertSame('', (string) $file->getBody());
    }

    /**
     * @return iterable<string, array{string, string, array<string, string>}>
     */
    public static function failures(): iterable
    {
        yield 'platform refuses' => ['dlfail', 'DOWNLOAD_FAILED', []];
        yield 'skipped as too large' => ['skip', 'FILE_TOO_LARGE', []];
        yield 'output over size limit' => ['big', 'FILE_TOO_LARGE', ['MAX_FILE_SIZE_MB' => '1']];
        yield 'not a media file' => ['corrupt', 'PROCESSING_FAILED', []];
        yield 'timeout' => ['hang', 'DOWNLOAD_TIMEOUT', ['DOWNLOAD_TIMEOUT_SEC' => '5']];
    }

    /**
     * @param array<string, string> $env
     */
    #[DataProvider('failures')]
    public function testFailuresAreReportedAndCleanedUp(string $scenario, string $code, array $env): void
    {
        $app = new TestApp($env);
        // Analyse the regular fixture, then make the download step hit the failure scenario.
        $jobId = $app->queueDownload();
        $this->rewriteJobUrl($app, $jobId, $scenario);

        $app->runQueuedJobs();
        $status = $app->jobStatus($jobId);

        self::assertSame('failed', $status['status']);
        self::assertSame($code, is_array($status['error']) ? $status['error']['code'] : null);
        self::assertNull($status['file']);
        $paths = $app->container->get(StoragePaths::class);
        self::assertDirectoryDoesNotExist($paths->tmpDir($jobId));
        self::assertDirectoryDoesNotExist($paths->downloadDir($jobId));
        self::assertSame(409, $app->request('GET', '/api/downloads/' . $jobId . '/file')->getStatusCode());
    }

    public function testQueuedJobCanBeCancelledBeforeAWorkerTakesIt(): void
    {
        $app = new TestApp();
        $jobId = $app->queueDownload();

        $cancel = $app->request('DELETE', '/api/downloads/' . $jobId, headers: ['Origin' => TestApp::APP_URL]);

        self::assertSame(204, $cancel->getStatusCode());
        self::assertSame('cancelled', $app->jobStatus($jobId)['status']);
        self::assertSame(0, $app->runQueuedJobs(), 'a cancelled job never reaches the worker');
    }

    public function testWorkerShutdownRequeuesTheJob(): void
    {
        $app = new TestApp();
        $jobId = $app->queueDownload('ok', 'v240');
        $this->rewriteJobUrl($app, $jobId, 'slow');
        $polls = 0;

        $app->runQueuedJobs(static function () use (&$polls): bool {
            return ++$polls > 3;
        });

        self::assertSame('queued', $app->jobStatus($jobId)['status']);
        self::assertSame([$jobId], $app->container->get(JobRepository::class)->queuedIds());
        self::assertDirectoryDoesNotExist($app->container->get(StoragePaths::class)->tmpDir($jobId));
    }

    public function testDeletingAFinishedDownloadRemovesTheFile(): void
    {
        $app = new TestApp();
        $jobId = $app->queueDownload();
        $app->runQueuedJobs();

        $app->request('DELETE', '/api/downloads/' . $jobId, headers: ['Origin' => TestApp::APP_URL]);

        self::assertSame('expired', $app->jobStatus($jobId)['status']);
        self::assertDirectoryDoesNotExist($app->container->get(StoragePaths::class)->downloadDir($jobId));
        self::assertSame(410, $app->request('GET', '/api/downloads/' . $jobId . '/file')->getStatusCode());
    }

    public function testOneActiveDownloadPerClientAndBoundedQueue(): void
    {
        $app = new TestApp(['MAX_QUEUE_LENGTH' => '2', 'RATE_LIMIT_DOWNLOADS' => '100/3600', 'RATE_LIMIT_ANALYZE' => '100/600']);

        $app->queueDownload();
        $analysis = TestApp::decode($app->analyze('https://www.youtube.com/watch?v=ok'));
        $second = $app->request('POST', '/api/downloads', ['analysisId' => $analysis['analysisId'], 'optionId' => 'v144']);
        self::assertSame(429, $second->getStatusCode());
        self::assertSame('TOO_MANY_ACTIVE_JOBS', TestApp::errorCode($second));

        $app->queueDownload('ok', 'v240', ['REMOTE_ADDR' => '198.51.100.1']);
        $analysis = TestApp::decode($app->analyze('https://www.youtube.com/watch?v=ok', ['REMOTE_ADDR' => '198.51.100.2']));
        $third = $app->request('POST', '/api/downloads', ['analysisId' => $analysis['analysisId'], 'optionId' => 'v240'], server: ['REMOTE_ADDR' => '198.51.100.2']);
        self::assertSame(503, $third->getStatusCode());
        self::assertSame('QUEUE_FULL', TestApp::errorCode($third));
    }

    public function testRequestsForUnknownOrMalformedIdsAreRejected(): void
    {
        $app = new TestApp();
        $analysis = TestApp::decode($app->analyze('https://www.youtube.com/watch?v=ok'));

        $badOption = $app->request('POST', '/api/downloads', ['analysisId' => $analysis['analysisId'], 'optionId' => 'v1080']); // not offered for this video
        $rawFormat = $app->request('POST', '/api/downloads', ['analysisId' => $analysis['analysisId'], 'optionId' => 'bestvideo+bestaudio']);
        self::assertSame('INVALID_REQUEST', TestApp::errorCode($rawFormat), 'raw yt-dlp format strings are never accepted');
        $missingAnalysis = $app->request('POST', '/api/downloads', ['analysisId' => str_repeat('0', 32), 'optionId' => 'v240']);
        $traversalAnalysis = $app->request('POST', '/api/downloads', ['analysisId' => '../../etc/passwd', 'optionId' => 'v240']);

        self::assertSame('INVALID_OPTION', TestApp::errorCode($badOption));
        self::assertSame('ANALYSIS_NOT_FOUND', TestApp::errorCode($missingAnalysis));
        self::assertSame('ANALYSIS_NOT_FOUND', TestApp::errorCode($traversalAnalysis));

        self::assertSame(404, $app->request('GET', '/api/downloads/' . str_repeat('a', 32))->getStatusCode());
        self::assertSame('NOT_FOUND', TestApp::errorCode($app->request('GET', '/api/downloads/..%2F..%2Fetc%2Fpasswd/file')));
        self::assertSame('NOT_FOUND', TestApp::errorCode($app->request('GET', '/api/downloads/ABCDEF/file')));
    }

    public function testExpiredAnalysisCannotBeDownloaded(): void
    {
        $app = new TestApp();
        $analysis = TestApp::decode($app->analyze('https://www.youtube.com/watch?v=ok'));
        $app->clock->advance(31 * 60);

        $response = $app->request('POST', '/api/downloads', ['analysisId' => $analysis['analysisId'], 'optionId' => 'v240']);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('ANALYSIS_NOT_FOUND', TestApp::errorCode($response));
    }

    /**
     * Points a queued job at another fake scenario (the fake picks behaviour from ?v=).
     */
    private function rewriteJobUrl(TestApp $app, string $jobId, string $scenario): void
    {
        $paths = $app->container->get(StoragePaths::class);
        $raw = (string) file_get_contents($paths->jobFile($jobId));
        file_put_contents($paths->jobFile($jobId), str_replace('watch?v=ok', 'watch?v=' . $scenario, $raw));
    }
}
