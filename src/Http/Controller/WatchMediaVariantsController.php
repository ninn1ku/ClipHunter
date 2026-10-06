<?php

declare(strict_types=1);

namespace ClipHunter\Http\Controller;

use ClipHunter\Http\JsonBody;
use ClipHunter\Http\JsonResponder;
use ClipHunter\Http\Middleware\RequestIdMiddleware;
use ClipHunter\Watch\WatchMediaService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET  /api/watch/media/{id}/variants — the quality variants of a room video and their state.
 * POST /api/watch/media/{id}/variants {"optionId": "v480"} — prepare one for this member (202).
 */
final readonly class WatchMediaVariantsController implements RequestHandlerInterface
{
    public function __construct(
        private WatchMediaService $media,
        private JsonResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $id = $request->getAttribute('id');
        $id = is_string($id) ? $id : '';

        if ($request->getMethod() === 'POST') {
            $optionId = JsonBody::string(JsonBody::parse($request), 'optionId', 16);
            $ipHash = $request->getAttribute(RequestIdMiddleware::ATTR_IP_HASH);

            return $this->responder->json(
                $this->media->prepareVariant($id, $optionId, is_string($ipHash) ? $ipHash : '0000000000000000'),
                202,
            );
        }

        return $this->responder->json(['variants' => $this->media->variants($id)]);
    }
}
