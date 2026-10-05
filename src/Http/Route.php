<?php

declare(strict_types=1);

namespace ClipHunter\Http;

use ClipHunter\Http\Controller\AnalyzeController;
use ClipHunter\Http\Controller\DownloadController;
use ClipHunter\Http\Controller\DownloadFileController;
use ClipHunter\Http\Controller\DownloadStatusController;
use ClipHunter\Http\Controller\HealthController;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class Route
{
    /**
     * @param class-string<RequestHandlerInterface> $handler
     */
    public function __construct(
        public string $method,
        public string $pattern,
        public string $handler,
    ) {
    }

    /**
     * The application's route table.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        $job = '/api/downloads/{id:[a-f0-9]{32}}';

        return [
            new self('GET', '/api/health', HealthController::class),
            new self('POST', '/api/analyze', AnalyzeController::class),
            new self('POST', '/api/downloads', DownloadController::class),
            new self('GET', $job, DownloadStatusController::class),
            new self('DELETE', $job, DownloadStatusController::class),
            new self('GET', $job . '/file', DownloadFileController::class),
        ];
    }
}
