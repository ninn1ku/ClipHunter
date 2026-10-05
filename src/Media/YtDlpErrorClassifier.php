<?php

declare(strict_types=1);

namespace ClipHunter\Media;

use ClipHunter\Exception\ErrorCode;

/**
 * Maps yt-dlp's stderr to an error code.
 *
 * stderr is human-readable and changes between versions, so it is only used to pick the most
 * helpful error message — never to extract data. Unknown output falls back to a generic code.
 */
final class YtDlpErrorClassifier
{
    /** Ordered: first match wins (e.g. the bot check before the generic "sign in"). */
    private const RULES = [
        [ErrorCode::SourceTemporarilyBlocked, '~not a bot|HTTP Error 429|Too Many Requests|rate.?limit|temporarily (blocked|unavailable)~i'],
        [ErrorCode::FileTooLarge, '~larger than max-filesize|File is larger than~i'],
        [ErrorCode::VideoPrivate, '~private video|this video is private|is private\b~i'],
        [ErrorCode::LiveNotSupported, '~is_live|live event will begin|premieres in|this live (event|stream)|is a live stream|currently live~i'],
        [ErrorCode::GeoRestricted, '~not (made this video )?available in your (country|location)|geo.?restrict|from your location|blocked it in your country~i'],
        [ErrorCode::LoginRequired, '~sign in to confirm your age|age.?restricted|login required|requires? (authentication|login)|log ?in to|--cookies|members.?only|only available (to|for) (registered|logged)|this content isn.t available~i'],
        [ErrorCode::VideoUnavailable, '~video (is )?(currently )?unavailable|has been removed|does not exist|no longer available|account .{0,40}terminated|HTTP Error 404|not found|DRM|unable to download webpage: HTTP Error 410~i'],
        [ErrorCode::UnsupportedSource, '~unsupported url|no suitable extractor~i'],
        [ErrorCode::NoFormats, '~requested format is not available|no video formats found~i'],
    ];

    public static function classify(string $stderr, ErrorCode $fallback): ErrorCode
    {
        $errors = self::errorLines($stderr);
        $haystack = $errors === '' ? $stderr : $errors;

        foreach (self::RULES as [$code, $pattern]) {
            if (preg_match($pattern, $haystack) === 1) {
                return $code;
            }
        }

        return $fallback;
    }

    /**
     * Prefer "ERROR:" lines so that WARNING noise does not drive classification.
     */
    private static function errorLines(string $stderr): string
    {
        $errors = [];
        foreach (preg_split('~\R~', $stderr) ?: [] as $line) {
            if (str_starts_with($line, 'ERROR:')) {
                $errors[] = $line;
            }
        }

        return implode("\n", $errors);
    }
}
