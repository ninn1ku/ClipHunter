<?php

declare(strict_types=1);

namespace ClipHunter\Media;

final readonly class Platform
{
    /**
     * @param non-empty-list<string> $domains lowercase registrable domains
     * @param list<string> $extractors yt-dlp extractor names; empty for platforms played only in
     *                                the browser, which yt-dlp never sees
     */
    public function __construct(
        public string $key,
        public string $name,
        public array $domains,
        public array $extractors,
    ) {
    }

    /** Whether yt-dlp may analyse and download links of this platform. */
    public function isYtDlpSource(): bool
    {
        return $this->extractors !== [];
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
