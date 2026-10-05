<?php

declare(strict_types=1);

namespace ClipHunter\Tests\Integration;

use ClipHunter\Process\ProcessOptions;
use ClipHunter\Process\ProcessRunner;
use ClipHunter\Process\TerminationReason;
use ClipHunter\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use PHPUnit\Framework\TestCase;

final class ProcessRunnerTest extends TestCase
{
    /**
     * @return non-empty-list<string>
     */
    private static function php(string $code): array
    {
        return [PHP_BINARY, '-r', $code];
    }

    public function testCapturesStdoutStderrAndExitCode(): void
    {
        $result = (new ProcessRunner())->run(
            self::php('echo "out"; fwrite(STDERR, "err"); exit(3);'),
            new ProcessOptions(timeoutSec: 10),
        );

        self::assertSame(TerminationReason::Exited, $result->reason);
        self::assertSame(3, $result->exitCode);
        self::assertSame('out', $result->stdout);
        self::assertSame('err', $result->stderrTail);
        self::assertFalse($result->succeeded());
    }

    public function testArgumentsAreNeverInterpretedByAShell(): void
    {
        $marker = TempDir::create('proc-') . '/pwned';
        $payload = '; echo hacked > ' . $marker . ' && $(touch ' . $marker . ') `touch ' . $marker . '` | cat';

        $result = (new ProcessRunner())->run(
            [PHP_BINARY, '-r', 'echo $argv[1];', '--', $payload],
            new ProcessOptions(timeoutSec: 10),
        );

        self::assertSame($payload, $result->stdout);
        self::assertFileDoesNotExist($marker);
    }

    public function testTimeoutKillsTheProcess(): void
    {
        $started = microtime(true);

        $result = (new ProcessRunner())->run(self::php('sleep(30);'), new ProcessOptions(timeoutSec: 1));

        self::assertSame(TerminationReason::Timeout, $result->reason);
        self::assertLessThan(8, microtime(true) - $started);
    }

    public function testStdoutLimitStopsRunawayOutput(): void
    {
        // Bounded (8 MiB) on purpose: a test must never be able to run away if the runner is broken.
        $result = (new ProcessRunner())->run(
            self::php('for ($i = 0; $i < 128; $i++) { echo str_repeat("x", 65536); }'),
            new ProcessOptions(timeoutSec: 20, maxStdoutBytes: 1024 * 1024),
        );

        self::assertSame(TerminationReason::OutputLimit, $result->reason);
        self::assertSame('', $result->stdout);
    }

    public function testStderrKeepsOnlyTheTail(): void
    {
        $result = (new ProcessRunner())->run(
            self::php('fwrite(STDERR, str_repeat("a", 20000) . "END");'),
            new ProcessOptions(timeoutSec: 10, stderrTailBytes: 100),
        );

        self::assertSame(100, strlen($result->stderrTail));
        self::assertStringEndsWith('END', $result->stderrTail);
    }

    public function testDeliversCompleteLinesToTheCallback(): void
    {
        $lines = [];
        (new ProcessRunner())->run(
            self::php('echo "one\ntwo\r\nthr"; usleep(200000); echo "ee\nlast";'),
            new ProcessOptions(timeoutSec: 10, onStdoutLine: static function (string $line) use (&$lines): void {
                $lines[] = $line;
            }),
        );

        self::assertSame(['one', 'two', 'three', 'last'], $lines);
    }

    public function testCancellationAndWatchdogStopTheProcess(): void
    {
        $runner = new ProcessRunner();

        $cancelled = $runner->run(self::php('sleep(30);'), new ProcessOptions(timeoutSec: 30, isCancelled: static fn (): bool => true));
        $watchdog = $runner->run(self::php('sleep(30);'), new ProcessOptions(timeoutSec: 30, watchdog: static fn (): string => 'disk_quota'));

        self::assertSame(TerminationReason::Cancelled, $cancelled->reason);
        self::assertSame(TerminationReason::Watchdog, $watchdog->reason);
        self::assertSame('disk_quota', $watchdog->watchdogDetail);
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function testKillsTheWholeProcessGroupIncludingGrandchildren(): void
    {
        $pidFile = TempDir::create('proc-') . '/child.pid';
        // The parent spawns a long-lived grandchild (like yt-dlp spawning ffmpeg) and hangs.
        $code = sprintf(
            '$p = proc_open(["sleep", "60"], [], $pipes); file_put_contents(%s, (string) proc_get_status($p)["pid"]); sleep(60);',
            var_export($pidFile, true),
        );

        $result = (new ProcessRunner())->run(self::php($code), new ProcessOptions(timeoutSec: 2));

        self::assertSame(TerminationReason::Timeout, $result->reason);
        $childPid = (int) file_get_contents($pidFile);
        self::assertGreaterThan(0, $childPid);
        usleep(200_000);
        self::assertFalse(file_exists('/proc/' . $childPid) && !str_contains((string) @file_get_contents('/proc/' . $childPid . '/stat'), ') Z '), 'grandchild must be killed');
    }
}
