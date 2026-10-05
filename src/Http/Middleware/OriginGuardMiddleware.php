<?php

declare(strict_types=1);

namespace ClipHunter\Http\Middleware;

use ClipHunter\Exception\ApiException;
use ClipHunter\Exception\ErrorCode;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Rejects state-changing requests initiated by other websites.
 *
 * There are no cookies or sessions, so this is not about classic CSRF; it stops third-party
 * pages from using visitors' browsers to drive our API. Together with the mandatory JSON
 * content type (which forces a CORS preflight we never approve) this closes browser-based abuse.
 * Non-browser clients are handled by rate limits.
 */
final readonly class OriginGuardMiddleware implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private string $appUrl)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!in_array($request->getMethod(), self::SAFE_METHODS, true)) {
            $fetchSite = strtolower($request->getHeaderLine('Sec-Fetch-Site'));
            if ($fetchSite !== '' && $fetchSite !== 'same-origin' && $fetchSite !== 'none') {
                throw new ApiException(ErrorCode::ForbiddenOrigin, 'sec-fetch-site=' . substr($fetchSite, 0, 32));
            }

            $origin = $request->getHeaderLine('Origin');
            if ($origin !== '' && !hash_equals(self::origin($this->appUrl), strtolower(rtrim($origin, '/')))) {
                throw new ApiException(ErrorCode::ForbiddenOrigin, 'origin mismatch');
            }
        }

        return $handler->handle($request);
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . '://' . $host . $port;
    }
}
