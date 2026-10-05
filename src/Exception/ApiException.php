<?php

declare(strict_types=1);

namespace ClipHunter\Exception;

use RuntimeException;
use Throwable;

/**
 * An expected failure that maps to a structured API error.
 *
 * The public message is shown to users; the internal detail goes to logs only.
 */
class ApiException extends RuntimeException
{
    /**
     * @param array<string, string> $headers extra response headers, e.g. Retry-After
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        string $internalDetail = '',
        private readonly ?string $publicMessage = null,
        public readonly array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($internalDetail !== '' ? $internalDetail : $errorCode->value, 0, $previous);
    }

    public function publicMessage(): string
    {
        return $this->publicMessage ?? $this->errorCode->defaultMessage();
    }
}
