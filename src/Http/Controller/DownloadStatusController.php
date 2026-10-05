<?php

declare(strict_types=1);

namespace ClipHunter\Http\Controller;

use ClipHunter\Http\JsonResponder;
use ClipHunter\Job\DownloadService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/downloads/{id} — job status and progress (polled by the frontend).
 * DELETE /api/downloads/{id} — cancel, or delete the finished file early.
 */
final readonly class DownloadStatusController implements RequestHandlerInterface
{
    public function __construct(
        private DownloadService $downloads,
        private JsonResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $id = $request->getAttribute('id');
        $id = is_string($id) ? $id : '';

        if ($request->getMethod() === 'DELETE') {
            $this->downloads->cancel($id);

            return $this->responder->noContent();
        }

        return $this->responder->json($this->downloads->status($id));
    }
}
