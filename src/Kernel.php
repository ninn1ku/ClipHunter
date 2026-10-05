<?php

declare(strict_types=1);

namespace ClipHunter;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * HTTP entry point: PSR-7 request in, PSR-7 response out.
 */
final readonly class Kernel implements RequestHandlerInterface
{
    public function __construct(private RequestHandlerInterface $pipeline)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->pipeline->handle($request);
    }
}
