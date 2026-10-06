<?php

declare(strict_types=1);

namespace ClipHunter\Http\Controller;

use ClipHunter\Exception\ApiException;
use ClipHunter\Exception\ErrorCode;
use ClipHunter\Http\JsonBody;
use ClipHunter\Http\JsonResponder;
use ClipHunter\Http\Middleware\RequestIdMiddleware;
use ClipHunter\Security\UrlValidator;
use ClipHunter\Watch\WatchSourceKind;
use ClipHunter\Watch\WatchSourceService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/watch/sources {"url": "...", "mode": "auto" | "file"}
 *
 * 200 for an embeddable source (YouTube), 202 when a file is being prepared.
 */
final readonly class WatchSourceController implements RequestHandlerInterface
{
    public function __construct(
        private WatchSourceService $service,
        private JsonResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = JsonBody::parse($request);
        $url = JsonBody::string($body, 'url', UrlValidator::MAX_LENGTH + 64);
        $mode = $body['mode'] ?? WatchSourceService::MODE_AUTO;
        if (!in_array($mode, [WatchSourceService::MODE_AUTO, WatchSourceService::MODE_FILE], true)) {
            throw new ApiException(ErrorCode::InvalidRequest, 'bad mode', 'Поле «mode» заполнено неверно.');
        }

        $ipHash = $request->getAttribute(RequestIdMiddleware::ATTR_IP_HASH);
        $result = $this->service->resolve($url, $mode, is_string($ipHash) ? $ipHash : '0000000000000000');
        $source = $result['source'];

        return $this->responder->json(
            ['source' => $source->toPublicArray($result['status']), 'ticket' => $result['ticket']],
            $source->kind === WatchSourceKind::File ? 202 : 200,
        );
    }
}
