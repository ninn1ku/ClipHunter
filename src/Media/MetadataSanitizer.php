<?php

declare(strict_types=1);

namespace ClipHunter\Media;

use ClipHunter\Exception\ApiException;
use ClipHunter\Exception\ErrorCode;
use Normalizer;

/**
 * Converts raw yt-dlp JSON (untrusted, platform-controlled) into a {@see MediaInfo}.
 *
 * Only known fields are read, each is type-checked and bounded; unknown fields are ignored.
 */
final readonly class MetadataSanitizer
{
    public const TITLE_MAX = 200;
    public const UPLOADER_MAX = 100;
    private const URL_MAX = 2048;
    private const MAX_FORMATS = 500;
    private const MAX_DIMENSION = 16384;
    private const MAX_SIZE = 10 * 1024 ** 4;

    public function __construct(private int $maxDurationSec)
    {
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws ApiException PLAYLIST_NOT_SUPPORTED, LIVE_NOT_SUPPORTED, VIDEO_TOO_LONG, VIDEO_UNAVAILABLE, NO_FORMATS
     */
    public function sanitize(array $data): MediaInfo
    {
        $type = $data['_type'] ?? 'video';
        if ($type === 'playlist' || $type === 'multi_video' || isset($data['entries'])) {
            throw new ApiException(ErrorCode::PlaylistNotSupported, 'yt-dlp returned ' . (is_string($type) ? $type : 'entries'));
        }

        $liveStatus = $data['live_status'] ?? null;
        if (($data['is_live'] ?? false) === true || in_array($liveStatus, ['is_live', 'is_upcoming', 'post_live'], true)) {
            throw new ApiException(ErrorCode::LiveNotSupported, 'live_status=' . (is_string($liveStatus) ? $liveStatus : 'is_live'));
        }

        $duration = self::positiveNumber($data['duration'] ?? null);
        $durationSec = $duration === null ? null : (int) round($duration);
        if ($durationSec !== null && $durationSec > $this->maxDurationSec) {
            throw new ApiException(ErrorCode::VideoTooLong, 'duration=' . $durationSec);
        }

        $formats = $this->formats($data);
        if ($formats === []) {
            throw new ApiException(ErrorCode::NoFormats, 'no usable formats');
        }

        $extractor = $data['extractor'] ?? null;

        return new MediaInfo(
            title: self::text($data['title'] ?? null, self::TITLE_MAX) ?? 'Без названия',
            uploader: self::text($data['uploader'] ?? $data['channel'] ?? null, self::UPLOADER_MAX),
            durationSec: $durationSec,
            thumbnailUrl: self::httpsUrl($data['thumbnail'] ?? null),
            extractor: is_string($extractor) && preg_match('~^[A-Za-z0-9:._-]{1,64}$~D', $extractor) === 1 ? $extractor : 'unknown',
            formats: $formats,
        );
    }

    /**
     * Normalises display text: valid UTF-8, NFC, no control/format characters (incl. bidi
     * overrides and zero-width chars), collapsed whitespace, bounded length.
     */
    public static function text(mixed $value, int $maxLength): ?string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }
        $text = mb_scrub((string) $value, 'UTF-8');
        $normalized = Normalizer::normalize($text, Normalizer::FORM_C);
        if (is_string($normalized)) {
            $text = $normalized;
        }
        $text = (string) preg_replace('~[\p{Cc}\p{Cf}\p{Co}\p{Cs}\x{FFFD}]+~u', ' ', $text);
        $text = trim((string) preg_replace('~\s+~u', ' ', $text));
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > $maxLength) {
            $text = rtrim(mb_substr($text, 0, $maxLength - 1)) . '…';
        }

        return $text;
    }

    public static function httpsUrl(mixed $value): ?string
    {
        if (!is_string($value) || $value === '' || strlen($value) > self::URL_MAX) {
            return null;
        }
        if (preg_match('~[\x00-\x20\x7F"\'<>\\\\`]~', $value) === 1) {
            return null;
        }
        $parts = parse_url($value);
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<FormatInfo>
     */
    private function formats(array $data): array
    {
        $raw = $data['formats'] ?? null;
        if (!is_array($raw) || $raw === []) {
            // Single-format result: the top-level object describes the only format.
            $raw = isset($data['ext']) ? [$data] : [];
        }

        $formats = [];
        $drmOnly = true;
        foreach (array_slice($raw, 0, self::MAX_FORMATS) as $f) {
            if (!is_array($f)) {
                continue;
            }
            if (($f['has_drm'] ?? false) === true) {
                continue;
            }
            $drmOnly = false;

            $size = self::positiveInt($f['filesize'] ?? null, self::MAX_SIZE);
            $approx = false;
            if ($size === null) {
                $size = self::positiveInt($f['filesize_approx'] ?? null, self::MAX_SIZE);
                $approx = $size !== null;
            }

            $format = new FormatInfo(
                ext: self::token($f['ext'] ?? null, '~^[a-z0-9]{1,8}$~D'),
                vcodec: self::token($f['vcodec'] ?? null, '~^[A-Za-z0-9._-]{1,64}$~D'),
                acodec: self::token($f['acodec'] ?? null, '~^[A-Za-z0-9._-]{1,64}$~D'),
                width: self::positiveInt($f['width'] ?? null, self::MAX_DIMENSION),
                height: self::positiveInt($f['height'] ?? null, self::MAX_DIMENSION),
                sizeBytes: $size,
                sizeIsApprox: $approx,
            );
            if ($format->hasVideo() || $format->hasAudio()) {
                $formats[] = $format;
            }
        }

        if ($raw !== [] && $drmOnly) {
            throw new ApiException(ErrorCode::VideoUnavailable, 'all formats are DRM protected');
        }

        return $formats;
    }

    private static function token(mixed $value, string $pattern): ?string
    {
        return is_string($value) && preg_match($pattern, $value) === 1 ? $value : null;
    }

    private static function positiveInt(mixed $value, int $max): ?int
    {
        $number = self::positiveNumber($value);
        if ($number === null || $number > $max) {
            return null;
        }

        return (int) round($number);
    }

    private static function positiveNumber(mixed $value): int|float|null
    {
        if ((is_int($value) || is_float($value)) && is_finite((float) $value) && $value > 0) {
            return $value;
        }

        return null;
    }
}
