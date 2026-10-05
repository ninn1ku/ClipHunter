<?php

declare(strict_types=1);

namespace ClipHunter\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Temporary directories for tests, removed at process shutdown.
 */
final class TempDir
{
    /** @var list<string> */
    private static array $created = [];

    public static function create(string $prefix): string
    {
        $path = str_replace('\\', '/', sys_get_temp_dir()) . '/' . $prefix . bin2hex(random_bytes(6));
        if (!mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Cannot create temp dir ' . $path);
        }

        if (self::$created === []) {
            register_shutdown_function(static function (): void {
                foreach (self::$created as $dir) {
                    self::remove($dir);
                }
            });
        }
        self::$created[] = $path;

        return $path;
    }

    public static function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
