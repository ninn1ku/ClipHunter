<?php

declare(strict_types=1);

namespace ClipHunter\Media;

use InvalidArgumentException;

/**
 * A download variant offered to the user. The client only ever sends back the id;
 * the yt-dlp arguments are derived server-side by {@see FormatArgs}.
 */
final readonly class DownloadOption
{
    public const ID_PATTERN = '~^(v(2160|1440|1080|720|480|360|240|144)|a-m4a|a-mp3)$~D';

    public function __construct(
        public string $id,
        public OptionKind $kind,
        public string $label,
        public string $container,
        public ?int $height,
        public ?int $sizeBytes,
        public bool $sizeIsApprox,
    ) {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException('Invalid option id.');
        }
    }

    /**
     * @return array{id: string, kind: string, label: string, container: string, height: ?int, sizeBytes: ?int, sizeIsApprox: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'label' => $this->label,
            'container' => $this->container,
            'height' => $this->height,
            'sizeBytes' => $this->sizeBytes,
            'sizeIsApprox' => $this->sizeIsApprox,
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $id = $data['id'] ?? null;
        $kind = OptionKind::tryFrom(is_string($data['kind'] ?? null) ? $data['kind'] : '');
        $label = $data['label'] ?? null;
        $container = $data['container'] ?? null;
        $height = $data['height'] ?? null;
        $size = $data['sizeBytes'] ?? null;
        $approx = $data['sizeIsApprox'] ?? null;

        if (!is_string($id) || $kind === null || !is_string($label) || !is_string($container)
            || !(is_int($height) || $height === null) || !(is_int($size) || $size === null) || !is_bool($approx)) {
            throw new InvalidArgumentException('Malformed stored option.');
        }

        return new self($id, $kind, $label, $container, $height, $size, $approx);
    }
}
