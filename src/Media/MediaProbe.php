<?php

declare(strict_types=1);

namespace ClipHunter\Media;

use ClipHunter\Process\ProcessOptions;
use ClipHunter\Process\ProcessRunner;

/**
 * Verifies a produced media file with ffprobe before it is offered for download.
 */
final readonly class MediaProbe
{
    private const TIMEOUT_SEC = 20;

    /**
     * @param non-empty-list<string> $ffprobe
     */
    public function __construct(
        private array $ffprobe,
        private ProcessRunner $runner,
    ) {
    }

    /**
     * @return array{formatName: string, durationSec: ?float, hasVideo: bool, hasAudio: bool}|null null if unreadable
     */
    public function probe(string $path): ?array
    {
        $result = $this->runner->run([
            ...$this->ffprobe,
            '-v', 'error',
            '-print_format', 'json',
            '-show_entries', 'format=format_name,duration:stream=codec_type',
            $path,
        ], new ProcessOptions(timeoutSec: self::TIMEOUT_SEC, maxStdoutBytes: 1024 * 1024, niceLevel: 10));

        if (!$result->succeeded()) {
            return null;
        }
        $data = json_decode($result->stdout, true, 16);
        if (!is_array($data) || !is_array($data['format'] ?? null)) {
            return null;
        }

        $hasVideo = false;
        $hasAudio = false;
        foreach (is_array($data['streams'] ?? null) ? $data['streams'] : [] as $stream) {
            $type = is_array($stream) ? ($stream['codec_type'] ?? null) : null;
            $hasVideo = $hasVideo || $type === 'video';
            $hasAudio = $hasAudio || $type === 'audio';
        }

        $format = $data['format'];
        $duration = $format['duration'] ?? null;

        return [
            'formatName' => is_string($format['format_name'] ?? null) ? $format['format_name'] : '',
            'durationSec' => is_numeric($duration) ? (float) $duration : null,
            'hasVideo' => $hasVideo,
            'hasAudio' => $hasAudio,
        ];
    }

    /**
     * Whether a probe result matches what the option promised.
     *
     * @param array{formatName: string, durationSec: ?float, hasVideo: bool, hasAudio: bool} $probe
     */
    public static function matches(array $probe, string $ext, ?int $expectedDurationSec): bool
    {
        $formatOk = match ($ext) {
            'mp4', 'm4a' => str_contains($probe['formatName'], 'mp4'),
            'mp3' => $probe['formatName'] === 'mp3',
            default => false,
        };
        $streamsOk = $ext === 'mp4' ? $probe['hasVideo'] : $probe['hasAudio'];

        $durationOk = true;
        if ($expectedDurationSec !== null && $expectedDurationSec > 0 && $probe['durationSec'] !== null) {
            $tolerance = max(5.0, $expectedDurationSec * 0.1);
            $durationOk = abs($probe['durationSec'] - $expectedDurationSec) <= $tolerance;
        }

        return $formatOk && $streamsOk && $durationOk;
    }
}
