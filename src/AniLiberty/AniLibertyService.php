<?php

declare(strict_types=1);

namespace ClipHunter\AniLiberty;

use ClipHunter\Config\AppConfig;
use ClipHunter\Exception\ApiException;
use ClipHunter\Exception\ErrorCode;
use ClipHunter\Media\MetadataSanitizer;
use ClipHunter\RateLimit\RateLimiter;
use Psr\Log\LoggerInterface;

/**
 * Use cases behind /api/watch/aniliberty/*: title search for the host, the episode list of a
 * release (episode picker), and one episode's streams (players). Every call costs one request to
 * the AniLiberty API, so all of them share the watch_anime rate limit.
 */
final readonly class AniLibertyService
{
    public const RATE_BUCKET = 'watch_anime';
    public const QUERY_MIN = 2;
    public const QUERY_MAX = 100;

    public function __construct(
        private AniLibertyClient $client,
        private RateLimiter $rateLimiter,
        private AppConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws ApiException
     */
    public function search(string $rawQuery, string $ipHash): array
    {
        $query = MetadataSanitizer::text($rawQuery, self::QUERY_MAX + 1);
        if ($query === null || mb_strlen($query) < self::QUERY_MIN || mb_strlen($query) > self::QUERY_MAX) {
            throw new ApiException(ErrorCode::InvalidRequest, 'bad search query', 'Введите от 2 до 100 символов.');
        }
        $this->rateLimiter->hit(self::RATE_BUCKET, $ipHash, $this->config->watchAnimeRateLimit);

        $results = $this->client->search($query);
        $this->logger->info('aniliberty.search', ['results' => count($results)]);

        return array_map(static fn (Release $r): array => $r->toArray(false), $results);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function release(int $id, string $ipHash): array
    {
        $this->rateLimiter->hit(self::RATE_BUCKET, $ipHash, $this->config->watchAnimeRateLimit);
        $release = $this->client->fetchRelease($id);
        self::assertWatchable($release);

        return $release->toArray(true);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function episode(string $episodeId, string $ipHash): array
    {
        $this->rateLimiter->hit(self::RATE_BUCKET, $ipHash, $this->config->watchAnimeRateLimit);
        ['episode' => $episode, 'release' => $release] = $this->client->fetchEpisode($episodeId);
        self::assertWatchable($release);
        if ($episode->streams === []) {
            throw new ApiException(ErrorCode::NoFormats, 'episode without streams', 'У этой серии пока нет видео.');
        }

        return $episode->toPlayerArray();
    }

    /**
     * Releases AniLiberty itself does not show (copyright holder's request, region) are not shown
     * here either.
     *
     * @throws ApiException GEO_RESTRICTED
     */
    public static function assertWatchable(Release $release): void
    {
        if ($release->blocked) {
            throw new ApiException(ErrorCode::GeoRestricted, 'release blocked by aniliberty', 'AniLiberty не показывает этот тайтл: доступ ограничен правообладателем или по региону.');
        }
    }
}
