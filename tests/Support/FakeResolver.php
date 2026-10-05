<?php

declare(strict_types=1);

namespace ClipHunter\Tests\Support;

use ClipHunter\Security\HostResolver;

/**
 * Deterministic DNS for tests. Unknown hosts resolve to a public documentation-free address.
 */
final class FakeResolver implements HostResolver
{
    /** @var list<string> */
    public array $queried = [];

    /**
     * @param array<string, list<string>> $map
     */
    public function __construct(private array $map = [])
    {
    }

    public function resolve(string $host): array
    {
        $this->queried[] = $host;

        return $this->map[$host] ?? ['142.250.74.46'];
    }
}
