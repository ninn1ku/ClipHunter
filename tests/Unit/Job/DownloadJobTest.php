<?php

declare(strict_types=1);

namespace ClipHunter\Tests\Unit\Job;

use ClipHunter\Job\DownloadJob;
use ClipHunter\Job\JobPurpose;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DownloadJobTest extends TestCase
{
    /**
     * A job file as written before watch rooms existed (no "purpose").
     *
     * @return array<string, mixed>
     */
    private static function legacyRecord(): array
    {
        return [
            'id' => str_repeat('a', 32),
            'analysisId' => str_repeat('b', 32),
            'optionId' => 'v720',
            'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'platformKey' => 'youtube',
            'title' => 'Title',
            'expectedDurationSec' => 213,
            'expectedSizeBytes' => null,
            'ipHash' => '0123456789abcdef',
            'createdAt' => 1_800_000_000,
            'status' => 'completed',
            'progress' => null,
            'error' => null,
            'fileName' => 'Title.mp4',
            'fileExt' => 'mp4',
            'fileSizeBytes' => 1000,
            'startedAt' => 1_800_000_001,
            'finishedAt' => 1_800_000_002,
            'expiresAt' => 1_800_001_800,
        ];
    }

    public function testJobFilesWithoutAPurposeAreDownloads(): void
    {
        $job = DownloadJob::fromArray(self::legacyRecord());

        self::assertSame(JobPurpose::Download, $job->purpose);
    }

    public function testPurposeSurvivesARoundTrip(): void
    {
        $job = DownloadJob::fromArray(['purpose' => 'watch'] + self::legacyRecord());

        self::assertSame(JobPurpose::Watch, $job->purpose);
        self::assertSame('watch', $job->toArray()['purpose']);
        self::assertSame(JobPurpose::Watch, DownloadJob::fromArray($job->toArray())->purpose);
    }

    public function testUnknownPurposeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DownloadJob::fromArray(['purpose' => 'stream'] + self::legacyRecord());
    }
}
