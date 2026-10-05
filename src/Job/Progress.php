<?php

declare(strict_types=1);

namespace ClipHunter\Job;

final readonly class Progress
{
    public function __construct(
        public ?float $percent,
        public int $downloadedBytes,
        public ?int $totalBytes,
        public ?int $speedBps,
        public ?int $etaSec,
    ) {
    }

    /**
     * @return array{percent: ?float, downloadedBytes: int, totalBytes: ?int, speedBps: ?int, etaSec: ?int}
     */
    public function toArray(): array
    {
        return [
            'percent' => $this->percent,
            'downloadedBytes' => $this->downloadedBytes,
            'totalBytes' => $this->totalBytes,
            'speedBps' => $this->speedBps,
            'etaSec' => $this->etaSec,
        ];
    }

    /**
     * @param array<array-key, mixed> $d
     */
    public static function fromArray(array $d): self
    {
        $int = static fn (string $k): ?int => is_int($d[$k] ?? null) ? $d[$k] : null;
        $percent = $d['percent'] ?? null;

        return new self(
            is_int($percent) || is_float($percent) ? (float) $percent : null,
            $int('downloadedBytes') ?? 0,
            $int('totalBytes'),
            $int('speedBps'),
            $int('etaSec'),
        );
    }
}
