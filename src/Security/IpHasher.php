<?php

declare(strict_types=1);

namespace ClipHunter\Security;

/**
 * Pseudonymises client IPs for logs and rate-limit keys, so raw IPs are never stored.
 */
final readonly class IpHasher
{
    public function __construct(private string $secret)
    {
    }

    /**
     * @return non-empty-string 16 lowercase hex characters
     */
    public function hash(string $ip): string
    {
        /** @var non-empty-string */
        return substr(hash_hmac('sha256', $ip, $this->secret), 0, 16);
    }
}
