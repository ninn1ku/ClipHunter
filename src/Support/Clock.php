<?php

declare(strict_types=1);

namespace ClipHunter\Support;

interface Clock
{
    /** Current Unix timestamp in seconds. */
    public function now(): int;
}
