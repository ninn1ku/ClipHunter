<?php

declare(strict_types=1);

namespace ClipHunter\Job;

use Psr\Log\LoggerInterface;

/**
 * Long-running loop: claim the oldest queued job, run it, repeat.
 *
 * One worker processes one job at a time; MAX_CONCURRENT_DOWNLOADS is the number of worker
 * processes (systemd template instances). SIGTERM/SIGINT stop the loop; a job interrupted by
 * shutdown is requeued instead of failed.
 */
final class Worker
{
    private const IDLE_SLEEP_US = 500_000;

    private bool $stopping = false;

    public function __construct(
        private readonly JobRepository $jobs,
        private readonly JobRunner $runner,
        private readonly LoggerInterface $logger,
        private readonly string $heartbeatFile,
    ) {
    }

    /**
     * @param int $maxJobs exit after this many jobs (systemd restarts the worker; bounds leaks)
     */
    public function run(int $maxJobs = 200, ?int $maxIdleLoops = null): void
    {
        $this->installSignalHandlers();
        $this->logger->info('worker.started', ['pid' => getmypid()]);

        $processed = 0;
        $idleLoops = 0;
        while (!$this->stopping && $processed < $maxJobs) {
            @touch($this->heartbeatFile);

            $claimed = $this->jobs->claimNext();
            if ($claimed === null) {
                if ($maxIdleLoops !== null && ++$idleLoops >= $maxIdleLoops) {
                    break;
                }
                usleep(self::IDLE_SLEEP_US);
                continue;
            }
            $idleLoops = 0;

            $finished = $this->runner->run($claimed, fn (): bool => $this->stopping);
            if ($finished) {
                $this->jobs->release($claimed);
                $processed++;
            } else {
                $this->jobs->requeue($claimed);
            }
        }

        $this->logger->info('worker.stopped', ['pid' => getmypid(), 'processed' => $processed]);
    }

    public function stop(): void
    {
        $this->stopping = true;
    }

    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_async_signals')) {
            return;
        }
        pcntl_async_signals(true);
        $handler = function (): void {
            $this->stop();
        };
        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
    }
}
