<?php

declare(strict_types=1);

namespace ClipHunter\Watch;

/**
 * What a Twitch link points to, parsed from an already validated URL without asking yt-dlp:
 * a recording (twitch.tv/videos/123, ?t=1h2m3s) or a channel's live stream (twitch.tv/<login>).
 *
 * Clips are not returned: the clip embed has no JavaScript API (no position, no play/pause
 * control), so clips go through yt-dlp and are prepared as files instead.
 */
final readonly class TwitchRef
{
    /** First path segments of twitch.tv that are pages, not channels. */
    private const RESERVED = [
        'directory', 'videos', 'settings', 'subscriptions', 'inventory', 'wallet', 'drops', 'downloads',
        'jobs', 'p', 'search', 'turbo', 'prime', 'store', 'friends', 'messages', 'login', 'signup',
        'payments', 'privacy', 'terms', 'team', 'moderator', 'popout', 'embed', 'collections',
        'broadcast', 'following', 'clips', 'bits', 'redeem', 'products',
    ];

    private function __construct(
        public string $ref,
        public int $startSec,
    ) {
    }

    public function isLive(): bool
    {
        return str_starts_with($this->ref, 'channel:');
    }

    /**
     * @param string $url canonical https URL from {@see \ClipHunter\Security\UrlValidator}
     *
     * @return ?self null for clips and other pages: those go through yt-dlp
     */
    public static function fromUrl(string $url): ?self
    {
        $parts = parse_url($url);
        $host = strtolower(is_array($parts) ? ($parts['host'] ?? '') : '');
        $path = is_array($parts) ? ($parts['path'] ?? '/') : '/';
        parse_str(is_array($parts) ? ($parts['query'] ?? '') : '', $query);

        if (!in_array($host, ['twitch.tv', 'www.twitch.tv', 'm.twitch.tv'], true)) {
            return null; // clips.twitch.tv, player.twitch.tv and others
        }
        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));

        if (count($segments) === 2 && $segments[0] === 'videos' && preg_match('~^[1-9][0-9]{0,14}$~D', $segments[1]) === 1) {
            return new self('video:' . $segments[1], StartOffset::parse($query['t'] ?? null));
        }

        if (count($segments) === 1) {
            $login = strtolower($segments[0]);
            if (preg_match('~^[a-z0-9][a-z0-9_]{2,24}$~D', $login) === 1 && !in_array($login, self::RESERVED, true)) {
                return new self('channel:' . $login, 0);
            }
        }

        return null;
    }
}
