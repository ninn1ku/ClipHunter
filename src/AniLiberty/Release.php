<?php

declare(strict_types=1);

namespace ClipHunter\AniLiberty;

/**
 * An AniLiberty release (an anime title), sanitized. episodes is empty when the API response did
 * not include them (search results).
 */
final readonly class Release
{
    /**
     * @param list<Episode> $episodes sorted by ordinal
     */
    public function __construct(
        public int $id,
        public string $alias,
        public string $title,
        public ?string $titleEnglish,
        public ?int $year,
        public ?string $posterUrl,
        public string $pageUrl,
        public bool $blocked,
        public ?int $episodesTotal,
        public array $episodes,
    ) {
    }

    /** The first episode that can be played, or null. */
    public function firstPlayableEpisode(): ?Episode
    {
        foreach ($this->episodes as $episode) {
            if ($episode->streams !== []) {
                return $episode;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $withEpisodes): array
    {
        $data = [
            'id' => $this->id,
            'alias' => $this->alias,
            'title' => $this->title,
            'titleEnglish' => $this->titleEnglish,
            'year' => $this->year,
            'posterUrl' => $this->posterUrl,
            'pageUrl' => $this->pageUrl,
            'blocked' => $this->blocked,
            'episodesTotal' => $this->episodesTotal,
        ];
        if ($withEpisodes) {
            $data['episodes'] = array_map(static fn (Episode $e): array => $e->summary(), $this->episodes);
        }

        return $data;
    }
}
