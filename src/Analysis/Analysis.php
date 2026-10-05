<?php

declare(strict_types=1);

namespace ClipHunter\Analysis;

use ClipHunter\Media\DownloadOption;
use InvalidArgumentException;

/**
 * The stored result of analysing one URL. A download request references it by id, so the
 * client never resubmits the URL or a raw yt-dlp format string.
 */
final readonly class Analysis
{
    /**
     * @param list<DownloadOption> $options
     */
    public function __construct(
        public string $id,
        public string $url,
        public string $platformKey,
        public string $platformName,
        public string $title,
        public ?string $uploader,
        public ?int $durationSec,
        public ?string $thumbnailUrl,
        public array $options,
        public int $createdAt,
        public int $expiresAt,
    ) {
    }

    public function option(string $id): ?DownloadOption
    {
        foreach ($this->options as $option) {
            if ($option->id === $id) {
                return $option;
            }
        }

        return null;
    }

    /**
     * The API representation (POST /api/analyze).
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'analysisId' => $this->id,
            'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', $this->expiresAt),
            'video' => [
                'platform' => $this->platformName,
                'title' => $this->title,
                'uploader' => $this->uploader,
                'durationSec' => $this->durationSec,
                'thumbnailUrl' => $this->thumbnailUrl,
                'webpageUrl' => $this->url,
            ],
            'options' => array_map(static fn (DownloadOption $o): array => $o->toArray(), $this->options),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'platformKey' => $this->platformKey,
            'platformName' => $this->platformName,
            'title' => $this->title,
            'uploader' => $this->uploader,
            'durationSec' => $this->durationSec,
            'thumbnailUrl' => $this->thumbnailUrl,
            'options' => array_map(static fn (DownloadOption $o): array => $o->toArray(), $this->options),
            'createdAt' => $this->createdAt,
            'expiresAt' => $this->expiresAt,
        ];
    }

    /**
     * @param array<array-key, mixed> $d
     */
    public static function fromArray(array $d): self
    {
        $str = static fn (string $k): string => is_string($d[$k] ?? null) ? $d[$k] : throw new InvalidArgumentException('Malformed analysis: ' . $k);
        $nullableStr = static fn (string $k): ?string => is_string($d[$k] ?? null) ? $d[$k] : null;
        $int = static fn (string $k): int => is_int($d[$k] ?? null) ? $d[$k] : throw new InvalidArgumentException('Malformed analysis: ' . $k);

        $rawOptions = $d['options'] ?? null;
        if (!is_array($rawOptions)) {
            throw new InvalidArgumentException('Malformed analysis: options');
        }
        $options = [];
        foreach ($rawOptions as $o) {
            if (!is_array($o)) {
                throw new InvalidArgumentException('Malformed analysis: option');
            }
            $options[] = DownloadOption::fromArray($o);
        }

        return new self(
            id: $str('id'),
            url: $str('url'),
            platformKey: $str('platformKey'),
            platformName: $str('platformName'),
            title: $str('title'),
            uploader: $nullableStr('uploader'),
            durationSec: is_int($d['durationSec'] ?? null) ? $d['durationSec'] : null,
            thumbnailUrl: $nullableStr('thumbnailUrl'),
            options: $options,
            createdAt: $int('createdAt'),
            expiresAt: $int('expiresAt'),
        );
    }
}
