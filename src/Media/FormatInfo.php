<?php

declare(strict_types=1);

namespace ClipHunter\Media;

/**
 * One media format reported by yt-dlp, reduced to sanitised fields.
 */
final readonly class FormatInfo
{
    public function __construct(
        public ?string $ext,
        public ?string $vcodec,
        public ?string $acodec,
        public ?int $width,
        public ?int $height,
        public ?int $sizeBytes,
        public bool $sizeIsApprox,
    ) {
    }

    public function hasVideo(): bool
    {
        if ($this->vcodec !== null) {
            return $this->vcodec !== 'none';
        }

        return $this->height !== null;
    }

    public function hasAudio(): bool
    {
        return $this->acodec !== null && $this->acodec !== 'none';
    }

    public function isAudioOnly(): bool
    {
        return $this->hasAudio() && !$this->hasVideo();
    }

    /**
     * Resolution as yt-dlp's "res": the smaller dimension, so vertical 1080x1920 counts as 1080p.
     */
    public function resolution(): ?int
    {
        if ($this->width !== null && $this->height !== null) {
            return min($this->width, $this->height);
        }

        return $this->height;
    }

    public function isH264(): bool
    {
        return $this->vcodec !== null && (str_starts_with($this->vcodec, 'avc1') || str_starts_with($this->vcodec, 'h264'));
    }
}
