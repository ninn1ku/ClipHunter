<?php

declare(strict_types=1);

namespace ClipHunter\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Runs PSR-15 middleware in order, then hands off to the final handler.
 */
final readonly class MiddlewarePipeline implements RequestHandlerInterface
{
    /**
     * @param list<MiddlewareInterface> $middleware outermost first
     */
    public function __construct(
        private array $middleware,
        private RequestHandlerInterface $handler,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->middleware === []) {
            return $this->handler->handle($request);
        }

        $first = $this->middleware[0];
        $rest = new self(array_slice($this->middleware, 1), $this->handler);

        return $first->process($request, $rest);
    }
}
