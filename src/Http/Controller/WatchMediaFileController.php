<?php

declare(strict_types=1);

namespace ClipHunter\Http\Controller;

use ClipHunter\Config\AppConfig;
use ClipHunter\Http\ByteRange;
use ClipHunter\Http\FileRangeStream;
use ClipHunter\Watch\WatchMediaService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/watch/media/{id}/file — the prepared file, inline, for a <video> element.
 *
 * Production: Nginx streams it via X-Accel-Redirect (with Range support). Development: PHP streams
 * it and honours a single Range, without which seeking (and therefore syncing) would not work.
 */
final readonly class WatchMediaFileController implements RequestHandlerInterface
{
    public function __construct(
        private WatchMediaService $media,
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
        private AppConfig $config,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $id = $request->getAttribute('id');
        $file = $this->media->file(is_string($id) ? $id : '');

        $response = $this->responses->createResponse(200)
            // Type comes from the option we produced and verified with ffprobe, never from metadata.
            ->withHeader('Content-Type', $file['mime'])
            ->withHeader('Content-Disposition', 'inline')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, max-age=3600');

        if ($this->config->fileDelivery === AppConfig::FILE_DELIVERY_XACCEL) {
            return $response->withHeader('X-Accel-Redirect', DownloadFileController::X_ACCEL_PREFIX . $file['internalPath']);
        }

        $size = (int) filesize($file['path']);
        $response = $response->withHeader('Accept-Ranges', 'bytes');
        $range = ByteRange::parse($request->getHeaderLine('Range'), $size);

        if ($range === null) {
            return $response
                ->withHeader('Content-Length', (string) $size)
                ->withBody($this->streams->createStreamFromFile($file['path'], 'rb'));
        }
        if (!$range->satisfiable) {
            return $response->withStatus(416)->withHeader('Content-Range', $range->contentRange($size))->withHeader('Content-Length', '0');
        }

        return $response
            ->withStatus(206)
            ->withHeader('Content-Range', $range->contentRange($size))
            ->withHeader('Content-Length', (string) $range->length())
            ->withBody(new FileRangeStream($file['path'], $range->start, $range->length()));
    }
}
