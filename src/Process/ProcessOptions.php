<?php

declare(strict_types=1);

namespace ClipHunter\Process;

use Closure;

final readonly class ProcessOptions
{
    /**
     * @param Closure(): ?string|null $watchdog polled while the process runs; a non-null return
     *                                          is a reason to kill it (e.g. "disk_quota")
     * @param Closure(): bool|null $isCancelled polled while the process runs
     * @param Closure(string): void|null $onStdoutLine receives complete stdout lines; stdout is then not buffered
     */
    public function __construct(
        public float $timeoutSec,
        public int $maxStdoutBytes = 16 * 1024 * 1024,
        public ?int $niceLevel = null,
        public ?string $cwd = null,
        public ?Closure $watchdog = null,
        public ?Closure $isCancelled = null,
        public ?Closure $onStdoutLine = null,
        public int $stderrTailBytes = 8192,
    ) {
    }
}
