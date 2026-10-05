<?php

declare(strict_types=1);

namespace ClipHunter\Storage;

use ClipHunter\Config\AppConfig;
use ClipHunter\Exception\ErrorCode;
use ClipHunter\Job\JobRepository;
use ClipHunter\Job\JobStatus;
use ClipHunter\Support\Clock;
use ClipHunter\Support\Ids;
use Psr\Log\LoggerInterface;

/**
 * Retention policy (run every few minutes by a systemd timer):
 *  - finished files are deleted FILE_RETENTION_MIN after completion (job becomes "expired");
 *  - job records are deleted JOB_TTL_HOURS after creation;
 *  - jobs whose worker died are failed and their temp files removed;
 *  - orphaned tmp/download directories, expired analyses and rate-limit windows are removed.
 */
final readonly class Cleaner
{
    /** Grace period before an unowned directory or running marker is considered abandoned. */
    private const ORPHAN_AGE_SEC = 600;
    private const STALE_RUNNING_AGE_SEC = 60;

    public function __construct(
        private JobRepository $jobs,
        private StoragePaths $paths,
        private Filesystem $fs,
        private Clock $clock,
        private AppConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, int> what was cleaned
     */
    public function run(): array
    {
        $now = $this->clock->now();
        $stats = ['stale_jobs' => 0, 'expired_files' => 0, 'deleted_jobs' => 0, 'orphan_dirs' => 0, 'analyses' => 0, 'rate_limits' => 0];

        foreach ($this->jobs->staleRunningIds(self::STALE_RUNNING_AGE_SEC) as $id) {
            $job = $this->jobs->find($id);
            if ($job !== null && $job->status->isActive()) {
                $job->status = JobStatus::Failed;
                $job->error = ErrorCode::DownloadFailed;
                $job->finishedAt = $now;
                $this->jobs->save($job);
            }
            $this->fs->removeTree($this->paths->tmpDir($id));
            @unlink($this->paths->jobsDir('running') . '/' . $id);
            $stats['stale_jobs']++;
        }

        $known = [];
        foreach ($this->jobs->allIds() as $id) {
            $job = $this->jobs->find($id);
            if ($job === null) {
                if ($this->olderThan($this->paths->jobFile($id), $this->config->jobTtlSec)) {
                    $this->jobs->delete($id);
                    $stats['deleted_jobs']++;
                }
                continue;
            }

            if ($job->createdAt + $this->config->jobTtlSec <= $now && $job->status->isFinal()) {
                $this->fs->removeTree($this->paths->downloadDir($id));
                $this->fs->removeTree($this->paths->tmpDir($id));
                $this->jobs->delete($id);
                $stats['deleted_jobs']++;
                continue;
            }

            if ($job->status === JobStatus::Completed && $job->expiresAt !== null && $job->expiresAt <= $now) {
                $this->fs->removeTree($this->paths->downloadDir($id));
                $job->status = JobStatus::Expired;
                $this->jobs->save($job);
                $stats['expired_files']++;
            }
            $known[$id] = $job->status;
        }

        $running = array_flip($this->jobs->runningIds());
        foreach (['tmp', 'downloads'] as $area) {
            foreach ($this->entries($this->paths->dir($area)) as $id) {
                $isOwned = $area === 'tmp' ? isset($running[$id]) : ($known[$id] ?? null) === JobStatus::Completed;
                if (!$isOwned && $this->olderThan($this->paths->dir($area) . '/' . $id, self::ORPHAN_AGE_SEC)) {
                    $this->fs->removeTree($this->paths->dir($area) . '/' . $id);
                    $stats['orphan_dirs']++;
                }
            }
        }

        $stats['analyses'] = $this->sweepJsonFiles($this->paths->dir('analyses'), 'expiresAt', $now, $this->config->analysisTtlSec);
        $stats['rate_limits'] = $this->sweepJsonFiles($this->paths->dir('ratelimit'), 'expires', $now, 86_400);

        if (array_sum($stats) > 0) {
            $this->logger->info('cleanup.run', $stats);
        }

        return $stats;
    }

    /**
     * Deletes JSON files whose expiry field has passed (or that are unreadable and old).
     */
    private function sweepJsonFiles(string $dir, string $expiryField, int $now, int $fallbackAgeSec): int
    {
        $deleted = 0;
        foreach (@scandir($dir) ?: [] as $name) {
            if (!str_ends_with($name, '.json')) {
                continue;
            }
            $path = $dir . '/' . $name;
            $data = AtomicFile::readJson($path);
            $expires = is_array($data) && is_int($data[$expiryField] ?? null) ? $data[$expiryField] : null;
            $expired = $expires !== null ? $expires <= $now : $this->olderThan($path, $fallbackAgeSec);
            if ($expired && @unlink($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * @return list<string> id-named entries of a directory
     */
    private function entries(string $dir): array
    {
        return array_values(array_filter(@scandir($dir) ?: [], static fn (string $n): bool => Ids::isValid($n)));
    }

    private function olderThan(string $path, int $ageSec): bool
    {
        $mtime = @filemtime($path);

        return $mtime !== false && $this->clock->now() - $mtime >= $ageSec;
    }
}
