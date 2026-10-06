<?php

declare(strict_types=1);

namespace ClipHunter\Watch;

use ClipHunter\Exception\ApiException;
use ClipHunter\Job\DownloadService;
use ClipHunter\Job\JobPurpose;
use ClipHunter\Job\JobStatus;
use ClipHunter\Support\Clock;

/**
 * Preparation status and file lookup for watch-room media (jobs with purpose "watch").
 */
final readonly class WatchMediaService
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PREPARING = 'preparing';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';

    public function __construct(
        private DownloadService $downloads,
        private Clock $clock,
    ) {
    }

    /**
     * @return array{mediaId: string, status: string, queuePosition: ?int, percent: ?float, error: array{code: string, message: string}|null}
     *
     * @throws ApiException MEDIA_NOT_FOUND
     */
    public function status(string $mediaId): array
    {
        $job = $this->downloads->find($mediaId, JobPurpose::Watch);

        $status = match ($job->status) {
            JobStatus::Queued => self::STATUS_QUEUED,
            JobStatus::Downloading, JobStatus::Processing => self::STATUS_PREPARING,
            JobStatus::Completed => $job->expiresAt !== null && $job->expiresAt <= $this->clock->now() ? self::STATUS_EXPIRED : self::STATUS_READY,
            JobStatus::Failed, JobStatus::Cancelled => self::STATUS_FAILED,
            JobStatus::Expired => self::STATUS_EXPIRED,
        };

        return [
            'mediaId' => $job->id,
            'status' => $status,
            'queuePosition' => $status === self::STATUS_QUEUED ? $this->downloads->queuePosition($job->id) : null,
            'percent' => match ($status) {
                self::STATUS_PREPARING => $job->progress?->percent,
                self::STATUS_READY => 100.0,
                default => null,
            },
            'error' => $status === self::STATUS_FAILED && $job->error !== null
                ? ['code' => $job->error->value, 'message' => $job->error->defaultMessage()]
                : null,
        ];
    }

    /**
     * @return array{path: string, internalPath: string, mime: string, name: string, size: ?int}
     *
     * @throws ApiException MEDIA_NOT_FOUND, FILE_NOT_READY, FILE_EXPIRED
     */
    public function file(string $mediaId): array
    {
        return $this->downloads->file($mediaId, JobPurpose::Watch);
    }
}
