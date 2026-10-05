<?php

declare(strict_types=1);

namespace ClipHunter\Support;

/**
 * Server-generated identifiers (128-bit, hex). They double as capability tokens and as
 * filesystem path segments, so every id coming from a request is checked with isValid()
 * before it gets anywhere near the filesystem.
 */
final class Ids
{
    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function isValid(string $id): bool
    {
        return preg_match('~^[a-f0-9]{32}$~D', $id) === 1;
    }
}
