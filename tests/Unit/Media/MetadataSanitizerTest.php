<?php

declare(strict_types=1);

namespace ClipHunter\Tests\Unit\Media;

use ClipHunter\Exception\ApiException;
use ClipHunter\Exception\ErrorCode;
use ClipHunter\Media\MetadataSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MetadataSanitizerTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function base(): array
    {
        return ['title' => 'ok', 'duration' => 10, 'formats' => [['ext' => 'mp4', 'vcodec' => 'avc1', 'acodec' => 'mp4a', 'height' => 360, 'width' => 640]]];
    }

    /**
     * @return iterable<string, array{mixed, ?string}>
     */
    public static function texts(): iterable
    {
        yield 'plain' => ['Hello', 'Hello'];
        yield 'cyrillic and emoji' => ['Привет 🎬', 'Привет 🎬'];
        yield 'bidi override' => ["abc\u{202E}fdp.exe", 'abc fdp.exe'];
        yield 'zero width' => ["a\u{200B}b", 'a b'];
        yield 'control chars' => ["a\x00b\x07c\x1Bd", 'a b c d'];
        yield 'newlines collapse' => ["line1\n\n\tline2", 'line1 line2'];
        yield 'invalid utf8' => ["ok\xC3\x28", 'ok?('];
        yield 'nfc normalisation' => ["e\u{0301}", "\u{00E9}"];
        yield 'only whitespace' => ["  \n ", null];
        yield 'number' => [42, '42'];
        yield 'array' => [['x'], null];
        yield 'null' => [null, null];
        yield 'bool' => [true, null];
    }

    #[DataProvider('texts')]
    public function testTextIsNormalised(mixed $input, ?string $expected): void
    {
        self::assertSame($expected, MetadataSanitizer::text($input, 50));
    }

    public function testTextIsTruncated(): void
    {
        $text = MetadataSanitizer::text(str_repeat('я', 300), 200);

        self::assertNotNull($text);
        self::assertSame(200, mb_strlen($text));
        self::assertStringEndsWith('…', $text);
    }

    /**
     * @return iterable<string, array{mixed, ?string}>
     */
    public static function urls(): iterable
    {
        yield 'https' => ['https://i.ytimg.com/vi/x/hq.jpg', 'https://i.ytimg.com/vi/x/hq.jpg'];
        yield 'http' => ['http://i.ytimg.com/x.jpg', null];
        yield 'javascript' => ['javascript:alert(1)', null];
        yield 'data' => ['data:image/svg+xml,<svg onload=alert(1)>', null];
        yield 'quote breakout' => ['https://x.com/a.jpg" onerror="alert(1)', null];
        yield 'userinfo' => ['https://user:pw@x.com/a.jpg', null];
        yield 'relative' => ['//x.com/a.jpg', null];
        yield 'not string' => [123, null];
    }

    #[DataProvider('urls')]
    public function testOnlySafeHttpsUrlsSurvive(mixed $input, ?string $expected): void
    {
        self::assertSame($expected, MetadataSanitizer::httpsUrl($input));
    }

    public function testWrongTypesAreIgnored(): void
    {
        $media = (new MetadataSanitizer(7200))->sanitize([
            'title' => ['nested'],
            'duration' => '120',
            'extractor' => '../../x',
            'formats' => [
                'not-an-array',
                ['ext' => '../mp4', 'vcodec' => 'avc1;rm', 'acodec' => 'mp4a', 'height' => -5, 'filesize' => 'big'],
                ['ext' => 'mp4', 'vcodec' => 'avc1', 'acodec' => 'none', 'height' => 720, 'width' => 1280, 'filesize' => 1e30],
            ],
        ]);

        self::assertSame('Без названия', $media->title);
        self::assertNull($media->durationSec);
        self::assertSame('unknown', $media->extractor);
        self::assertCount(2, $media->formats);
        self::assertNull($media->formats[0]->ext);
        self::assertNull($media->formats[0]->vcodec);
        self::assertNull($media->formats[0]->height);
        self::assertNull($media->formats[1]->sizeBytes, 'absurd sizes are dropped');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, ErrorCode}>
     */
    public static function rejections(): iterable
    {
        yield 'playlist' => [['_type' => 'playlist'] + self::base(), ErrorCode::PlaylistNotSupported];
        yield 'entries' => [['entries' => []] + self::base(), ErrorCode::PlaylistNotSupported];
        yield 'live flag' => [['is_live' => true] + self::base(), ErrorCode::LiveNotSupported];
        yield 'upcoming' => [['live_status' => 'is_upcoming'] + self::base(), ErrorCode::LiveNotSupported];
        yield 'too long' => [['duration' => 7201] + self::base(), ErrorCode::VideoTooLong];
        yield 'no formats' => [['formats' => []] + ['title' => 'x'], ErrorCode::NoFormats];
        yield 'drm only' => [['formats' => [['has_drm' => true, 'vcodec' => 'avc1', 'height' => 720]]] + ['title' => 'x'], ErrorCode::VideoUnavailable];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('rejections')]
    public function testRejectsUnsupportedResults(array $data, ErrorCode $expected): void
    {
        try {
            (new MetadataSanitizer(7200))->sanitize($data);
            self::fail('Expected rejection');
        } catch (ApiException $e) {
            self::assertSame($expected, $e->errorCode);
        }
    }

    public function testWasLiveRecordingsAreAccepted(): void
    {
        $media = (new MetadataSanitizer(7200))->sanitize(['live_status' => 'was_live'] + self::base());

        self::assertSame(10, $media->durationSec);
    }
}
