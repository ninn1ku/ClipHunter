<?php

declare(strict_types=1);

namespace ClipHunter\RateLimit;

use ClipHunter\Config\RateLimitRule;
use ClipHunter\Exception\ApiException;
use ClipHunter\Exception\ErrorCode;
use ClipHunter\Support\Clock;
use InvalidArgumentException;
use RuntimeException;

/**
 * Fixed-window counters, one small file per (bucket, client) guarded by flock().
 */
final readonly class RateLimiter
{
    public function __construct(
        private string $dir,
        private Clock $clock,
    ) {
    }

    /**
     * Counts one hit; throws once the limit for the current window is exhausted.
     *
     * @param string $key pseudonymised client id (hex)
     *
     * @throws ApiException RATE_LIMITED with Retry-After
     */
    public function hit(string $bucket, string $key, RateLimitRule $rule): void
    {
        if (preg_match('~^[a-z0-9_-]{1,32}$~D', $bucket) !== 1 || preg_match('~^[a-f0-9]{8,64}$~D', $key) !== 1) {
            throw new InvalidArgumentException('Invalid rate limit bucket or key.');
        }

        $handle = @fopen($this->dir . '/' . $bucket . '-' . $key . '.json', 'c+');
        if ($handle === false) {
            throw new RuntimeException('Cannot open rate limit file.');
        }

        try {
            flock($handle, LOCK_EX);
            $now = $this->clock->now();

            $state = json_decode((string) stream_get_contents($handle), true);
            $start = is_array($state) && is_int($state['start'] ?? null) ? $state['start'] : $now;
            $count = is_array($state) && is_int($state['count'] ?? null) ? $state['count'] : 0;

            if ($now >= $start + $rule->windowSec) {
                $start = $now;
                $count = 0;
            }

            if ($count >= $rule->limit) {
                $retryAfter = max(1, $start + $rule->windowSec - $now);
                throw new ApiException(
                    ErrorCode::RateLimited,
                    sprintf('bucket=%s limit=%d window=%d', $bucket, $rule->limit, $rule->windowSec),
                    headers: ['Retry-After' => (string) $retryAfter],
                );
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode(['start' => $start, 'count' => $count + 1, 'expires' => $start + $rule->windowSec], JSON_THROW_ON_ERROR));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
