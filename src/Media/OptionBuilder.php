<?php

declare(strict_types=1);

namespace ClipHunter\Media;

/**
 * Builds the user-facing list of download variants from the available formats.
 *
 * Video variants are standard resolution tiers that actually exist for the video; their size is
 * an estimate (best matching video stream + best audio). Variants known to exceed the size limit
 * are not offered at all.
 */
final readonly class OptionBuilder
{
    /** Descending. Must match DownloadOption::ID_PATTERN and FormatArgs. */
    public const TIERS = [2160, 1440, 1080, 720, 480, 360, 240, 144];

    /** Formats a few pixels short of a tier (e.g. 1072p) still count as that tier. */
    private const TIER_TOLERANCE = 1.07;

    private const MP3_BITRATE_BPS = 192_000;

    public function __construct(
        private int $maxHeight,
        private int $maxFileSizeBytes,
    ) {
    }

    /**
     * @return list<DownloadOption>
     */
    public function build(MediaInfo $media): array
    {
        $bestAudio = self::bestAudio($media->formats);
        $options = [];

        foreach ($this->videoTiers($media->formats) as $tier => $video) {
            $size = $video->sizeBytes;
            if ($size !== null && !$video->hasAudio()) {
                $size = $bestAudio?->sizeBytes === null ? null : $size + $bestAudio->sizeBytes;
            }
            if ($size !== null && $size > $this->maxFileSizeBytes) {
                continue;
            }
            // yt-dlp makes the final format pick, so a video size is always an estimate.
            $options[] = new DownloadOption('v' . $tier, OptionKind::Video, self::tierLabel($tier), 'mp4', $tier, $size, $size !== null);
        }

        if (self::hasAnyAudio($media->formats)) {
            $m4a = $bestAudio !== null && $bestAudio->ext === 'm4a' ? $bestAudio : null;
            $options[] = new DownloadOption('a-m4a', OptionKind::Audio, 'Аудио M4A', 'm4a', null, $m4a?->sizeBytes, $m4a !== null && $m4a->sizeIsApprox);

            $mp3Size = $media->durationSec === null ? null : intdiv($media->durationSec * self::MP3_BITRATE_BPS, 8);
            if ($mp3Size === null || $mp3Size <= $this->maxFileSizeBytes) {
                $options[] = new DownloadOption('a-mp3', OptionKind::Audio, 'Аудио MP3', 'mp3', null, $mp3Size, $mp3Size !== null);
            }
        }

        return $options;
    }

    public static function tierFor(int $resolution): ?int
    {
        foreach (self::TIERS as $tier) {
            if ($tier <= $resolution * self::TIER_TOLERANCE) {
                return $tier;
            }
        }

        return null;
    }

    /**
     * For each tier, the representative video format: prefer H.264 (what the download step
     * prefers too), then the largest known size.
     *
     * @param list<FormatInfo> $formats
     *
     * @return array<int, FormatInfo> tier => format, descending
     */
    private function videoTiers(array $formats): array
    {
        $byTier = [];
        foreach ($formats as $format) {
            $res = $format->resolution();
            if (!$format->hasVideo() || $res === null) {
                continue;
            }
            $tier = self::tierFor($res);
            if ($tier === null || $tier > $this->maxHeight) {
                continue;
            }
            $current = $byTier[$tier] ?? null;
            if ($current === null || self::better($format, $current)) {
                $byTier[$tier] = $format;
            }
        }
        krsort($byTier);

        return $byTier;
    }

    private static function better(FormatInfo $candidate, FormatInfo $current): bool
    {
        if ($candidate->isH264() !== $current->isH264()) {
            return $candidate->isH264();
        }

        return ($candidate->sizeBytes ?? 0) > ($current->sizeBytes ?? 0);
    }

    /**
     * @param list<FormatInfo> $formats
     */
    private static function bestAudio(array $formats): ?FormatInfo
    {
        $best = null;
        foreach ($formats as $format) {
            if (!$format->isAudioOnly() || $format->sizeBytes === null) {
                continue;
            }
            if ($best === null
                || ($format->ext === 'm4a' && $best->ext !== 'm4a')
                || ($format->ext === $best->ext && $format->sizeBytes > ($best->sizeBytes ?? 0))) {
                $best = $format;
            }
        }

        return $best;
    }

    /**
     * @param list<FormatInfo> $formats
     */
    private static function hasAnyAudio(array $formats): bool
    {
        foreach ($formats as $format) {
            if ($format->hasAudio() || ($format->vcodec === 'none' && $format->acodec === null)) {
                return true;
            }
        }

        return false;
    }

    private static function tierLabel(int $tier): string
    {
        return match ($tier) {
            2160 => '2160p (4K)',
            1440 => '1440p (2K)',
            default => $tier . 'p',
        };
    }
}
