<?php

declare(strict_types=1);

namespace ClipHunter\Job;

/**
 * A job taken from the queue by a worker. The worker holds an exclusive flock() on the
 * running marker for as long as it works on the job; a free lock means the worker died.
 */
final class ClaimedJob
{
    /**
     * @param resource|null $lock
     */
    public function __construct(
        public DownloadJob $job,
        public readonly string $markerPath,
        private $lock,
    ) {
    }

    public function unlock(): void
    {
        if ($this->lock !== null) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
    }

    public function __destruct()
    {
        $this->unlock();
    }
}
