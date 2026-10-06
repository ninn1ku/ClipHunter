<?php

declare(strict_types=1);

namespace ClipHunter\Media;

use ClipHunter\Config\ConfigException;

/**
 * The allowlist of supported platforms, loaded from config/platforms.php.
 */
final readonly class PlatformRegistry
{
    /**
     * @param list<Platform> $platforms
     */
    public function __construct(private array $platforms)
    {
    }

    public static function fromFile(string $path): self
    {
        $data = require $path;
        if (!is_array($data)) {
            throw new ConfigException('Platform config must return an array.');
        }

        $platforms = [];
        foreach ($data as $key => $def) {
            if (!is_string($key) || !is_array($def)) {
                throw new ConfigException('Invalid platform entry.');
            }
            $name = $def['name'] ?? null;
            if (!is_string($name) || $name === '') {
                throw new ConfigException(sprintf('Platform "%s" needs a name.', $key));
            }
            $platforms[] = new Platform(
                $key,
                $name,
                self::nonEmptyStrings($def['domains'] ?? null, $key, 'domains', '~^[a-z0-9-]+(\.[a-z0-9-]+)+$~'),
                // An empty list marks a platform played only in the browser (never handed to yt-dlp).
                ($def['extractors'] ?? null) === [] ? [] : self::nonEmptyStrings($def['extractors'] ?? null, $key, 'extractors', '~^[A-Za-z0-9.:_]+$~'),
            );
        }

        return new self($platforms);
    }

    public function forHost(string $host): ?Platform
    {
        foreach ($this->platforms as $platform) {
            if ($platform->matchesHost($host)) {
                return $platform;
            }
        }

        return null;
    }

    public function get(string $key): ?Platform
    {
        foreach ($this->platforms as $platform) {
            if ($platform->key === $key) {
                return $platform;
            }
        }

        return null;
    }

    /**
     * @return list<Platform>
     */
    public function all(): array
    {
        return $this->platforms;
    }

    /**
     * @return non-empty-list<string>
     */
    private static function nonEmptyStrings(mixed $value, string $key, string $field, string $pattern): array
    {
        if (!is_array($value) || $value === []) {
            throw new ConfigException(sprintf('Platform "%s" needs non-empty %s.', $key, $field));
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item) || preg_match($pattern, $item) !== 1) {
                throw new ConfigException(sprintf('Platform "%s" has an invalid %s entry.', $key, $field));
            }
            $result[] = $item;
        }

        return $result;
    }
}
