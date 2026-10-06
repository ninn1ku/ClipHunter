<?php

declare(strict_types=1);

namespace ClipHunter\Tests\Unit\Http;

use ClipHunter\Http\ByteRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ByteRangeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, array{int, int}}>
     */
    public static function ranges(): iterable
    {
        yield 'closed' => ['bytes=0-99', [0, 99]];
        yield 'open ended' => ['bytes=500-', [500, 999]];
        yield 'end clamped to size' => ['bytes=900-5000', [900, 999]];
        yield 'suffix' => ['bytes=-100', [900, 999]];
        yield 'suffix larger than file' => ['bytes=-5000', [0, 999]];
        yield 'single byte' => ['bytes=999-999', [999, 999]];
    }

    /**
     * @param array{int, int} $expected
     */
    #[DataProvider('ranges')]
    public function testParsesSatisfiableRanges(string $header, array $expected): void
    {
        $range = ByteRange::parse($header, 1000);

        self::assertNotNull($range);
        self::assertTrue($range->satisfiable);
        self::assertSame($expected, [$range->start, $range->end]);
        self::assertSame($expected[1] - $expected[0] + 1, $range->length());
        self::assertSame(sprintf('bytes %d-%d/1000', ...$expected), $range->contentRange(1000));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ignored(): iterable
    {
        yield 'absent' => [''];
        yield 'other unit' => ['items=0-1'];
        yield 'multi range' => ['bytes=0-1,5-6'];
        yield 'empty spec' => ['bytes=-'];
        yield 'garbage' => ['bytes=abc-def'];
        yield 'negative' => ['bytes=-1-2'];
    }

    #[DataProvider('ignored')]
    public function testIgnoresUnsupportedHeaders(string $header): void
    {
        self::assertNull(ByteRange::parse($header, 1000));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsatisfiable(): iterable
    {
        yield 'start past end' => ['bytes=1000-'];
        yield 'inverted' => ['bytes=50-10'];
        yield 'zero suffix' => ['bytes=-0'];
    }

    #[DataProvider('unsatisfiable')]
    public function testReportsUnsatisfiableRanges(string $header): void
    {
        $range = ByteRange::parse($header, 1000);

        self::assertNotNull($range);
        self::assertFalse($range->satisfiable);
        self::assertSame('bytes */1000', $range->contentRange(1000));
    }
}
