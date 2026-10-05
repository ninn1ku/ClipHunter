<?php

declare(strict_types=1);

namespace ClipHunter\Http\Controller;

use ClipHunter\Analysis\AnalyzeService;
use ClipHunter\Http\JsonBody;
use ClipHunter\Http\JsonResponder;
use ClipHunter\Http\Middleware\RequestIdMiddleware;
use ClipHunter\Security\UrlValidator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/analyze {"url": "..."}
 */
final readonly class AnalyzeController implements RequestHandlerInterface
{
    public function __construct(
        private AnalyzeService $service,
        private JsonResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = JsonBody::parse($request);
        $url = JsonBody::string($body, 'url', UrlValidator::MAX_LENGTH + 64);

        $ipHash = $request->getAttribute(RequestIdMiddleware::ATTR_IP_HASH);
        $analysis = $this->service->analyze($url, is_string($ipHash) ? $ipHash : '0000000000000000');

        return $this->responder->json($analysis->toPublicArray());
    }
}
