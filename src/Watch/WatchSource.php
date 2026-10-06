<?php

declare(strict_types=1);

namespace ClipHunter\Watch;

/**
 * Media a watch room can play, resolved and vetted by PHP. Text fields are already sanitized.
 *
 * ref is the YouTube video id (kind youtube) or the id of the watch job preparing the file (kind file).
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

        return $this->kind === WatchSourceKind::YouTube
            ? ['videoId' => $this->ref] + $data
            : ['mediaId' => $this->ref] + $data;
    }
}
