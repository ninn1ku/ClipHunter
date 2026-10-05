<?php

declare(strict_types=1);

namespace ClipHunter\RateLimit;

/**
 * Counting semaphore built from N lock files. A slot is held while its flock() is held, so
 * a crashed process frees its slot automatically when the OS closes the file.
 */
final readonly class Semaphore
{
    public function __construct(private string $dir)
    {
    }

    public function tryAcquire(string $name, int $slots): ?SemaphoreSlot
    {
        for ($i = 0; $i < $slots; $i++) {
            $handle = @fopen($this->dir . '/' . $name . '-' . $i . '.lock', 'c');
            if ($handle === false) {
                continue;
            }
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return new SemaphoreSlot($handle);
            }
            fclose($handle);
        }

        return null;
    }
}
