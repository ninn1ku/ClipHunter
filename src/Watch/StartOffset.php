<?php

declare(strict_types=1);

namespace ClipHunter\Watch;

/**
 * A start offset from a share link: t=90, t=90s, t=1h2m3s (YouTube, VK, Twitch) or start=90.
 */
final class StartOffset
{
    /** Longer start offsets are not meaningful for a single video and are ignored. */
    public const MAX_SEC = 86_400;

    /**
     * @return int seconds, 0 when the value is missing, malformed or out of range
     */
    public static function parse(mixed $raw): int
    {
        if (!is_string($raw)) {
            return 0;
        }
        if (preg_match('~^\d{1,6}s?$~D', $raw) === 1) {
            $sec = (int) $raw;
        } elseif ($raw !== '' && preg_match('~^(?:(\d{1,2})h)?(?:(\d{1,4})m)?(?:(\d{1,6})s)?$~D', $raw, $m) === 1) {
            $sec = (int) ($m[1] ?? 0) * 3600 + (int) ($m[2] ?? 0) * 60 + (int) ($m[3] ?? 0);
        } else {
            return 0;
        }

        return $sec <= self::MAX_SEC ? $sec : 0;
    }
}
