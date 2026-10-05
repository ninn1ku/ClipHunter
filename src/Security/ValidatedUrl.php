<?php

declare(strict_types=1);

namespace ClipHunter\Security;

use ClipHunter\Media\Platform;

/**
 * A URL that passed every check in {@see UrlValidator}: canonical https form, allowlisted host,
 * public DNS. Only instances of this class may be handed to yt-dlp.
 */
final readonly class ValidatedUrl
{
    public function __construct(
        public string $url,
        public string $host,
        public Platform $platform,
    ) {
    }

    /**
     * Stable short identifier for logs: the full URL is never logged.
     */
    public function hash(): string
    {
        return substr(hash('sha256', $this->url), 0, 16);
    }
}
