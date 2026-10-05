<?php

declare(strict_types=1);

namespace ClipHunter\Storage;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Recursive helpers confined to the storage root: removeTree() refuses any path that does not
 * resolve inside it, so a bug elsewhere can never turn into deleting arbitrary files.
 */
final readonly class Filesystem
{
    private string $root;

    public function __construct(string $storageRoot)
    {
        $real = realpath($storageRoot);
        if ($real === false) {
            throw new RuntimeException('Storage root does not exist.');
        }
        $this->root = self::normalize($real);
    }

    public function removeTree(string $path): void
    {
        $real = realpath($path);
        if ($real === false) {
            return;
        }
        $real = self::normalize($real);
        if (!str_starts_with($real, $this->root . '/')) {
            throw new RuntimeException('Refusing to delete outside storage.');
        }

        if (is_file($real) || is_link($real)) {
            @unlink($real);

            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($real);
    }

    /**
     * Total size of regular files under $path (0 if missing). Symlinks are not followed.
     */
    public static function size(string $path): int
    {
        if (is_file($path)) {
            return (int) @filesize($path);
        }
        if (!is_dir($path)) {
            return 0;
        }

        $total = 0;
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            if ($item->isFile() && !$item->isLink()) {
                $total += $item->getSize();
            }
        }

        return $total;
    }

    private static function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
