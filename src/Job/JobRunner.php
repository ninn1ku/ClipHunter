<?php

declare(strict_types=1);

namespace ClipHunter\Job;

use ClipHunter\Config\AppConfig;
use ClipHunter\Exception\ApiException;
use ClipHunter\Exception\ErrorCode;
use ClipHunter\Media\FormatArgs;
use ClipHunter\Media\MediaProbe;
use ClipHunter\Media\YtDlpClient;
use ClipHunter\Process\ProcessOptions;
use ClipHunter\Process\ProcessResult;
use ClipHunter\Process\TerminationReason;
use ClipHunter\Security\FilenameSanitizer;
use ClipHunter\Security\UrlValidator;
use ClipHunter\Storage\Filesystem;
use ClipHunter\Storage\StorageGuard;
use ClipHunter\Storage\StoragePaths;
use ClipHunter\Support\Clock;
use Closure;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Executes one claimed download job:
 * re-validate URL → capacity check → yt-dlp (+ffmpeg) into tmp/<id> under a watchdog →
 * ffprobe verification → atomic move into downloads/<id> → completed.
 */
final readonly class JobRunner
{
    private const SAVE_INTERVAL_SEC = 1;
    private const WATCHDOG_INTERVAL_SEC = 2;
    private const TMP_OVERHEAD_BYTES = 64 * 1024 * 1024;

    public function __construct(
        private JobRepository $jobs,
        private UrlValidator $validator,
        private YtDlpClient $ytDlp,
        private MediaProbe $probe,
        private StoragePaths $paths,
        private StorageGuard $guard,
        private Filesystem $fs,
        private Clock $clock,
        private AppConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param Closure(): bool $shouldStop worker shutdown requested
     *
     * @return bool true when the job finished (in any final state), false when it must be requeued
     */
    public function run(ClaimedJob $claimed, Closure $shouldStop): bool
    {
        $job = $claimed->job;
        $tmp = $this->paths->tmpDir($job->id);
        $started = hrtime(true);

        if ($this->jobs->isCancelRequested($job->id)) {
            $this->finish($job, JobStatus::Cancelled, ErrorCode::Cancelled);
            $this->jobs->save($job);

            return true;
        }

        $job->status = JobStatus::Downloading;
        $job->startedAt = $this->clock->now();
        $job->progress = new Progress(0.0, 0, $job->expectedSizeBytes, null, null);
        $this->jobs->save($job);
        $this->logger->info('job.started', ['job_id' => $job->id, 'option' => $job->optionId, 'platform' => $job->platformKey]);

        $requeue = false;
        try {
            $requeue = $this->execute($job, $tmp, $shouldStop);
        } catch (ApiException $e) {
            $this->finish($job, JobStatus::Failed, $e->errorCode);
            $this->logger->warning('job.failed', ['job_id' => $job->id, 'code' => $e->errorCode->value, 'detail' => $e->getMessage()]);
        } catch (Throwable $e) {
            $this->finish($job, JobStatus::Failed, ErrorCode::InternalError);
            $this->logger->error('job.crashed', ['job_id' => $job->id, 'exception' => $e::class, 'message' => $e->getMessage(), 'location' => $e->getFile() . ':' . $e->getLine()]);
        } finally {
            $this->fs->removeTree($tmp);
        }

        if ($requeue) {
            $job->status = JobStatus::Queued;
            $job->progress = null;
            $job->startedAt = null;
            $this->jobs->save($job);
            $this->logger->notice('job.requeued', ['job_id' => $job->id]);

            return false;
        }

        $this->jobs->save($job);
        $this->logger->info('job.finished', [
            'job_id' => $job->id,
            'status' => $job->status->value,
            'error' => $job->error?->value,
            'size_bytes' => $job->fileSizeBytes,
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
        ]);

        return true;
    }

    /**
     * @param Closure(): bool $shouldStop
     *
     * @return bool true if the job must be requeued (worker shutdown)
     */
    private function execute(DownloadJob $job, string $tmp, Closure $shouldStop): bool
    {
        // DNS may have changed since the analysis; validate again right before downloading.
        $url = $this->validator->validate($job->url);
        $this->guard->assertCapacityFor($job->expectedSizeBytes ?? 0);

        if (!is_dir($tmp) && !@mkdir($tmp, 0750, true)) {
            throw new RuntimeException('Cannot create job tmp dir.');
        }

        $ext = FormatArgs::extension($job->optionId);
        $tracker = new ProgressTracker($job->expectedSizeBytes);
        $lastSave = 0;
        $lastWatchdog = 0;
        $maxTmp = $this->config->maxFileSizeBytes * 2 + self::TMP_OVERHEAD_BYTES;

        $result = $this->ytDlp->run('download', $url, [
            ...$this->ytDlp->baseArgs($url),
            ...FormatArgs::for($job->optionId),
            '--max-filesize', (string) $this->config->maxFileSizeBytes,
            '--match-filters', sprintf('!is_live & duration <=? %d', $this->config->maxVideoDurationSec),
            '--ffmpeg-location', $this->config->ffmpegPath,
            '--paths', 'home:' . $tmp,
            '--paths', 'temp:' . $tmp,
            '--output', 'media.%(ext)s',
            '--no-mtime',
            '--newline',
            '--progress-template', 'download:' . ProgressTracker::MARKER . '%(progress)j',
            '--',
            $url->url,
        ], new ProcessOptions(
            timeoutSec: $this->config->downloadTimeoutSec,
            maxStdoutBytes: 256 * 1024 * 1024,
            niceLevel: 10,
            watchdog: function () use (&$lastWatchdog, $tmp, $maxTmp): ?string {
                $now = $this->clock->now();
                if ($now - $lastWatchdog < self::WATCHDOG_INTERVAL_SEC) {
                    return null;
                }
                $lastWatchdog = $now;

                return $this->guard->violation($tmp, $maxTmp);
            },
            isCancelled: fn (): bool => $shouldStop() || $this->jobs->isCancelRequested($job->id),
            onStdoutLine: function (string $line) use ($tracker, $job, &$lastSave): void {
                if (!$tracker->consume($line)) {
                    return;
                }
                $now = $this->clock->now();
                $phaseChanged = $tracker->isProcessing() && $job->status !== JobStatus::Processing;
                if ($phaseChanged || $now - $lastSave >= self::SAVE_INTERVAL_SEC) {
                    $job->progress = $tracker->progress();
                    if ($tracker->isProcessing()) {
                        $job->status = JobStatus::Processing;
                    }
                    $this->jobs->save($job);
                    $lastSave = $now;
                }
            },
        ));

        if ($result->reason === TerminationReason::Cancelled) {
            if ($shouldStop() && !$this->jobs->isCancelRequested($job->id)) {
                return true;
            }
            $this->finish($job, JobStatus::Cancelled, ErrorCode::Cancelled);

            return false;
        }

        $this->assertDownloaded($result, $tracker);

        $job->status = JobStatus::Processing;
        $job->progress = $tracker->progress();
        $this->jobs->save($job);

        $file = $tmp . '/media.' . $ext;
        if (!is_file($file)) {
            throw new ApiException($tracker->skipReason() ?? ErrorCode::DownloadFailed, 'expected output file is missing');
        }
        $size = (int) filesize($file);
        if ($size > $this->config->maxFileSizeBytes) {
            throw new ApiException(ErrorCode::FileTooLarge, 'output size ' . $size);
        }

        $probe = $this->probe->probe($file);
        if ($probe === null || !MediaProbe::matches($probe, $ext, $job->expectedDurationSec)) {
            throw new ApiException(ErrorCode::ProcessingFailed, 'ffprobe verification failed: ' . json_encode($probe));
        }

        $this->publish($job, $file, $ext, $size);

        return false;
    }

    private function assertDownloaded(ProcessResult $result, ProgressTracker $tracker): void
    {
        $error = match ($result->reason) {
            TerminationReason::Timeout => ErrorCode::DownloadTimeout,
            TerminationReason::Watchdog => $result->watchdogDetail === 'file_too_large' ? ErrorCode::FileTooLarge : ErrorCode::StorageFull,
            TerminationReason::OutputLimit => ErrorCode::DownloadFailed,
            default => null,
        };
        if ($error !== null) {
            throw new ApiException($error, 'yt-dlp terminated: ' . $result->reason->value . ' ' . ($result->watchdogDetail ?? ''));
        }
        if (!$result->succeeded()) {
            $failure = $this->ytDlp->failure($result, ErrorCode::DownloadFailed);
            $skipReason = $tracker->skipReason();
            if ($skipReason !== null) {
                throw new ApiException($skipReason, $failure->getMessage());
            }
            throw $failure;
        }
    }

    private function publish(DownloadJob $job, string $file, string $ext, int $size): void
    {
        $dir = $this->paths->downloadDir($job->id);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            throw new RuntimeException('Cannot create download dir.');
        }
        $target = $dir . '/media.' . $ext;
        if (!@rename($file, $target)) {
            throw new RuntimeException('Cannot move finished file.');
        }
        @chmod($target, 0640);

        $now = $this->clock->now();
        $job->status = JobStatus::Completed;
        $job->error = null;
        $job->fileName = FilenameSanitizer::displayName($job->title, $ext);
        $job->fileExt = $ext;
        $job->fileSizeBytes = $size;
        $job->finishedAt = $now;
        $job->expiresAt = $now + $this->config->fileRetentionSec;
        $job->progress = new Progress(100.0, $size, $size, null, null);
    }

    private function finish(DownloadJob $job, JobStatus $status, ErrorCode $error): void
    {
        $job->status = $status;
        $job->error = $error;
        $job->finishedAt = $this->clock->now();
        if ($job->progress !== null) {
            $job->progress = new Progress($job->progress->percent, $job->progress->downloadedBytes, $job->progress->totalBytes, null, null);
        }
    }
}
