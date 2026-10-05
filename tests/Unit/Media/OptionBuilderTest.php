<?php

declare(strict_types=1);

namespace ClipHunter\Tests\Unit\Media;

use ClipHunter\Media\DownloadOption;
use ClipHunter\Media\FormatArgs;
use ClipHunter\Media\FormatInfo;
use ClipHunter\Media\MediaInfo;
use ClipHunter\Media\OptionBuilder;
use PHPUnit\Framework\TestCase;

final class OptionBuilderTest extends TestCase
{
    private const MB = 1024 * 1024;

    /**
     * @param list<FormatInfo> $formats
     */
    private static function media(array $formats, ?int $duration = 600): MediaInfo
    {
        return new MediaInfo('t', null, $duration, null, 'youtube', $formats);
    }

    private static function video(int $w, int $h, ?int $size, string $vcodec = 'avc1.640028', string $acodec = 'none'): FormatInfo
    {
        return new FormatInfo('mp4', $vcodec, $acodec, $w, $h, $size, false);
    }

    private static function audio(string $ext, ?int $size): FormatInfo
    {
        return new FormatInfo($ext, 'none', $ext === 'm4a' ? 'mp4a.40.2' : 'opus', null, null, $size, false);
    }

    /**
     * @param list<DownloadOption> $options
     *
     * @return list<string>
     */
    private static function ids(array $options): array
    {
        return array_map(static fn (DownloadOption $o): string => $o->id, $options);
    }

    public function testTierMappingToleratesSlightlyShortResolutions(): void
    {
        self::assertSame(1080, OptionBuilder::tierFor(1080));
        self::assertSame(1080, OptionBuilder::tierFor(1012));
        self::assertSame(720, OptionBuilder::tierFor(1000));
        self::assertSame(360, OptionBuilder::tierFor(352));
        self::assertSame(144, OptionBuilder::tierFor(136));
        self::assertNull(OptionBuilder::tierFor(100));
    }

    public function testOptionsRespectMaxHeightAndMaxSize(): void
    {
        $formats = [
            self::video(3840, 2160, 900 * self::MB),
            self::video(2560, 1440, 500 * self::MB),
            self::video(1920, 1080, 100 * self::MB),
            self::audio('m4a', 10 * self::MB),
        ];

        $sizeLimited = (new OptionBuilder(2160, 600 * self::MB))->build(self::media($formats));
        $heightLimited = (new OptionBuilder(1080, 10_000 * self::MB))->build(self::media($formats));

        self::assertSame(['v1440', 'v1080', 'a-m4a', 'a-mp3'], self::ids($sizeLimited));
        self::assertSame(['v1080', 'a-m4a', 'a-mp3'], self::ids($heightLimited));
    }

    public function testUnknownSizesAreStillOfferedWithoutASize(): void
    {
        $options = (new OptionBuilder(2160, 100 * self::MB))->build(self::media([self::video(1280, 720, null), self::audio('webm', null)], null));

        self::assertSame(['v720', 'a-m4a', 'a-mp3'], self::ids($options));
        self::assertNull($options[0]->sizeBytes);
        self::assertNull($options[2]->sizeBytes, 'mp3 size needs a known duration');
    }

    public function testVideoWithoutAudioStreamsOffersNoAudioOptions(): void
    {
        $options = (new OptionBuilder(2160, 100 * self::MB))->build(self::media([self::video(640, 360, 5 * self::MB)]));

        self::assertSame(['v360'], self::ids($options));
    }

    public function testEveryOfferedOptionHasFormatArguments(): void
    {
        foreach (OptionBuilder::TIERS as $tier) {
            self::assertContains('--merge-output-format', FormatArgs::for('v' . $tier));
        }
        self::assertContains('-x', FormatArgs::for('a-mp3'));
        self::assertSame('audio/mpeg', FormatArgs::mimeType('a-mp3'));
        self::assertSame('video/mp4', FormatArgs::mimeType('v720'));

        $this->expectException(\InvalidArgumentException::class);
        FormatArgs::for('bestvideo+bestaudio');
    }
}
