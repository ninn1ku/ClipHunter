<?php

declare(strict_types=1);

namespace ClipHunter\Http;

use ClipHunter\Http\Controller\HealthController;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class Route
{
    /**
     * @param class-string<RequestHandlerInterface> $handler
     */
    public function __construct(
        public string $method,
        public string $pattern,
        public string $handler,
    ) {
    }

    /**
     * The application's route table.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return [
            new self('GET', '/api/health', HealthController::class),
        ];
    }
}
