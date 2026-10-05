<?php

declare(strict_types=1);

namespace ClipHunter\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Resolves the client IP.
 *
 * Nginx and PHP-FPM run on the same host with no proxy in front, so REMOTE_ADDR is authoritative.
 * X-Forwarded-For is deliberately ignored: it is client-controlled and would let anyone dodge rate limits.
 */
final class ClientIp
{
    public const UNKNOWN = '0.0.0.0';

    public static function fromRequest(ServerRequestInterface $request): string
    {
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        if (is_string($remote) && filter_var($remote, FILTER_VALIDATE_IP) !== false) {
            return $remote;
        }

        return self::UNKNOWN;
    }

    public static function isLoopback(string $ip): bool
    {
        return $ip === '::1' || str_starts_with($ip, '127.');
    }
}
