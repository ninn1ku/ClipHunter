<?php

declare(strict_types=1);

namespace ClipHunter\Http\Controller;

use ClipHunter\AniLiberty\AniLibertyService;
use ClipHunter\Exception\ApiException;
use ClipHunter\Exception\ErrorCode;
use ClipHunter\Http\JsonResponder;
use ClipHunter\Http\Middleware\RequestIdMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/watch/aniliberty/search?q=…           → {"results": [release, …]}
 * GET /api/watch/aniliberty/releases/{releaseId} → {"release": {…, "episodes": [summary, …]}}
 * GET /api/watch/aniliberty/episodes/{episodeId} → {"episode": {…, "streams": [{height, label, url}]}}
 */
final readonly class AniLibertyController implements RequestHandlerInterface
{
    public function __construct(
        private AniLibertyService $service,
        private JsonResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $ipHash = $request->getAttribute(RequestIdMiddleware::ATTR_IP_HASH);
        $ipHash = is_string($ipHash) ? $ipHash : '0000000000000000';
        $releaseId = $request->getAttribute('releaseId');
        $episodeId = $request->getAttribute('episodeId');

        if (is_string($releaseId)) {
            return $this->responder->json(['release' => $this->service->release((int) $releaseId, $ipHash)]);
        }
        if (is_string($episodeId)) {
            return $this->responder->json(['episode' => $this->service->episode($episodeId, $ipHash)]);
        }

        $query = $request->getQueryParams()['q'] ?? null;
        if (!is_string($query)) {
            throw new ApiException(ErrorCode::InvalidRequest, 'missing q', 'Введите название аниме.');
        }

        return $this->responder->json(['results' => $this->service->search($query, $ipHash)]);
    }
}
