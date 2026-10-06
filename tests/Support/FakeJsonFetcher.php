<?php

declare(strict_types=1);

namespace ClipHunter\Tests\Support;

use ClipHunter\AniLiberty\JsonFetcher;
use ClipHunter\Exception\ApiException;
use ClipHunter\Exception\ErrorCode;
use UnexpectedValueException;

/**
 * Canned API responses by URL path (+ query), for tests without network. Unknown paths answer 404.
 */
final class FakeJsonFetcher implements JsonFetcher
{
    /** @var list<string> */
    public array $requested = [];

    /**
     * @param array<string, array<array-key, mixed>|ApiException> $responses path (with query) => decoded JSON
     */
    public function __construct(public array $responses = [])
    {
    }

    public function get(string $url): array
    {
        $this->requested[] = $url;
        $parts = parse_url($url);
        $key = (is_array($parts) ? ($parts['path'] ?? '') : '') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $response = $this->responses[$key] ?? new ApiException(ErrorCode::VideoUnavailable, 'service returned 404');
        if ($response instanceof ApiException) {
            throw $response;
        }

        return $response;
    }

    /**
     * Responses built from tests/fixtures/aniliberty: a release (10335) with three episodes, one of
     * them hostile, and the episode endpoint for its first episode.
     *
     * @return array<string, array<array-key, mixed>>
     */
    public static function fixtureResponses(): array
    {
        $release = self::fixture('release.json');
        $episodes = $release['episodes'] ?? null;
        // Episode 1 (the playable one) is the second entry of the fixture.
        $episode = is_array($episodes) && is_array($episodes[1] ?? null) ? $episodes[1] : throw new UnexpectedValueException('fixture episode missing');

        return [
            '/api/v1/anime/releases/10335' => $release,
            '/api/v1/anime/releases/test-anime' => $release,
            '/api/v1/anime/releases/episodes/a2eaa868-41e2-486d-81f0-c2f124f82803' => $episode + ['release' => $release],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fixture(string $name): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/aniliberty/' . $name), true, 64, JSON_THROW_ON_ERROR);
    }
}
