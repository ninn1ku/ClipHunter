<?php

declare(strict_types=1);

namespace ClipHunter\Job;

use ClipHunter\Storage\AtomicFile;
use ClipHunter\Storage\StoragePaths;
use ClipHunter\Support\Ids;
use InvalidArgumentException;
use RuntimeException;

/**
 * Directory-based queue (Maildir style):
 *   jobs/<id>.json            job state, written atomically
 *   jobs/queue/<ts>-<id>      queued marker; claimed by an atomic rename into running/
 *   jobs/running/<id>         running marker, flock()-ed by the worker while it works
 *   jobs/<id>.cancel          cancellation request (a separate file, so the worker's
 *                             progress writes can never overwrite it)
 */
final readonly class FilesystemJobRepository implements JobRepository
{
    public function __construct(private StoragePaths $paths)
    {
    }

    public function create(DownloadJob $job): void
    {
        $this->save($job);
        $marker = $this->queueMarker($job);
        if (@touch($marker) === false) {
            throw new RuntimeException('Cannot enqueue job.');
        }
    }

    public function save(DownloadJob $job): void
    {
        AtomicFile::writeJson($this->paths->jobFile($job->id), $job->toArray());
    }

    public function find(string $id): ?DownloadJob
    {
        if (!Ids::isValid($id)) {
            return null;
        }
        $data = AtomicFile::readJson($this->paths->jobFile($id));
        if ($data === null) {
            return null;
        }

        try {
            $job = DownloadJob::fromArray($data);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $job->id === $id ? $job : null;
    }

    public function claimNext(): ?ClaimedJob
    {
        foreach ($this->queueEntries() as $name => $id) {
            $running = $this->paths->jobsDir('running') . '/' . $id;
            if (!@rename($this->paths->jobsDir('queue') . '/' . $name, $running)) {
                continue; // another worker won the race
            }
            @touch($running);

            $lock = @fopen($running, 'c');
            if ($lock === false) {
                continue; // left for stale-job recovery
            }
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                fclose($lock);
                continue;
            }

            $job = $this->find($id);
            if ($job === null) {
                flock($lock, LOCK_UN);
                fclose($lock);
                @unlink($running);
                continue;
            }

            return new ClaimedJob($job, $running, $lock);
        }

        return null;
    }

    public function release(ClaimedJob $claimed): void
    {
        $claimed->unlock();
        @unlink($claimed->markerPath);
    }

    public function requeue(ClaimedJob $claimed): void
    {
        $claimed->unlock();
        @rename($claimed->markerPath, $this->queueMarker($claimed->job));
    }

    public function dequeue(string $id): bool
    {
        foreach ($this->queueEntries() as $name => $queuedId) {
            // unlink() and the worker's rename() are both atomic: exactly one of them wins.
            if ($queuedId === $id && @unlink($this->paths->jobsDir('queue') . '/' . $name)) {
                return true;
            }
        }

        return false;
    }

    public function queuedIds(): array
    {
        return array_values($this->queueEntries());
    }

    public function runningIds(): array
    {
        return $this->idsIn($this->paths->jobsDir('running'), '~^[a-f0-9]{32}$~D');
    }

    public function staleRunningIds(int $minAgeSec): array
    {
        $stale = [];
        foreach ($this->runningIds() as $id) {
            $path = $this->paths->jobsDir('running') . '/' . $id;
            $mtime = @filemtime($path);
            if ($mtime === false || time() - $mtime < $minAgeSec) {
                continue;
            }
            $handle = @fopen($path, 'c');
            if ($handle === false) {
                continue;
            }
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                $stale[] = $id;
                flock($handle, LOCK_UN);
            }
            fclose($handle);
        }

        return $stale;
    }

    public function requestCancel(string $id): void
    {
        if (Ids::isValid($id)) {
            @touch($this->paths->jobsDir() . '/' . $id . '.cancel');
        }
    }

    public function isCancelRequested(string $id): bool
    {
        return Ids::isValid($id) && is_file($this->paths->jobsDir() . '/' . $id . '.cancel');
    }

    public function allIds(): array
    {
        $ids = [];
        foreach ($this->idsIn($this->paths->jobsDir(), '~^[a-f0-9]{32}\.json$~D') as $file) {
            $ids[] = substr($file, 0, 32);
        }

        return $ids;
    }

    public function delete(string $id): void
    {
        if (!Ids::isValid($id)) {
            return;
        }
        @unlink($this->paths->jobFile($id));
        @unlink($this->paths->jobsDir() . '/' . $id . '.cancel');
        @unlink($this->paths->jobsDir('running') . '/' . $id);
        foreach ($this->queueEntries() as $name => $queuedId) {
            if ($queuedId === $id) {
                @unlink($this->paths->jobsDir('queue') . '/' . $name);
            }
        }
    }

    private function queueMarker(DownloadJob $job): string
    {
        return $this->paths->jobsDir('queue') . '/' . sprintf('%012d', $job->createdAt) . '-' . $job->id;
    }

    /**
     * @return array<string, string> marker name => job id, oldest first
     */
    private function queueEntries(): array
    {
        $entries = [];
        foreach ($this->idsIn($this->paths->jobsDir('queue'), '~^\d{12}-[a-f0-9]{32}$~D') as $name) {
            $entries[$name] = substr($name, 13);
        }
        ksort($entries, SORT_STRING);

        return $entries;
    }

    /**
     * @return list<string> directory entries matching the pattern
     */
    private function idsIn(string $dir, string $pattern): array
    {
        $names = @scandir($dir);
        if ($names === false) {
            return [];
        }

        return array_values(array_filter($names, static fn (string $n): bool => preg_match($pattern, $n) === 1));
    }
}
