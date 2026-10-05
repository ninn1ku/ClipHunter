<?php

declare(strict_types=1);

namespace ClipHunter\Security;

interface HostResolver
{
    /**
     * @return list<string> all A and AAAA addresses of the host; empty when it does not resolve
     */
    public function resolve(string $host): array;
}
