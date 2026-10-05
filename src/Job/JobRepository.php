<?php

declare(strict_types=1);

namespace ClipHunter\Job;

/**
 * Persistence and queueing for download jobs. The filesystem implementation is enough for a
 * single host; a Redis/SQLite implementation can replace it without touching the services.
 */
interface JobRepository
{
    public function create(DownloadJob $job): void;

    public function save(DownloadJob $job): void;

    public function find(string $id): ?DownloadJob;

    /** Atomically takes the oldest queued job, or returns null when the queue is empty. */
    public function claimNext(): ?ClaimedJob;

    /** Finishes work on a claimed job (the job itself must already be saved). */
    public function release(ClaimedJob $claimed): void;

    /** Puts a claimed job back at its original queue position (graceful worker shutdown). */
    public function requeue(ClaimedJob $claimed): void;

    /** Removes a job from the queue if no worker has claimed it yet. */
    public function dequeue(string $id): bool;

    /** @return list<string> queued job ids, oldest first */
    public function queuedIds(): array;

    /** @return list<string> ids of jobs being processed */
    public function runningIds(): array;

    /** @return list<string> ids of running jobs whose worker no longer holds the lock */
    public function staleRunningIds(int $minAgeSec): array;

    public function requestCancel(string $id): void;

    public function isCancelRequested(string $id): bool;

    /** @return list<string> every stored job id */
    public function allIds(): array;

    public function delete(string $id): void;
}
