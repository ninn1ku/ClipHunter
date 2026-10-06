<?php

declare(strict_types=1);

namespace ClipHunter\AniLiberty;

/**
 * One episode of an AniLiberty release, sanitized. streams maps a height (480, 720, 1080) to the
 * https URL of its HLS playlist on their CDN; the browser plays it from there.
 */
final readonly class Episode
{
    /**
     * @param array<int, string> $streams height => playlist URL, highest first
     * @param ?array{start: int, stop: int} $opening
     * @param ?array{start: int, stop: int} $ending
     */
    public function __construct(
        public string $id,
        public int $releaseId,
        public int|float $ordinal,
        public ?string $name,
        public ?int $durationSec,
        public ?string $previewUrl,
        public array $streams,
        public ?array $opening = null,
        public ?array $ending = null,
    ) {
    }

    /** "12" or "12.5": how the episode is numbered in titles and pickers. */
    public function label(): string
    {
        return is_int($this->ordinal) || floor($this->ordinal) === $this->ordinal
            ? (string) (int) $this->ordinal
            : rtrim(rtrim(number_format($this->ordinal, 2, '.', ''), '0'), '.');
    }

    /**
     * For episode lists: no stream URLs.
     *
     * @return array{id: string, label: string, name: ?string, durationSec: ?int, previewUrl: ?string, playable: bool}
     */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label(),
            'name' => $this->name,
            'durationSec' => $this->durationSec,
            'previewUrl' => $this->previewUrl,
            'playable' => $this->streams !== [],
        ];
    }

    /**
     * For the player: GET /api/watch/aniliberty/episodes/{id}.
     *
     * @return array<string, mixed>
     */
    public function toPlayerArray(): array
    {
        $streams = [];
        foreach ($this->streams as $height => $url) {
            $streams[] = ['height' => $height, 'label' => $height . 'p', 'url' => $url];
        }

        return [
            'id' => $this->id,
            'releaseId' => $this->releaseId,
            'label' => $this->label(),
            'name' => $this->name,
            'durationSec' => $this->durationSec,
            'streams' => $streams,
            'opening' => $this->opening,
            'ending' => $this->ending,
        ];
    }
}
