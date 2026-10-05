<?php

declare(strict_types=1);

namespace ClipHunter;

use Closure;
use LogicException;

/**
 * Minimal explicit service container: one factory per service id, shared instances.
 *
 * Wiring lives in {@see Services}; there is no autowiring or reflection magic.
 */
final class Container
{
    /** @var array<string, Closure(self): object> */
    private array $factories = [];

    /** @var array<string, object> */
    private array $instances = [];

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     * @param Closure(self): T $factory
     */
    public function set(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    public function get(string $id): object
    {
        if (!isset($this->instances[$id])) {
            $factory = $this->factories[$id] ?? throw new LogicException(sprintf('Service "%s" is not registered.', $id));
            $this->instances[$id] = $factory($this);
        }

        $instance = $this->instances[$id];
        if (!$instance instanceof $id) {
            throw new LogicException(sprintf('Service "%s" has an unexpected type.', $id));
        }

        return $instance;
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]);
    }
}
