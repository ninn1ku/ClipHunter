<?php

declare(strict_types=1);

namespace ClipHunter\Storage;

use ClipHunter\Exception\ApiException;
use ClipHunter\Exception\ErrorCode;

/**
 * Disk protection: a storage quota for tmp + downloads and a free-space reserve for the OS.
 */
final readonly class StorageGuard
{
    public function __construct(
        private StoragePaths $paths,
        private int $quotaBytes,
        private int $minFreeBytes,
    ) {
    }

    public function usedBytes(): int
    {
        return Filesystem::size($this->paths->dir('tmp')) + Filesystem::size($this->paths->dir('downloads'));
    }

    public function freeBytes(): ?int
    {
        $free = @disk_free_space($this->paths->root);

        return $free === false ? null : (int) $free;
    }

    /**
     * @throws ApiException STORAGE_FULL
     */
    public function assertCapacityFor(int $expectedBytes): void
    {
        $free = $this->freeBytes();
        if ($free !== null && $free - $expectedBytes < $this->minFreeBytes) {
            throw new ApiException(ErrorCode::StorageFull, sprintf('free=%d expected=%d', $free, $expectedBytes), headers: ['Retry-After' => '300']);
        }
        if ($this->usedBytes() + $expectedBytes > $this->quotaBytes) {
            throw new ApiException(ErrorCode::StorageFull, 'quota exceeded', headers: ['Retry-After' => '300']);
        }
    }

    /**
     * Watchdog check for a running download. Returns a reason to abort, or null.
     */
    public function violation(string $tmpDir, int $maxTmpBytes): ?string
    {
        if (Filesystem::size($tmpDir) > $maxTmpBytes) {
            return 'file_too_large';
        }
        $free = $this->freeBytes();
        if ($free !== null && $free < intdiv($this->minFreeBytes, 2)) {
            return 'disk_low';
        }

        return null;
    }
}
