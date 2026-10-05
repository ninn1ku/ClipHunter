<?php

declare(strict_types=1);

namespace ClipHunter\Security;

use ClipHunter\Media\MetadataSanitizer;
use Transliterator;

/**
 * Builds the download file name shown to the user (Content-Disposition only — never a path
 * on our filesystem). Removes path separators, reserved and control characters.
 */
final class FilenameSanitizer
{
    public const MAX_LENGTH = 120;
    private const FALLBACK = 'cliphunter-video';

    public static function displayName(string $title, string $ext): string
    {
        $name = MetadataSanitizer::text($title, 1000) ?? '';
        $name = (string) preg_replace('~[\\\\/:*?"<>|\x00-\x1F\x7F]+~u', ' ', $name);
        $name = trim((string) preg_replace('~\s+~u', ' ', $name));
        $name = ltrim($name, '. ');
        $name = rtrim($name, '. ');
        if (mb_strlen($name) > self::MAX_LENGTH) {
            $name = rtrim(mb_substr($name, 0, self::MAX_LENGTH), '. ');
        }
        if ($name === '' || preg_match('~^(con|prn|aux|nul|com\d|lpt\d)$~i', $name) === 1) {
            $name = self::FALLBACK;
        }

        return $name . '.' . $ext;
    }

    /**
     * ASCII-only variant for the legacy filename= parameter.
     */
    public static function asciiFallback(string $displayName): string
    {
        $transliterator = Transliterator::create('Any-Latin; Latin-ASCII');
        $ascii = $transliterator?->transliterate($displayName);
        $ascii = is_string($ascii) ? $ascii : $displayName;
        $ascii = (string) preg_replace('~[^A-Za-z0-9 ._()\-]+~', '_', $ascii);
        $ascii = trim((string) preg_replace('~_+~', '_', $ascii), ' _');

        return $ascii === '' || str_starts_with($ascii, '.') ? 'cliphunter-video' . strrchr($displayName, '.') : $ascii;
    }

    public static function contentDisposition(string $displayName): string
    {
        return sprintf(
            'attachment; filename="%s"; filename*=UTF-8\'\'%s',
            self::asciiFallback($displayName),
            rawurlencode($displayName),
        );
    }
}
