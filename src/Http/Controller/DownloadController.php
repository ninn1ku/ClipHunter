<?php

declare(strict_types=1);

namespace ClipHunter\Http\Controller;

use ClipHunter\Http\JsonBody;
use ClipHunter\Http\JsonResponder;
use ClipHunter\Http\Middleware\RequestIdMiddleware;
use ClipHunter\Job\DownloadService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/downloads {"analysisId": "...", "optionId": "v1080"}
 */
final readonly class DownloadController implements RequestHandlerInterface
{
    public function __construct(
        private DownloadService $downloads,
        private JsonResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = JsonBody::parse($request);
        $analysisId = JsonBody::string($body, 'analysisId', 64);
        $optionId = JsonBody::string($body, 'optionId', 16);
        $ipHash = $request->getAttribute(RequestIdMiddleware::ATTR_IP_HASH);

        $job = $this->downloads->create($analysisId, $optionId, is_string($ipHash) ? $ipHash : '0000000000000000');
        $status = $this->downloads->status($job->id);

        return $this->responder->json([
            'jobId' => $job->id,
            'status' => $status['status'],
            'queuePosition' => $status['queuePosition'],
            'statusUrl' => '/api/downloads/' . $job->id,
        ], 202, ['Location' => '/api/downloads/' . $job->id]);
    }
}
