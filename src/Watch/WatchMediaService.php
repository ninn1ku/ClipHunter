<?php

declare(strict_types=1);

namespace ClipHunter\Watch;

use ClipHunter\Config\AppConfig;
use ClipHunter\Exception\ApiException;
use ClipHunter\Exception\ErrorCode;
use ClipHunter\Job\DownloadJob;
use ClipHunter\Job\DownloadService;
use ClipHunter\Job\JobPurpose;
use ClipHunter\Job\JobStatus;
use ClipHunter\RateLimit\RateLimiter;
use ClipHunter\Support\Clock;

/**
 * Watch-room media (jobs with purpose "watch"): preparation status, the file, and the quality
 * variants a member can switch to. Each variant is its own watch job; the same video and
 * variant is prepared only once, whichever room or member asks for it.
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
        private RateLimiter $rateLimiter,
        private Clock $clock,
        private AppConfig $config,
    ) {
    }

    /**
     * Polled while a file is prepared and, as a keep-alive, while it is watched.
     *
     * @return array{mediaId: string, status: string, queuePosition: ?int, percent: ?float, error: array{code: string, message: string}|null, optionId: string, label: ?string}
     *
     * @throws ApiException MEDIA_NOT_FOUND
     */
    public function status(string $mediaId): array
    {
        $job = $this->downloads->find($mediaId, JobPurpose::Watch);
        $this->downloads->touch($job);

        return $this->describe($job);
    }

    /**
     * @return array{path: string, internalPath: string, mime: string, name: string, size: ?int}
     *
     * @throws ApiException MEDIA_NOT_FOUND, FILE_NOT_READY, FILE_EXPIRED
     */
    public function file(string $mediaId): array
    {
        $file = $this->downloads->file($mediaId, JobPurpose::Watch);
        $this->downloads->touch($this->downloads->find($mediaId, JobPurpose::Watch));

        return $file;
    }

    /**
     * The variants of the video behind a media id, each with the job preparing it (if any).
     *
     * @return list<array{optionId: string, label: string, height: int, sizeBytes: ?int, mediaId: ?string, status: ?string, percent: ?float}>
     *
     * @throws ApiException MEDIA_NOT_FOUND
     */
    public function variants(string $mediaId): array
    {
        $job = $this->downloads->find($mediaId, JobPurpose::Watch);
        $list = [];
        foreach ($job->variants as $variant) {
            $prepared = $variant['id'] === $job->optionId ? $job : $this->downloads->findWatch($job->url, $variant['id']);
            $state = $prepared === null ? null : $this->describe($prepared);
            $list[] = [
                'optionId' => $variant['id'],
                'label' => $variant['label'],
                'height' => $variant['height'],
                'sizeBytes' => $variant['sizeBytes'],
                'mediaId' => $state === null || $state['status'] === self::STATUS_EXPIRED ? null : $state['mediaId'],
                'status' => $state === null ? null : $state['status'],
                'percent' => $state === null ? null : $state['percent'],
            ];
        }

        return $list;
    }

    /**
     * Prepares (or finds) another variant of the same video for one member.
     *
     * @return array{mediaId: string, status: string, queuePosition: ?int, percent: ?float, error: array{code: string, message: string}|null, optionId: string, label: ?string}
     *
     * @throws ApiException MEDIA_NOT_FOUND, INVALID_OPTION, RATE_LIMITED, QUEUE_FULL, STORAGE_FULL, FILE_TOO_LARGE
     */
    public function prepareVariant(string $mediaId, string $optionId, string $ipHash): array
    {
        $job = $this->downloads->find($mediaId, JobPurpose::Watch);
        $variant = null;
        foreach ($job->variants as $candidate) {
            if ($candidate['id'] === $optionId) {
                $variant = $candidate;
            }
        }
        if ($variant === null) {
            throw new ApiException(ErrorCode::InvalidOption, 'variant not offered for this media');
        }
        if ($variant['id'] === $job->optionId && $this->describe($job)['status'] !== self::STATUS_EXPIRED) {
            return $this->describe($job);
        }

        $this->rateLimiter->hit(WatchSourceService::RATE_BUCKET, $ipHash, $this->config->watchSourcesRateLimit);
        $prepared = $this->downloads->createWatch(
            $job->analysisId,
            $job->url,
            $job->platformKey,
            $job->title,
            $job->expectedDurationSec,
            $variant['id'],
            $variant['sizeBytes'],
            $job->variants,
            $ipHash,
        );

        return $this->describe($prepared);
    }

    /**
     * @return array{mediaId: string, status: string, queuePosition: ?int, percent: ?float, error: array{code: string, message: string}|null, optionId: string, label: ?string}
     */
    private function describe(DownloadJob $job): array
    {
        $status = match ($job->status) {
            JobStatus::Queued => self::STATUS_QUEUED,
            JobStatus::Downloading, JobStatus::Processing => self::STATUS_PREPARING,
            JobStatus::Completed => $job->expiresAt !== null && $job->expiresAt <= $this->clock->now() ? self::STATUS_EXPIRED : self::STATUS_READY,
            JobStatus::Failed, JobStatus::Cancelled => self::STATUS_FAILED,
            JobStatus::Expired => self::STATUS_EXPIRED,
        };
        $label = null;
        foreach ($job->variants as $variant) {
            if ($variant['id'] === $job->optionId) {
                $label = $variant['label'];
            }
        }

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
            'optionId' => $job->optionId,
            'label' => $label,
        ];
    }
}
