<?php

declare(strict_types=1);

namespace ClipHunter\Watch;

use ClipHunter\Exception\ApiException;
use ClipHunter\Exception\ErrorCode;

/**
 * A YouTube video id (and start time) parsed from an already validated URL, without asking yt-dlp.
 *
 * Accepted forms: watch?v=ID, youtu.be/ID, /shorts/ID, /live/ID, /embed/ID on youtube.com and its
 * subdomains (www, m, music) and youtube-nocookie.com. Start time comes from t= (90, 90s, 1h2m3s)
 * or start=.
 */
final readonly class YouTubeId
{
    public const ID_PATTERN = '~^[A-Za-z0-9_-]{11}$~D';

    private function __construct(
        public string $id,
        public int $startSec,
    ) {
    }

    /**
     * @param string $url canonical https URL from {@see \ClipHunter\Security\UrlValidator}
     *
     * @throws ApiException PLAYLIST_NOT_SUPPORTED for playlist-only links, INVALID_URL otherwise
     */
    public static function fromUrl(string $url): self
    {
        $parts = parse_url($url);
        $host = strtolower(is_array($parts) ? ($parts['host'] ?? '') : '');
        $path = is_array($parts) ? ($parts['path'] ?? '/') : '/';
        parse_str(is_array($parts) ? ($parts['query'] ?? '') : '', $query);

        $candidate = null;
        if ($host === 'youtu.be') {
            $candidate = self::segment($path, 0);
        } elseif (self::isYouTubeHost($host)) {
            if ($path === '/watch') {
                $candidate = is_string($query['v'] ?? null) ? $query['v'] : null;
            } elseif (in_array(self::segment($path, 0), ['shorts', 'live', 'embed'], true)) {
                $candidate = self::segment($path, 1);
            }
        }

        if ($candidate === null || preg_match(self::ID_PATTERN, $candidate) !== 1) {
            if (is_string($query['list'] ?? null)) {
                throw new ApiException(ErrorCode::PlaylistNotSupported, 'youtube playlist without video id');
            }

            throw new ApiException(ErrorCode::InvalidUrl, 'no youtube video id');
        }

        return new self($candidate, StartOffset::parse($query['t'] ?? $query['start'] ?? null));
    }

    public function thumbnailUrl(): string
    {
        return 'https://i.ytimg.com/vi/' . $this->id . '/hqdefault.jpg';
    }

    private static function isYouTubeHost(string $host): bool
    {
        foreach (['youtube.com', 'youtube-nocookie.com'] as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }

    private static function segment(string $path, int $index): ?string
    {
        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));

        return $segments[$index] ?? null;
    }
}
