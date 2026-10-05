<?php

declare(strict_types=1);

namespace ClipHunter\Http\Controller;

use ClipHunter\Config\AppConfig;
use ClipHunter\Http\ClientIp;
use ClipHunter\Http\JsonResponder;
use ClipHunter\Http\Middleware\RequestIdMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/health — liveness for everyone, diagnostic details only for loopback callers.
 */
final readonly class HealthController implements RequestHandlerInterface
{
    public function __construct(
        private JsonResponder $responder,
        private AppConfig $config,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $ip = $request->getAttribute(RequestIdMiddleware::ATTR_CLIENT_IP);
        if (!is_string($ip) || !ClientIp::isLoopback($ip)) {
            return $this->responder->json(['status' => 'ok']);
        }

        $storageWritable = is_dir($this->config->storagePath) && is_writable($this->config->storagePath);
        $freeBytes = $storageWritable ? @disk_free_space($this->config->storagePath) : false;

        return $this->responder->json([
            'status' => $storageWritable ? 'ok' : 'degraded',
            'env' => $this->config->env,
            'php' => PHP_VERSION,
            'checks' => [
                'storageWritable' => $storageWritable,
                'diskFreeMb' => $freeBytes === false ? null : (int) floor($freeBytes / 1024 / 1024),
            ],
        ]);
    }
}
