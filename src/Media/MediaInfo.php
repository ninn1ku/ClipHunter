<?php

declare(strict_types=1);

namespace ClipHunter\Media;

/**
 * Sanitised video metadata. Every string here is safe to display but must still be escaped
 * by the renderer (the frontend uses textContent) and is never used as a filesystem path.
 */
final readonly class MediaInfo
{
    /**
     * @param list<FormatInfo> $formats
     */
    public function __construct(
        public string $title,
        public ?string $uploader,
        public ?int $durationSec,
        public ?string $thumbnailUrl,
        public string $extractor,
        public array $formats,
    ) {
    }
}
