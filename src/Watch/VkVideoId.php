<?php

declare(strict_types=1);

namespace ClipHunter\Watch;

/**
 * A VK Video id (owner_id + video_id) parsed from an already validated URL, without asking yt-dlp.
 *
 * Accepted forms on vk.com, vk.ru, vkvideo.ru and their subdomains (m., www.):
 *   /video-22822305_456241864, /video123_456 (also inside /playlist/…/video…), /clip-1_2,
 *   ?z=video-1_2/… (video opened over another page) and the embed /video_ext.php?oid=…&id=….
 * Start time comes from t= (90, 90s, 1h2m3s).
 *
 * Links of private videos also carry an access key; it is not kept (tickets carry ids only), so
 * such videos fail in the embed and the host gets the server fallback.
 */
final readonly class VkVideoId
{
    private const ID = '(-?[1-9][0-9]{0,18})_([1-9][0-9]{0,18})';

    private function __construct(
        public string $ownerId,
        public string $videoId,
        public int $startSec,
    ) {
    }

    /** The ticket ref: owner_video. */
    public function ref(): string
    {
        return $this->ownerId . '_' . $this->videoId;
    }

    /**
     * @param string $url canonical https URL from {@see \ClipHunter\Security\UrlValidator}
     *
     * @return ?self null when the link points to something else (a wall post, a profile): such
     *               links go through yt-dlp instead
     */
    public static function fromUrl(string $url): ?self
    {
        $parts = parse_url($url);
        $path = is_array($parts) ? ($parts['path'] ?? '/') : '/';
        parse_str(is_array($parts) ? ($parts['query'] ?? '') : '', $query);
        $start = StartOffset::parse($query['t'] ?? null);

        if ($path === '/video_ext.php') {
            $owner = is_string($query['oid'] ?? null) ? $query['oid'] : '';
            $id = is_string($query['id'] ?? null) ? $query['id'] : '';

            return preg_match('~^' . self::ID . '$~D', $owner . '_' . $id, $m) === 1 ? new self($m[1], $m[2], $start) : null;
        }

        $z = is_string($query['z'] ?? null) ? $query['z'] : '';
        if (preg_match('~^(?:video|clip)' . self::ID . '(?:$|/)~D', $z, $m) === 1) {
            return new self($m[1], $m[2], $start);
        }

        if (preg_match('~/(?:video|clip)' . self::ID . '/?$~D', $path, $m) === 1) {
            return new self($m[1], $m[2], $start);
        }

        return null;
    }
}
