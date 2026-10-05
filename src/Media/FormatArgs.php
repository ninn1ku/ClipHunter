<?php

declare(strict_types=1);

namespace ClipHunter\Media;

use InvalidArgumentException;

/**
 * Maps an option id to a fixed set of yt-dlp arguments. Nothing here comes from the client
 * except the id itself, which must match {@see DownloadOption::ID_PATTERN}.
 */
final class FormatArgs
{
    /**
     * @return list<string>
     */
    public static function for(string $optionId): array
    {
        if (preg_match(DownloadOption::ID_PATTERN, $optionId) !== 1) {
            throw new InvalidArgumentException('Unknown option id.');
        }

        if ($optionId === 'a-m4a') {
            return ['-f', 'ba[ext=m4a]/ba/b', '-x', '--audio-format', 'm4a'];
        }
        if ($optionId === 'a-mp3') {
            return ['-f', 'ba/b', '-x', '--audio-format', 'mp3', '--audio-quality', '192K'];
        }

        $tier = (int) substr($optionId, 1);

        // "res" is the smaller dimension, so vertical videos map to the expected tier.
        // Up to 1080p H.264/AAC is preferred for compatibility; above that platforms only offer VP9/AV1.
        return [
            '-f', 'bv*+ba/b',
            '-S', sprintf('res:%d,vcodec:h264,acodec:m4a', $tier),
            '--merge-output-format', 'mp4',
            '--remux-video', 'mp4',
        ];
    }

    public static function extension(string $optionId): string
    {
        return match ($optionId) {
            'a-m4a' => 'm4a',
            'a-mp3' => 'mp3',
            default => 'mp4',
        };
    }

    public static function mimeType(string $optionId): string
    {
        return match (self::extension($optionId)) {
            'm4a' => 'audio/mp4',
            'mp3' => 'audio/mpeg',
            default => 'video/mp4',
        };
    }
}
