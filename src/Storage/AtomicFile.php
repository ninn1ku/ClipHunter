<?php

declare(strict_types=1);

namespace ClipHunter\Storage;

use RuntimeException;

/**
 * Crash-safe file writes: write to a temp file in the same directory, then rename over the target.
 * Readers see either the old or the new content, never a partial file.
 */
final class AtomicFile
{
    public static function write(string $path, string $contents, int $mode = 0640): void
    {
        $dir = dirname($path);
        $tmp = $dir . '/.' . basename($path) . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($tmp, $contents, LOCK_EX) !== strlen($contents)) {
            @unlink($tmp);
            throw new RuntimeException('Cannot write temp file in ' . $dir);
        }
        @chmod($tmp, $mode);

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Cannot move temp file to ' . $path);
        }
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function writeJson(string $path, array $data): void
    {
        self::write($path, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<array-key, mixed>|null null when the file is missing or not a JSON object/array
     */
    public static function readJson(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true, 64);

        return is_array($data) ? $data : null;
    }
}
