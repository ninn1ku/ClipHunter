<?php

declare(strict_types=1);

namespace ClipHunter\AniLiberty;

/**
 * What an AniLiberty link points to, parsed from an already validated URL:
 *   /anime/releases/release/{alias}[/episodes]  → a release (the room starts with its first episode)
 *   /anime/video/episode/{uuid}                  → one episode
 */
final readonly class AniLibertyLink
{
    private function __construct(
        public ?string $releaseAlias,
        public ?string $episodeId,
    ) {
    }

    /**
     * @param string $url canonical https URL from {@see \ClipHunter\Security\UrlValidator}
     */
    public static function fromUrl(string $url): ?self
    {
        $parts = parse_url($url);
        $path = is_array($parts) ? ($parts['path'] ?? '/') : '/';
        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));

        if (
            count($segments) >= 4 && count($segments) <= 5 && array_slice($segments, 0, 3) === ['anime', 'releases', 'release']
            && preg_match(AniLibertyClient::ALIAS, $segments[3]) === 1 && ($segments[4] ?? 'episodes') === 'episodes'
        ) {
            return new self($segments[3], null);
        }
        if (count($segments) === 4 && array_slice($segments, 0, 3) === ['anime', 'video', 'episode'] && preg_match(AniLibertyClient::EPISODE_ID, $segments[3]) === 1) {
            return new self(null, $segments[3]);
        }

        return null;
    }
}
