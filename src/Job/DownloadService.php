<?php

declare(strict_types=1);

namespace ClipHunter\Job;

use ClipHunter\Analysis\AnalysisRepository;
use ClipHunter\Config\AppConfig;
use ClipHunter\Exception\ApiException;
use ClipHunter\Exception\ErrorCode;
use ClipHunter\Media\FormatArgs;
use ClipHunter\RateLimit\RateLimiter;
use ClipHunter\Storage\Filesystem;
use ClipHunter\Storage\StorageGuard;
use ClipHunter\Storage\StoragePaths;
use ClipHunter\Support\Clock;
use ClipHunter\Support\Ids;
use Psr\Log\LoggerInterface;

/**
 * API-side download use cases: create, status, file lookup, cancel.
 *
 * Every lookup is scoped to a {@see JobPurpose}: a watch-room file is not reachable through the
 * download endpoints (otherwise any room member who knows the media id could DELETE it), and
 * vice versa.
 */
final readonly class DownloadService
{
    public const RATE_BUCKET = 'downloads';

    public function __construct(
        private JobRepository $jobs,
        private AnalysisRepository $analyses,
        private RateLimiter $rateLimiter,
        private StorageGuard $guard,
        private StoragePaths $paths,
        private Filesystem $fs,
        private Clock $clock,
        private AppConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws ApiException
     */
    public function create(string $analysisId, string $optionId, string $ipHash, JobPurpose $purpose = JobPurpose::Download): DownloadJob
    {
        $this->rateLimiter->hit(self::RATE_BUCKET, $ipHash, $this->config->downloadRateLimit);

        $analysis = Ids::isValid($analysisId) ? $this->analyses->find($analysisId) : null;
        if ($analysis === null) {
            throw new ApiException(ErrorCode::AnalysisNotFound, 'analysis missing or expired');
        }
        $option = $analysis->option($optionId);
        if ($option === null) {
            throw new ApiException(ErrorCode::InvalidOption, 'option not offered');
        }
        if ($option->sizeBytes !== null && $option->sizeBytes > $this->config->maxFileSizeBytes) {
            throw new ApiException(ErrorCode::FileTooLarge, 'option exceeds max size');
        }

        $active = 0;
        foreach ([...$this->jobs->queuedIds(), ...$this->jobs->runningIds()] as $id) {
            if ($this->jobs->find($id)?->ipHash === $ipHash) {
                $active++;
            }
        }
        if ($active >= $this->config->maxActiveJobsPerIp) {
            throw new ApiException(ErrorCode::TooManyActiveJobs, 'active=' . $active);
        }
        if (count($this->jobs->queuedIds()) >= $this->config->maxQueueLength) {
            throw new ApiException(ErrorCode::QueueFull, 'queue full', headers: ['Retry-After' => '60']);
        }
        $this->guard->assertCapacityFor($option->sizeBytes ?? 0);

        $job = new DownloadJob(
            id: Ids::generate(),
            analysisId: $analysis->id,
            optionId: $option->id,
            url: $analysis->url,
            platformKey: $analysis->platformKey,
            title: $analysis->title,
            expectedDurationSec: $analysis->durationSec,
            expectedSizeBytes: $option->sizeBytes,
            ipHash: $ipHash,
            createdAt: $this->clock->now(),
            purpose: $purpose,
        );
        $this->jobs->create($job);
        $this->logger->info('job.queued', ['job_id' => $job->id, 'analysis_id' => $analysis->id, 'option' => $option->id, 'purpose' => $purpose->value]);

        return $job;
    }

    /**
     * Queues the preparation of a watch-room file, or returns an existing job for the same video
     * and variant that is still queued, running or ready (another room, another member).
     *
     * Unlike downloads there is no "one active job per client" rule: a room file simply waits in
     * the queue (whose length is still bounded), so the client sees a queue position instead of
     * an error about some other preparation it may not even know about.
     *
     * @param list<array{id: string, label: string, height: int, sizeBytes: ?int}> $variants
     *
     * @throws ApiException QUEUE_FULL, STORAGE_FULL
     */
    public function createWatch(
        string $analysisId,
        string $url,
        string $platformKey,
        string $title,
        ?int $durationSec,
        string $optionId,
        ?int $expectedSizeBytes,
        array $variants,
        string $ipHash,
    ): DownloadJob {
        $existing = $this->findWatch($url, $optionId);
        if ($existing !== null) {
            $this->logger->info('job.reused', ['job_id' => $existing->id, 'option' => $optionId]);

            return $existing;
        }
        if ($expectedSizeBytes !== null && $expectedSizeBytes > $this->config->maxFileSizeBytes) {
            throw new ApiException(ErrorCode::FileTooLarge, 'variant exceeds max size');
        }
        if (count($this->jobs->queuedIds()) >= $this->config->maxQueueLength) {
            throw new ApiException(ErrorCode::QueueFull, 'queue full', headers: ['Retry-After' => '60']);
        }
        $this->guard->assertCapacityFor($expectedSizeBytes ?? 0);

        $job = new DownloadJob(
            id: Ids::generate(),
            analysisId: $analysisId,
            optionId: $optionId,
            url: $url,
            platformKey: $platformKey,
            title: $title,
            expectedDurationSec: $durationSec,
            expectedSizeBytes: $expectedSizeBytes,
            ipHash: $ipHash,
            createdAt: $this->clock->now(),
            purpose: JobPurpose::Watch,
            variants: $variants,
        );
        $this->jobs->create($job);
        $this->logger->info('job.queued', ['job_id' => $job->id, 'analysis_id' => $analysisId, 'option' => $optionId, 'purpose' => 'watch']);

        return $job;
    }

    /**
     * A watch job for this video and variant that is queued, running, or completed with its file
     * still on disk; the most recent one wins.
     */
    public function findWatch(string $url, string $optionId): ?DownloadJob
    {
        $now = $this->clock->now();
        $best = null;
        foreach ($this->jobs->allIds() as $id) {
            $job = $this->jobs->find($id);
            if ($job === null || $job->purpose !== JobPurpose::Watch || $job->url !== $url || $job->optionId !== $optionId) {
                continue;
            }
            $usable = $job->status->isActive() && !$this->jobs->isCancelRequested($job->id)
                || $job->status === JobStatus::Completed && ($job->expiresAt ?? 0) > $now
                    && is_file($this->paths->downloadDir($job->id) . '/media.' . ($job->fileExt ?? 'mp4'));
            if ($usable && ($best === null || $job->createdAt > $best->createdAt)) {
                $best = $job;
            }
        }

        return $best;
    }

    /**
     * Records that a room file is being used (status poll, playback, keep-alive), at most once a minute.
     */
    public function touch(DownloadJob $job): void
    {
        $now = $this->clock->now();
        if ($job->status === JobStatus::Completed && ($job->lastAccessAt === null || $now - $job->lastAccessAt >= 60)) {
            $job->lastAccessAt = $now;
            $this->jobs->save($job);
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ApiException JOB_NOT_FOUND
     */
    public function status(string $jobId): array
    {
        $job = $this->find($jobId);
        $status = $job->status;
        if ($status === JobStatus::Queued && $this->jobs->isCancelRequested($job->id)) {
            $status = JobStatus::Cancelled;
        }

        $file = null;
        if ($status === JobStatus::Completed && $job->fileName !== null && $job->expiresAt !== null) {
            $file = [
                'name' => $job->fileName,
                'sizeBytes' => $job->fileSizeBytes,
                'url' => '/api/downloads/' . $job->id . '/file',
                'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', $job->expiresAt),
            ];
        }

        return [
            'jobId' => $job->id,
            'status' => $status->value,
            'queuePosition' => $status === JobStatus::Queued ? $this->queuePosition($job->id) : null,
            'progress' => $status->isActive() || $status === JobStatus::Completed ? $job->progress?->toArray() : null,
            'file' => $file,
            'error' => $job->error !== null && $status->isFinal() && $status !== JobStatus::Completed
                ? ['code' => $job->error->value, 'message' => $job->error->defaultMessage()]
                : null,
        ];
    }

    /**
     * @return array{path: string, internalPath: string, mime: string, name: string, size: ?int}
     *
     * @throws ApiException JOB_NOT_FOUND (MEDIA_NOT_FOUND for watch jobs), FILE_NOT_READY, FILE_EXPIRED
     */
    public function file(string $jobId, JobPurpose $purpose = JobPurpose::Download): array
    {
        $job = $this->find($jobId, $purpose);

        if ($job->status === JobStatus::Expired || ($job->expiresAt !== null && $job->expiresAt <= $this->clock->now())) {
            throw new ApiException(ErrorCode::FileExpired, 'expired');
        }
        if ($job->status !== JobStatus::Completed || $job->fileExt === null || $job->fileName === null) {
            throw new ApiException(ErrorCode::FileNotReady, 'status=' . $job->status->value);
        }

        $relative = $job->id . '/media.' . $job->fileExt;
        $path = $this->paths->downloadDir($job->id) . '/media.' . $job->fileExt;
        if (!is_file($path)) {
            throw new ApiException(ErrorCode::FileExpired, 'file missing on disk');
        }

        return [
            'path' => $path,
            'internalPath' => $relative,
            'mime' => FormatArgs::mimeType($job->optionId),
            'name' => $job->fileName,
            'size' => $job->fileSizeBytes,
        ];
    }

    /**
     * Cancels an active job or deletes a finished file early.
     *
     * @throws ApiException JOB_NOT_FOUND
     */
    public function cancel(string $jobId): void
    {
        $job = $this->find($jobId);

        if ($job->status === JobStatus::Queued && $this->jobs->dequeue($job->id)) {
            $job->status = JobStatus::Cancelled;
            $job->error = ErrorCode::Cancelled;
            $job->finishedAt = $this->clock->now();
            $this->jobs->save($job);
            $this->logger->info('job.cancelled', ['job_id' => $job->id, 'while' => 'queued']);

            return;
        }

        if ($job->status->isActive()) {
            // Already claimed by a worker: it polls this flag and kills yt-dlp.
            $this->jobs->requestCancel($job->id);
            $this->logger->info('job.cancel_requested', ['job_id' => $job->id]);

            return;
        }

        if ($job->status === JobStatus::Completed) {
            $this->fs->removeTree($this->paths->downloadDir($job->id));
            $job->status = JobStatus::Expired;
            $this->jobs->save($job);
            $this->logger->info('job.deleted_by_user', ['job_id' => $job->id]);
        }
    }

    /**
     * @throws ApiException JOB_NOT_FOUND, or MEDIA_NOT_FOUND when looking for a watch job
     */
    public function find(string $jobId, JobPurpose $purpose = JobPurpose::Download): DownloadJob
    {
        $job = Ids::isValid($jobId) ? $this->jobs->find($jobId) : null;
        if ($job === null || $job->purpose !== $purpose) {
            $code = $purpose === JobPurpose::Watch ? ErrorCode::MediaNotFound : ErrorCode::JobNotFound;

            throw new ApiException($code, $job === null ? 'job missing' : 'purpose mismatch');
        }

        return $job;
    }

    /**
     * Zero-based position in the queue, or null when the job is not queued.
     */
    public function queuePosition(string $jobId): ?int
    {
        $position = array_search($jobId, $this->jobs->queuedIds(), true);

        return is_int($position) ? $position : null;
    }
}
