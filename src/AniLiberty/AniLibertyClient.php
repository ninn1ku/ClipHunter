<?php

declare(strict_types=1);

namespace ClipHunter\AniLiberty;

use ClipHunter\Exception\ApiException;
use ClipHunter\Exception\ErrorCode;
use ClipHunter\Media\MetadataSanitizer;
use ClipHunter\Security\UrlValidator;

/**
 * The AniLiberty public API v1 (https://aniliberty.top/api/docs/v1): search, releases, episodes.
 *
 * Everything in a response is untrusted: texts are sanitized, ids checked against their formats,
 * images resolved only from /storage/ paths on the API host, and stream URLs accepted only on the
 * configured CDN hosts (config/aniliberty.php). Our server reads metadata only; the HLS streams
 * are played by browsers straight from the CDN.
 */
final readonly class AniLibertyClient
{
    public const EPISODE_ID = '~^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$~D';
    public const ALIAS = '~^[a-z0-9][a-z0-9-]{0,127}$~D';
    public const MAX_SEARCH_RESULTS = 20;

    private const TITLE_MAX = 160;
    private const EPISODE_NAME_MAX = 160;
    private const MAX_DURATION_SEC = 86_400;
    private const STREAM_HEIGHTS = [1080, 720, 480];

    private string $apiUrl;
    private string $mediaOrigin;

    /**
     * @param string $apiUrl base URL of the API, e.g. https://aniliberty.top/api/v1
     * @param list<string> $apiHosts
     * @param list<string> $streamHosts
     */
    public function __construct(
        private JsonFetcher $fetcher,
        private UrlValidator $validator,
        string $apiUrl,
        private array $apiHosts,
        private array $streamHosts,
        private string $siteUrl,
    ) {
        $this->apiUrl = rtrim($apiUrl, '/');
        $parts = parse_url($this->apiUrl);
        $this->mediaOrigin = 'https://' . (is_array($parts) ? ($parts['host'] ?? '') : '');
    }

    /**
     * @return list<Release> without episodes
     *
     * @throws ApiException
     */
    public function search(string $query): array
    {
        $data = $this->get('/app/search/releases?' . http_build_query(['query' => $query]));
        $releases = [];
        foreach (array_is_list($data) ? $data : [] as $item) {
            $release = is_array($item) ? $this->release($item) : null;
            if ($release !== null) {
                $releases[] = $release;
            }
            if (count($releases) >= self::MAX_SEARCH_RESULTS) {
                break;
            }
        }

        return $releases;
    }

    /**
     * @param int|string $idOrAlias numeric id or alias
     *
     * @throws ApiException VIDEO_UNAVAILABLE when there is no such release
     */
    public function fetchRelease(int|string $idOrAlias): Release
    {
        if (!is_int($idOrAlias) && preg_match(self::ALIAS, $idOrAlias) !== 1) {
            throw new ApiException(ErrorCode::InvalidUrl, 'bad release alias');
        }

        return $this->release($this->get('/anime/releases/' . rawurlencode((string) $idOrAlias)))
            ?? throw new ApiException(ErrorCode::ExtractorFailed, 'malformed release');
    }

    /**
     * @return array{episode: Episode, release: Release}
     *
     * @throws ApiException VIDEO_UNAVAILABLE when there is no such episode
     */
    public function fetchEpisode(string $episodeId): array
    {
        if (preg_match(self::EPISODE_ID, $episodeId) !== 1) {
            throw new ApiException(ErrorCode::InvalidUrl, 'bad episode id');
        }
        $data = $this->get('/anime/releases/episodes/' . $episodeId);
        $episode = $this->episode($data);
        $release = is_array($data['release'] ?? null) ? $this->release($data['release']) : null;
        if ($episode === null || $release === null || $episode->id !== $episodeId || $episode->releaseId !== $release->id) {
            throw new ApiException(ErrorCode::ExtractorFailed, 'malformed episode');
        }

        return ['episode' => $episode, 'release' => $release];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function get(string $path): array
    {
        return $this->fetcher->get($this->validator->validateServiceUrl($this->apiUrl . $path, $this->apiHosts));
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function release(array $data): ?Release
    {
        $id = $data['id'] ?? null;
        $alias = $data['alias'] ?? null;
        $name = is_array($data['name'] ?? null) ? $data['name'] : [];
        $title = MetadataSanitizer::text($name['main'] ?? null, self::TITLE_MAX);
        if (!is_int($id) || $id < 1 || $id > 9_999_999_999 || !is_string($alias) || preg_match(self::ALIAS, $alias) !== 1 || $title === null) {
            return null;
        }

        $episodes = [];
        foreach (is_array($data['episodes'] ?? null) ? $data['episodes'] : [] as $item) {
            $episode = is_array($item) ? $this->episode($item + ['release_id' => $id]) : null;
            if ($episode !== null && $episode->releaseId === $id) {
                $episodes[] = $episode;
            }
        }
        usort($episodes, static fn (Episode $a, Episode $b): int => $a->ordinal <=> $b->ordinal);

        $year = $data['year'] ?? null;
        $total = $data['episodes_total'] ?? null;
        $poster = is_array($data['poster'] ?? null) ? $data['poster'] : [];
        $optimized = is_array($poster['optimized'] ?? null) ? $poster['optimized'] : [];

        return new Release(
            id: $id,
            alias: $alias,
            title: $title,
            titleEnglish: MetadataSanitizer::text($name['english'] ?? null, self::TITLE_MAX),
            year: is_int($year) && $year > 1900 && $year < 2200 ? $year : null,
            posterUrl: $this->media($optimized['thumbnail'] ?? $poster['thumbnail'] ?? $poster['src'] ?? null),
            pageUrl: rtrim($this->siteUrl, '/') . '/anime/releases/release/' . $alias,
            blocked: ($data['is_blocked_by_geo'] ?? false) === true || ($data['is_blocked_by_copyrights'] ?? false) === true,
            episodesTotal: is_int($total) && $total > 0 && $total < 100_000 ? $total : null,
            episodes: $episodes,
        );
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function episode(array $data): ?Episode
    {
        $id = $data['id'] ?? null;
        $releaseId = $data['release_id'] ?? null;
        $ordinal = $data['ordinal'] ?? null;
        if (
            !is_string($id) || preg_match(self::EPISODE_ID, $id) !== 1 || !is_int($releaseId) || $releaseId < 1
            || !(is_int($ordinal) || is_float($ordinal)) || $ordinal < 0 || $ordinal >= 100_000
        ) {
            return null;
        }

        $streams = [];
        foreach (self::STREAM_HEIGHTS as $height) {
            $url = $this->streamUrl($data['hls_' . $height] ?? null);
            if ($url !== null) {
                $streams[$height] = $url;
            }
        }
        $duration = $data['duration'] ?? null;
        $preview = is_array($data['preview'] ?? null) ? $data['preview'] : [];
        $optimized = is_array($preview['optimized'] ?? null) ? $preview['optimized'] : [];

        return new Episode(
            id: $id,
            releaseId: $releaseId,
            ordinal: $ordinal,
            name: MetadataSanitizer::text($data['name'] ?? null, self::EPISODE_NAME_MAX),
            durationSec: is_int($duration) && $duration > 0 && $duration <= self::MAX_DURATION_SEC ? $duration : null,
            previewUrl: $this->media($optimized['thumbnail'] ?? $preview['thumbnail'] ?? $preview['src'] ?? null),
            streams: $streams,
            opening: self::range($data['opening'] ?? null),
            ending: self::range($data['ending'] ?? null),
        );
    }

    /** An image under /storage/ on the API host; anything else is dropped. */
    private function media(mixed $path): ?string
    {
        return is_string($path) && strlen($path) <= 512 && preg_match('~^/storage/[A-Za-z0-9/_.-]+$~D', $path) === 1 && !str_contains($path, '..')
            ? $this->mediaOrigin . $path
            : null;
    }

    /** An HLS playlist on one of the CDN hosts the CSP allows; anything else is dropped. */
    private function streamUrl(mixed $url): ?string
    {
        $url = MetadataSanitizer::httpsUrl($url);
        $parts = $url === null ? false : parse_url($url);
        if ($url === null || $parts === false || isset($parts['port']) || !str_ends_with($parts['path'] ?? '', '.m3u8')) {
            return null;
        }
        $host = strtolower($parts['host'] ?? '');
        foreach ($this->streamHosts as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return $url;
            }
        }

        return null;
    }

    /**
     * @return ?array{start: int, stop: int}
     */
    private static function range(mixed $value): ?array
    {
        $start = is_array($value) ? ($value['start'] ?? null) : null;
        $stop = is_array($value) ? ($value['stop'] ?? null) : null;

        return is_int($start) && is_int($stop) && $start >= 0 && $stop > $start && $stop <= self::MAX_DURATION_SEC
            ? ['start' => $start, 'stop' => $stop]
            : null;
    }
}
