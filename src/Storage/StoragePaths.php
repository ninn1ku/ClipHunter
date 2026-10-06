<?php

declare(strict_types=1);

namespace ClipHunter\Storage;

use ClipHunter\Support\Ids;
use InvalidArgumentException;
use RuntimeException;

/**
 * The only place that builds filesystem paths inside storage/.
 *
 * Every dynamic segment is a server-generated id validated by {@see Ids::isValid()};
 * nothing derived from external metadata ever becomes part of a path.
 */
final readonly class StoragePaths
{
    public const DIRS = ['analyses', 'jobs', 'jobs/queue', 'jobs/running', 'tmp', 'downloads', 'ratelimit', 'locks', 'cache', 'cache/yt-dlp', 'logs'];

    public function __construct(public string $root)
    {
    }

    public function ensureDirectories(): void
    {
        foreach (self::DIRS as $dir) {
            $path = $this->root . '/' . $dir;
            if (!is_dir($path) && !@mkdir($path, 0750, true) && !is_dir($path)) {
                throw new RuntimeException('Cannot create storage directory ' . $dir);
            }
        }
    }

    /** Written by the rooms service: media ids of prepared files in non-empty rooms. */
    public function roomsMediaInUseFile(): string
    {
        return $this->root . '/rooms/media-in-use.json';
    }

    public function analysisFile(string $id): string
    {
        return $this->root . '/analyses/' . self::id($id) . '.json';
    }

    public function jobFile(string $id): string
    {
        return $this->root . '/jobs/' . self::id($id) . '.json';
    }

    public function jobsDir(string $sub = ''): string
    {
        return $this->root . '/jobs' . ($sub === '' ? '' : '/' . $sub);
    }

    public function tmpDir(string $jobId): string
    {
        return $this->root . '/tmp/' . self::id($jobId);
    }

    public function downloadDir(string $jobId): string
    {
        return $this->root . '/downloads/' . self::id($jobId);
    }

    public function dir(string $name): string
    {
        if (!in_array($name, self::DIRS, true)) {
            throw new InvalidArgumentException('Unknown storage directory.');
        }

        return $this->root . '/' . $name;
    }

    private static function id(string $id): string
    {
        if (!Ids::isValid($id)) {
            throw new InvalidArgumentException('Invalid id.');
        }

        return $id;
    }
}
