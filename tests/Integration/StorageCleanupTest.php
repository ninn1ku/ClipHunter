<?php

declare(strict_types=1);

namespace ClipHunter\Tests\Integration;

use ClipHunter\Job\JobRepository;
use ClipHunter\Storage\Cleaner;
use ClipHunter\Storage\Filesystem;
use ClipHunter\Storage\StoragePaths;
use ClipHunter\Support\Ids;
use ClipHunter\Tests\Support\TempDir;
use ClipHunter\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StorageCleanupTest extends TestCase
{
    private static function app(): TestApp
    {
        $app = new TestApp();
        $app->clock->now = time(); // the cleaner compares against real file mtimes

        return $app;
    }

    public function testFinishedFilesExpireAfterTheRetentionPeriod(): void
    {
        $app = self::app();
        $jobId = $app->queueDownload();
        $app->runQueuedJobs();
        $paths = $app->container->get(StoragePaths::class);
        $cleaner = $app->container->get(Cleaner::class);

        $cleaner->run();
        self::assertFileExists($paths->downloadDir($jobId) . '/media.mp4', 'still within retention');

        $app->clock->advance(30 * 60);
        $stats = $cleaner->run();

        self::assertSame(1, $stats['expired_files']);
        self::assertDirectoryDoesNotExist($paths->downloadDir($jobId));
        self::assertSame('expired', $app->jobStatus($jobId)['status']);

        $app->clock->advance(24 * 3600);
        $cleaner->run();
        self::assertSame(404, $app->request('GET', '/api/downloads/' . $jobId)->getStatusCode(), 'job record removed after JOB_TTL');
    }

    public function testJobsOfDeadWorkersAreFailedAndTheirTempFilesRemoved(): void
    {
        $app = self::app();
        $jobId = $app->queueDownload();
        $jobs = $app->container->get(JobRepository::class);
        $paths = $app->container->get(StoragePaths::class);

        // A worker claims the job, creates temp files, then dies without releasing anything.
        $claimed = $jobs->claimNext();
        self::assertNotNull($claimed);
        mkdir($paths->tmpDir($jobId));
        file_put_contents($paths->tmpDir($jobId) . '/media.f133.mp4.part', 'partial');
        $claimed->unlock();
        touch($paths->jobsDir('running') . '/' . $jobId, time() - 120);

        $stats = $app->container->get(Cleaner::class)->run();

        self::assertSame(1, $stats['stale_jobs']);
        self::assertSame('failed', $app->jobStatus($jobId)['status']);
        self::assertDirectoryDoesNotExist($paths->tmpDir($jobId));
        self::assertSame([], $jobs->runningIds());
    }

    public function testRunningJobsHeldByALiveWorkerAreLeftAlone(): void
    {
        $app = self::app();
        $jobId = $app->queueDownload();
        $jobs = $app->container->get(JobRepository::class);
        $claimed = $jobs->claimNext();
        self::assertNotNull($claimed);
        touch($claimed->markerPath, time() - 3600);

        $stats = $app->container->get(Cleaner::class)->run();

        self::assertSame(0, $stats['stale_jobs']);
        self::assertSame([$jobId], $jobs->runningIds());
        $claimed->unlock();
    }

    public function testOrphanDirectoriesAndExpiredAnalysesAreRemoved(): void
    {
        $app = self::app();
        $paths = $app->container->get(StoragePaths::class);
        $orphan = $paths->tmpDir(Ids::generate());
        mkdir($orphan);
        file_put_contents($orphan . '/junk', 'x');
        touch($orphan, time() - 3600);
        $app->analyze('https://www.youtube.com/watch?v=ok');

        $app->clock->advance(31 * 60);
        $stats = $app->container->get(Cleaner::class)->run();

        self::assertSame(1, $stats['orphan_dirs']);
        self::assertSame(1, $stats['analyses']);
        self::assertDirectoryDoesNotExist($orphan);
    }

    public function testTwoWorkersNeverClaimTheSameJob(): void
    {
        $app = self::app();
        $app->queueDownload();
        $jobs = $app->container->get(JobRepository::class);
        $other = new \ClipHunter\Job\FilesystemJobRepository($app->container->get(StoragePaths::class));

        $first = $jobs->claimNext();
        $second = $other->claimNext();

        self::assertNotNull($first);
        self::assertNull($second);
        $first->unlock();
    }

    public function testRemoveTreeRefusesPathsOutsideStorage(): void
    {
        $storage = TempDir::create('fs-');
        $outside = TempDir::create('fs-outside-');
        file_put_contents($outside . '/precious', 'keep me');
        $fs = new Filesystem($storage);

        try {
            $fs->removeTree($storage . '/../' . basename($outside));
            self::fail('Expected refusal');
        } catch (RuntimeException) {
        }

        self::assertFileExists($outside . '/precious');
    }
}
