<?php

declare(strict_types=1);

namespace ClipHunter\Job;

enum JobStatus: string
{
    case Queued = 'queued';
    case Downloading = 'downloading';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function isActive(): bool
    {
        return $this === self::Queued || $this === self::Downloading || $this === self::Processing;
    }

    public function isFinal(): bool
    {
        return !$this->isActive();
    }
}
