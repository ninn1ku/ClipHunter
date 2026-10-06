<?php

declare(strict_types=1);

namespace ClipHunter\Tests\Unit\Watch;

use ClipHunter\Watch\MediaTicket;
use ClipHunter\Watch\WatchSource;
use ClipHunter\Watch\WatchSourceKind;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MediaTicketTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../fixtures/watch/ticket-v1.json';

    public function testMatchesTheContractFixtureSharedWithTheRoomsService(): void
    {
        $fixture = self::fixture();

        self::assertSame($fixture['ticket'], (new MediaTicket($fixture['secret']))->sign($fixture['payload']));
    }

    public function testIssuedTicketCarriesTheSourceAndExpiresInTenMinutes(): void
    {
        $secret = str_repeat('ab', 32);
        $source = new WatchSource(WatchSourceKind::YouTube, 'dQw4w9WgXcQ', 'YouTube', null, null, 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg', 42);

        $ticket = (new MediaTicket($secret))->issue($source, 1_800_000_000);

        [$body, $signature] = explode('.', $ticket);
        self::assertSame(self::b64(hash_hmac('sha256', $body, (string) hex2bin($secret), true)), $signature);
        self::assertSame([
            'v' => 1,
            'kind' => 'youtube',
            'ref' => 'dQw4w9WgXcQ',
            'platform' => 'YouTube',
            'title' => null,
            'durationSec' => null,
            'thumbnailUrl' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
            'startSec' => 42,
            'exp' => 1_800_000_600,
        ], self::payload($ticket));
        self::assertMatchesRegularExpression('~^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]{43}$~', $ticket, 'unpadded base64url');
    }

    public function testWorstCaseTicketFitsIntoAWebSocketFrame(): void
    {
        $longThumb = 'https://cdn.example.com/' . str_repeat('a', 2000);
        $title = str_repeat("\u{1F3AC}", 200);
        $source = new WatchSource(WatchSourceKind::File, str_repeat('f', 32), 'Одноклассники', $title, 86_400, $longThumb, 0);

        $ticket = (new MediaTicket(str_repeat('ab', 32)))->issue($source, 1_800_000_000);

        self::assertNull(self::payload($ticket)['thumbnailUrl'], 'over-long thumbnails are dropped');
        // {"type":"media.set","reqId":"<16 chars>","ticket":"..."} must stay below the 4096-byte frame limit.
        self::assertLessThan(4096 - 64, strlen($ticket));
    }

    public function testRejectsWeakSecrets(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MediaTicket('abc');
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(string $ticket): array
    {
        $payload = json_decode((string) base64_decode(strtr(explode('.', $ticket)[0], '-_', '+/'), true), true);
        self::assertIsArray($payload);

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * @return array{secret: string, payload: array<string, mixed>, ticket: string}
     */
    private static function fixture(): array
    {
        /** @var array{secret: string, payload: array<string, mixed>, ticket: string} */
        return json_decode((string) file_get_contents(self::FIXTURE), true, 16, JSON_THROW_ON_ERROR);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
