<?php

declare(strict_types=1);

namespace ClipHunter\Job;

use ClipHunter\Exception\ErrorCode;

/**
 * Turns yt-dlp's stdout lines into overall progress.
 *
 * Data comes from the JSON emitted by our --progress-template; the human-readable lines are
 * only used to detect phases (post-processing) and a few "skipped" outcomes.
 */
final class ProgressTracker
{
    public const MARKER = 'CHPROGRESS ';

    private int $completedBytes = 0;
    private int $currentDownloaded = 0;
    private ?int $currentTotal = null;
    private ?int $speed = null;
    private ?int $eta = null;
    private bool $processing = false;
    private ?ErrorCode $skipReason = null;

    public function __construct(private readonly ?int $expectedTotalBytes)
    {
    }

    /**
     * @return bool whether the observable state changed
     */
    public function consume(string $line): bool
    {
        if (str_starts_with($line, self::MARKER)) {
            return $this->consumeProgress(substr($line, strlen(self::MARKER)));
        }

        if (preg_match('~^\[(Merger|ExtractAudio|VideoRemuxer|VideoConvertor|FixupM3u8|FixupM4a|FixupStretched|FixupDuplicateMoov)\]~', $line) === 1) {
            $changed = !$this->processing;
            $this->processing = true;

            return $changed;
        }

        if (stripos($line, 'larger than max-filesize') !== false) {
            $this->skipReason = ErrorCode::FileTooLarge;
        } elseif (stripos($line, 'does not pass filter') !== false) {
            $this->skipReason = stripos($line, 'is_live') !== false ? ErrorCode::LiveNotSupported : ErrorCode::VideoTooLong;
        }

        return false;
    }

    public function isProcessing(): bool
    {
        return $this->processing;
    }

    /** Set when yt-dlp skipped the download instead of failing (it may still exit 0). */
    public function skipReason(): ?ErrorCode
    {
        return $this->skipReason;
    }

    public function progress(): Progress
    {
        $downloaded = $this->completedBytes + $this->currentDownloaded;

        if ($this->processing) {
            $percent = 100.0;
        } elseif ($this->expectedTotalBytes !== null && $this->expectedTotalBytes > 0) {
            $percent = min(99.0, $downloaded / $this->expectedTotalBytes * 100);
        } elseif ($this->currentTotal !== null && $this->currentTotal > 0) {
            $percent = min(99.0, $this->currentDownloaded / $this->currentTotal * 100);
        } else {
            $percent = null;
        }

        return new Progress(
            percent: $percent === null ? null : round($percent, 1),
            downloadedBytes: $downloaded,
            totalBytes: $this->expectedTotalBytes ?? $this->currentTotal,
            speedBps: $this->processing ? null : $this->speed,
            etaSec: $this->processing ? null : $this->eta,
        );
    }

    private function consumeProgress(string $json): bool
    {
        $data = json_decode($json, true, 8);
        if (!is_array($data)) {
            return false;
        }

        $downloaded = self::int($data['downloaded_bytes'] ?? null) ?? 0;
        $total = self::int($data['total_bytes'] ?? null) ?? self::int($data['total_bytes_estimate'] ?? null);

        if (($data['status'] ?? null) === 'finished') {
            $this->completedBytes += $total ?? $downloaded;
            $this->currentDownloaded = 0;
            $this->currentTotal = null;

            return true;
        }

        $this->currentDownloaded = $downloaded;
        $this->currentTotal = $total;
        $this->speed = self::int($data['speed'] ?? null);
        $this->eta = self::int($data['eta'] ?? null);

        return true;
    }

    private static function int(mixed $value): ?int
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 ? (int) $value : null;
    }
}
