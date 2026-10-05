<?php

declare(strict_types=1);

namespace ClipHunter\Http;

/**
 * Per-request correlation data shared with the logger.
 *
 * PHP-FPM serves one request per process lifecycle, so a mutable holder is safe here.
 */
final class RequestContext
{
    public ?string $requestId = null;
    public ?string $ipHash = null;

    public function reset(): void
    {
        $this->requestId = null;
        $this->ipHash = null;
    }
}
