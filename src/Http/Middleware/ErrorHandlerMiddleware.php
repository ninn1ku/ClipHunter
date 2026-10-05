<?php

declare(strict_types=1);

namespace ClipHunter\Http\Middleware;

use ClipHunter\Exception\ApiException;
use ClipHunter\Exception\ErrorCode;
use ClipHunter\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Converts every exception into the structured JSON error format.
 *
 * Clients never see stack traces, exception messages or filesystem paths; those go to logs only.
 */
final readonly class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private JsonResponder $responder,
        private LoggerInterface $logger,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (ApiException $e) {
            $status = $e->errorCode->httpStatus();
            $this->logger->log($status >= 500 ? 'error' : 'notice', 'http.error', [
                'code' => $e->errorCode->value,
                'detail' => $e->getMessage(),
                'previous' => $e->getPrevious() !== null ? $e->getPrevious()::class : null,
            ]);

            return $this->responder->error($e->errorCode, $e->publicMessage(), $e->headers);
        } catch (Throwable $e) {
            $this->logger->error('http.unhandled_exception', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'location' => $e->getFile() . ':' . $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->responder->error(ErrorCode::InternalError);
        }
    }
}
