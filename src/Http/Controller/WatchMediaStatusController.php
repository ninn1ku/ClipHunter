<?php

declare(strict_types=1);

namespace ClipHunter\Http\Controller;

use ClipHunter\Http\JsonResponder;
use ClipHunter\Watch\WatchMediaService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/watch/media/{id} — preparation status of a watch-room file (polled by room members).
 */
final readonly class WatchMediaStatusController implements RequestHandlerInterface
{
    public function __construct(
        private WatchMediaService $media,
        private JsonResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $id = $request->getAttribute('id');

        return $this->responder->json($this->media->status(is_string($id) ? $id : ''));
    }
}
