<?php

declare(strict_types=1);

namespace ClipHunter\Process;

/**
 * Why a process stopped. Anything other than Exited means ProcessRunner killed it.
 */
enum TerminationReason: string
{
    case Exited = 'exited';
    case Timeout = 'timeout';
    case OutputLimit = 'output_limit';
    case Cancelled = 'cancelled';
    case Watchdog = 'watchdog';
}
