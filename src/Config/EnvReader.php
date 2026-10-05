<?php

declare(strict_types=1);

namespace ClipHunter\Config;

/**
 * Strict accessors over a raw environment array. Every failure names the variable.
 */
final readonly class EnvReader
{
    /**
     * @param array<mixed> $env
     */
    public function __construct(private array $env)
    {
    }

    public function string(string $name, string $default): string
    {
        $value = $this->env[$name] ?? null;
        if ($value === null) {
            return $default;
        }
        if (!is_string($value)) {
            throw new ConfigException(sprintf('%s must be a string.', $name));
        }
        $value = trim($value);

        return $value === '' ? $default : $value;
    }

    public function int(string $name, int $default, int $min, int $max): int
    {
        $raw = $this->string($name, (string) $default);
        if (preg_match('~^-?\d{1,18}$~', $raw) !== 1) {
            throw new ConfigException(sprintf('%s must be an integer, got "%s".', $name, $raw));
        }
        $value = (int) $raw;
        if ($value < $min || $value > $max) {
            throw new ConfigException(sprintf('%s must be between %d and %d, got %d.', $name, $min, $max, $value));
        }

        return $value;
    }

    public function bool(string $name, bool $default): bool
    {
        $raw = strtolower($this->string($name, $default ? 'true' : 'false'));

        return match ($raw) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => throw new ConfigException(sprintf('%s must be a boolean, got "%s".', $name, $raw)),
        };
    }

    /**
     * @param list<string> $allowed
     */
    public function choice(string $name, array $allowed, string $default): string
    {
        $value = strtolower($this->string($name, $default));
        if (!in_array($value, $allowed, true)) {
            throw new ConfigException(sprintf('%s must be one of: %s.', $name, implode(', ', $allowed)));
        }

        return $value;
    }
}
