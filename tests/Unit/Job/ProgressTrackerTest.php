<?php

declare(strict_types=1);

namespace ClipHunter\Tests\Unit\Job;

use ClipHunter\Exception\ErrorCode;
use ClipHunter\Job\ProgressTracker;
use PHPUnit\Framework\TestCase;

final class ProgressTrackerTest extends TestCase
{
    /**
     * @param array<string, mixed> $progress
     */
    private static function line(array $progress): string
    {
        return ProgressTracker::MARKER . json_encode($progress);
    }

    public function testCombinesVideoAndAudioStreamsAgainstTheExpectedSize(): void
    {
        $tracker = new ProgressTracker(1000);

        $tracker->consume(self::line(['status' => 'downloading', 'downloaded_bytes' => 300, 'total_bytes' => 700, 'speed' => 1234.5, 'eta' => 3]));
        self::assertSame(30.0, $tracker->progress()->percent);
        self::assertSame(1234, $tracker->progress()->speedBps);

        $tracker->consume(self::line(['status' => 'finished', 'downloaded_bytes' => 700, 'total_bytes' => 700]));
        $tracker->consume(self::line(['status' => 'downloading', 'downloaded_bytes' => 150, 'total_bytes_estimate' => 300.7]));
        self::assertSame(85.0, $tracker->progress()->percent);
        self::assertSame(850, $tracker->progress()->downloadedBytes);

        $tracker->consume('[Merger] Merging formats into "/tmp/x/media.mp4"');
        self::assertTrue($tracker->isProcessing());
        self::assertSame(100.0, $tracker->progress()->percent);
        self::assertNull($tracker->progress()->etaSec);
    }

    public function testFallsBackToPerStreamPercentWithoutAnExpectedSize(): void
    {
        $tracker = new ProgressTracker(null);
        $tracker->consume(self::line(['status' => 'downloading', 'downloaded_bytes' => 50, 'total_bytes' => 200]));

        self::assertSame(25.0, $tracker->progress()->percent);
    }

    public function testIgnoresNoiseAndMalformedJson(): void
    {
        $tracker = new ProgressTracker(100);

        self::assertFalse($tracker->consume('[youtube] abc: Downloading webpage'));
        self::assertFalse($tracker->consume(ProgressTracker::MARKER . '{broken'));
        self::assertTrue($tracker->consume(self::line(['downloaded_bytes' => -5, 'total_bytes' => 'x'])));
        self::assertSame(0, $tracker->progress()->downloadedBytes);
        self::assertNull($tracker->skipReason());
    }

    public function testDetectsSkippedDownloads(): void
    {
        $size = new ProgressTracker(null);
        $size->consume('[download] File is larger than max-filesize (5 bytes > 1 bytes). Skipping...');
        $live = new ProgressTracker(null);
        $live->consume('[info] abc: does not pass filter (!is_live), skipping ..');
        $long = new ProgressTracker(null);
        $long->consume('[info] abc: does not pass filter (duration <=? 7200), skipping ..');

        self::assertSame(ErrorCode::FileTooLarge, $size->skipReason());
        self::assertSame(ErrorCode::LiveNotSupported, $live->skipReason());
        self::assertSame(ErrorCode::VideoTooLong, $long->skipReason());
    }
}
