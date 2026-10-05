<?php

declare(strict_types=1);

namespace ClipHunter\Process;

final readonly class ProcessResult
{
    public function __construct(
        public ?int $exitCode,
        public string $stdout,
        public string $stderrTail,
        public int $durationMs,
        public TerminationReason $reason,
        public ?string $watchdogDetail = null,
    ) {
    }

    public function succeeded(): bool
    {
        return $this->reason === TerminationReason::Exited && $this->exitCode === 0;
    }
}
