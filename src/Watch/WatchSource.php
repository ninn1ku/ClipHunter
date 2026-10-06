<?php

declare(strict_types=1);

namespace ClipHunter\Watch;

/**
 * Media a watch room can play, resolved and vetted by PHP. Text fields are already sanitized.
 *
 * ref identifies the media for its kind (see WatchSourceKind::refPattern()): a YouTube video id, a VK
 * owner_video pair, a Twitch video:<id> or channel:<login>, an AniLiberty release:episode, or the id of
 * the watch job preparing the file (kind file).
 */
final readonly class WatchSource
{
    public function __construct(
        public WatchSourceKind $kind,
        public string $ref,
        public string $platform,
        public ?string $title,
        public ?int $durationSec,
        public ?string $thumbnailUrl,
        public int $startSec,
    ) {
    }

    /**
     * The API representation (POST /api/watch/sources).
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(string $status): array
    {
        $data = [
            'kind' => $this->kind->value,
            'platform' => $this->platform,
            'title' => $this->title,
            'durationSec' => $this->durationSec,
            'thumbnailUrl' => $this->thumbnailUrl,
            'startSec' => $this->startSec,
            'status' => $status,
        ];

        return match ($this->kind) {
            WatchSourceKind::File => ['mediaId' => $this->ref] + $data,
            WatchSourceKind::YouTube => ['videoId' => $this->ref, 'ref' => $this->ref] + $data,
            default => ['ref' => $this->ref] + $data,
        };
    }
}
