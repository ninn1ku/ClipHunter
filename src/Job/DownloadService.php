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
    public function create(string $analysisId, string $optionId, string $ipHash): DownloadJob
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
        );
        $this->jobs->create($job);
        $this->logger->info('job.queued', ['job_id' => $job->id, 'analysis_id' => $analysis->id, 'option' => $option->id]);

        return $job;
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

        $position = array_search($job->id, $this->jobs->queuedIds(), true);
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
            'queuePosition' => $status === JobStatus::Queued && is_int($position) ? $position : null,
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
     * @throws ApiException JOB_NOT_FOUND, FILE_NOT_READY, FILE_EXPIRED
     */
    public function file(string $jobId): array
    {
        $job = $this->find($jobId);

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

    private function find(string $jobId): DownloadJob
    {
        $job = Ids::isValid($jobId) ? $this->jobs->find($jobId) : null;
        if ($job === null) {
            throw new ApiException(ErrorCode::JobNotFound, 'job missing');
        }

        return $job;
    }
}
