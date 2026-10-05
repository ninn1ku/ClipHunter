<?php

declare(strict_types=1);

namespace ClipHunter\Http\Controller;

use ClipHunter\Config\AppConfig;
use ClipHunter\Job\DownloadService;
use ClipHunter\Security\FilenameSanitizer;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/downloads/{id}/file
 *
 * Production: PHP only authorises; Nginx streams the file via X-Accel-Redirect from an
 * `internal` location (efficient, supports Range/resume, keeps PHP-FPM workers free).
 * Development (no Nginx): PHP streams the file itself.
 */
final readonly class DownloadFileController implements RequestHandlerInterface
{
    public const X_ACCEL_PREFIX = '/_files/';

    public function __construct(
        private DownloadService $downloads,
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
        private AppConfig $config,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $id = $request->getAttribute('id');
        $file = $this->downloads->file(is_string($id) ? $id : '');

        $response = $this->responses->createResponse(200)
            // Type comes from the option we produced and verified with ffprobe, never from metadata.
            ->withHeader('Content-Type', $file['mime'])
            ->withHeader('Content-Disposition', FilenameSanitizer::contentDisposition($file['name']))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store');

        if ($this->config->fileDelivery === AppConfig::FILE_DELIVERY_XACCEL) {
            return $response->withHeader('X-Accel-Redirect', self::X_ACCEL_PREFIX . $file['internalPath']);
        }

        if ($file['size'] !== null) {
            $response = $response->withHeader('Content-Length', (string) $file['size']);
        }

        return $response->withBody($this->streams->createStreamFromFile($file['path'], 'rb'));
    }
}
