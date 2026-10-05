<?php

declare(strict_types=1);

namespace ClipHunter\Process;

use Symfony\Component\Process\Process;

/**
 * Runs external binaries safely.
 *
 * - argv array only: no shell, so no shell syntax can ever be injected;
 * - hard wall-clock timeout, stdout size limit, cancellation and watchdog checks;
 * - on Linux the child gets its own session (setsid), so on termination the whole process
 *   group is killed — including ffmpeg spawned by yt-dlp — SIGTERM first, then SIGKILL.
 */
final class ProcessRunner
{
    private const POLL_INTERVAL_US = 50_000;
    private const KILL_GRACE_SEC = 2.0;

    private readonly bool $processGroups;

    public function __construct(?bool $processGroups = null)
    {
        $this->processGroups = $processGroups ?? (
            PHP_OS_FAMILY === 'Linux' && function_exists('posix_kill') && is_executable('/usr/bin/setsid')
        );
    }

    /**
     * @param non-empty-list<string> $command
     */
    public function run(array $command, ProcessOptions $options): ProcessResult
    {
        $process = new Process($this->wrap($command, $options), $options->cwd);
        $process->setTimeout(null);

        $stdout = '';
        $stdoutBytes = 0;
        $stderrTail = '';
        $lineBuffer = '';
        $outputExceeded = false;

        $started = hrtime(true);
        $process->start(static function (string $type, string $data) use (&$stdout, &$stdoutBytes, &$stderrTail, &$lineBuffer, &$outputExceeded, $options): void {
            if ($type !== Process::OUT) {
                $stderrTail = substr($stderrTail . $data, -$options->stderrTailBytes);

                return;
            }

            $stdoutBytes += strlen($data);
            if ($stdoutBytes > $options->maxStdoutBytes) {
                $outputExceeded = true;

                return;
            }

            if ($options->onStdoutLine === null) {
                $stdout .= $data;

                return;
            }

            $lineBuffer .= $data;
            $lines = preg_split('~\r\n|\r|\n~', $lineBuffer);
            if ($lines === false) {
                return;
            }
            $lineBuffer = (string) array_pop($lines);
            foreach ($lines as $line) {
                if ($line !== '') {
                    ($options->onStdoutLine)($line);
                }
            }
        });

        $reason = TerminationReason::Exited;
        $watchdogDetail = null;

        while ($process->isRunning()) {
            if ((hrtime(true) - $started) / 1e9 > $options->timeoutSec) {
                $reason = TerminationReason::Timeout;
            } elseif ($outputExceeded) {
                $reason = TerminationReason::OutputLimit;
            } elseif ($options->isCancelled !== null && ($options->isCancelled)()) {
                $reason = TerminationReason::Cancelled;
            } elseif ($options->watchdog !== null && ($watchdogDetail = ($options->watchdog)()) !== null) {
                $reason = TerminationReason::Watchdog;
            }

            if ($reason !== TerminationReason::Exited) {
                $this->terminate($process);
                break;
            }

            usleep(self::POLL_INTERVAL_US);
        }

        if ($reason === TerminationReason::Exited) {
            $process->wait();
            if ($outputExceeded) {
                $reason = TerminationReason::OutputLimit;
            }
            if ($lineBuffer !== '' && $options->onStdoutLine !== null && !$outputExceeded) {
                ($options->onStdoutLine)($lineBuffer);
            }
        }

        return new ProcessResult(
            exitCode: $process->getExitCode(),
            stdout: $outputExceeded ? '' : $stdout,
            stderrTail: $stderrTail,
            durationMs: intdiv(hrtime(true) - $started, 1_000_000),
            reason: $reason,
            watchdogDetail: $watchdogDetail,
        );
    }

    /**
     * @param non-empty-list<string> $command
     *
     * @return non-empty-list<string>
     */
    private function wrap(array $command, ProcessOptions $options): array
    {
        if (!$this->processGroups) {
            return $command;
        }

        // setsid execs (no fork: our child is not a group leader), so pid == pgid of the target.
        $prefix = ['/usr/bin/setsid'];
        if ($options->niceLevel !== null && is_executable('/usr/bin/nice')) {
            array_push($prefix, '/usr/bin/nice', '-n', (string) $options->niceLevel);
        }

        return [...$prefix, ...$command];
    }

    private function terminate(Process $process): void
    {
        $pid = $process->getPid();

        if (!$this->processGroups || $pid === null) {
            // Symfony: SIGTERM then SIGKILL on POSIX, `taskkill /T` (whole tree) on Windows.
            $process->stop(self::KILL_GRACE_SEC);

            return;
        }

        @posix_kill(-$pid, SIGTERM);
        $deadline = microtime(true) + self::KILL_GRACE_SEC;
        while ($process->isRunning() && microtime(true) < $deadline) {
            usleep(self::POLL_INTERVAL_US);
        }
        // Children may outlive the leader; always sweep the group.
        @posix_kill(-$pid, SIGKILL);
        $process->stop(0);
    }
}
