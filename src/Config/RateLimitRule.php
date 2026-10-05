<?php

declare(strict_types=1);

namespace ClipHunter\Config;

/**
 * "N requests per W seconds" in a fixed window.
 */
final readonly class RateLimitRule
{
    public function __construct(
        public int $limit,
        public int $windowSec,
    ) {
        if ($limit < 1 || $windowSec < 1) {
            throw new ConfigException('Rate limit and window must be positive.');
        }
    }

    /**
     * Parses the "<limit>/<windowSeconds>" notation used in the environment.
     */
    public static function fromString(string $value): self
    {
        if (preg_match('~^\s*(\d{1,6})\s*/\s*(\d{1,7})\s*$~', $value, $m) !== 1) {
            throw new ConfigException(sprintf('Invalid rate limit "%s", expected "<limit>/<seconds>".', $value));
        }

        return new self((int) $m[1], (int) $m[2]);
    }
}
