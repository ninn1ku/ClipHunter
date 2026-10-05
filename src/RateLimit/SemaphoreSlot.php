<?php

declare(strict_types=1);

namespace ClipHunter\RateLimit;

final class SemaphoreSlot
{
    /** @var resource|null */
    private $handle;

    /**
     * @param resource $handle a file handle holding an exclusive flock()
     */
    public function __construct($handle)
    {
        $this->handle = $handle;
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
