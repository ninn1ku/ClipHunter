<?php

declare(strict_types=1);

namespace ClipHunter\Tests\Support;

use ClipHunter\Support\Clock;

final class FrozenClock implements Clock
{
    public function __construct(public int $now = 1_800_000_000)
    {
    }

    public function now(): int
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now += $seconds;
    }
}
