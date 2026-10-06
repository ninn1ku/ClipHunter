<?php

declare(strict_types=1);

namespace ClipHunter\Watch;

use InvalidArgumentException;

/**
 * Signs a {@see WatchSource} for the rooms service (docs/WATCH_PARTY_PLAN.md §2.3).
 *
 *   ticket = base64url(payloadJson) "." base64url(HMAC-SHA256(key = hex2bin(ROOMS_SECRET), data = base64url(payloadJson)))
 *
 * The client carries the ticket to the rooms service, which verifies it and only then accepts the
 * media. The rooms service therefore never trusts a URL or title sent by a browser, and the two
 * services never call each other. The format is pinned by tests/fixtures/watch/ticket-v1.json,
 * which the PHP and Deno test suites both check.
 */
final readonly class MediaTicket
{
    public const VERSION = 1;
    public const TTL_SEC = 600;

    /**
     * Tickets travel inside WebSocket frames limited to 4096 bytes. Long thumbnail URLs (some CDNs
     * sign them) are dropped rather than risking an oversized frame; a missing thumbnail is cosmetic.
     */
    public const MAX_THUMBNAIL_LENGTH = 1024;

    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    private string $key;

    public function __construct(string $secretHex)
    {
        $key = preg_match('~^[a-f0-9]{64,}$~D', $secretHex) === 1 && strlen($secretHex) % 2 === 0 ? hex2bin($secretHex) : false;
        if ($key === false) {
            throw new InvalidArgumentException('The rooms secret must be an even-length hex string of at least 64 characters.');
        }
        $this->key = $key;
    }

    public function issue(WatchSource $source, int $now): string
    {
        $thumbnail = $source->thumbnailUrl !== null && strlen($source->thumbnailUrl) <= self::MAX_THUMBNAIL_LENGTH
            ? $source->thumbnailUrl
            : null;

        return $this->sign([
            'v' => self::VERSION,
            'kind' => $source->kind->value,
            'ref' => $source->ref,
            'platform' => $source->platform,
            'title' => $source->title,
            'durationSec' => $source->durationSec,
            'thumbnailUrl' => $thumbnail,
            'startSec' => $source->startSec,
            'exp' => $now + self::TTL_SEC,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function sign(array $payload): string
    {
        $body = self::base64url(json_encode($payload, self::JSON_FLAGS));

        return $body . '.' . self::base64url(hash_hmac('sha256', $body, $this->key, true));
    }

    private static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
