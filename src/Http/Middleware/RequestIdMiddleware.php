<?php

declare(strict_types=1);

namespace ClipHunter\Http\Middleware;

use ClipHunter\Http\ClientIp;
use ClipHunter\Http\RequestContext;
use ClipHunter\Security\IpHasher;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Assigns a request id and the pseudonymised client IP, exposes both to handlers and logs.
 */
final readonly class RequestIdMiddleware implements MiddlewareInterface
{
    public const ATTR_REQUEST_ID = 'request_id';
    public const ATTR_CLIENT_IP = 'client_ip';
    public const ATTR_IP_HASH = 'ip_hash';

    public function __construct(
        private RequestContext $context,
        private IpHasher $ipHasher,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestId = bin2hex(random_bytes(8));
        $ip = ClientIp::fromRequest($request);
        $ipHash = $this->ipHasher->hash($ip);

        $this->context->requestId = $requestId;
        $this->context->ipHash = $ipHash;

        $request = $request
            ->withAttribute(self::ATTR_REQUEST_ID, $requestId)
            ->withAttribute(self::ATTR_CLIENT_IP, $ip)
            ->withAttribute(self::ATTR_IP_HASH, $ipHash);

        return $handler->handle($request)->withHeader('X-Request-Id', $requestId);
    }
}
