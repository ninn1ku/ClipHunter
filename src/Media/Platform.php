<?php

declare(strict_types=1);

namespace ClipHunter\Media;

final readonly class Platform
{
    /**
     * @param non-empty-list<string> $domains lowercase registrable domains
     * @param non-empty-list<string> $extractors yt-dlp extractor names
     */
    public function __construct(
        public string $key,
        public string $name,
        public array $domains,
        public array $extractors,
    ) {
    }

    public function matchesHost(string $host): bool
    {
        foreach ($this->domains as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }
}
