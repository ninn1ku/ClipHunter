<?php

declare(strict_types=1);

namespace ClipHunter\Analysis;

use ClipHunter\Config\AppConfig;
use ClipHunter\Exception\ApiException;
use ClipHunter\Exception\ErrorCode;
use ClipHunter\Media\OptionBuilder;
use ClipHunter\Media\YtDlpClient;
use ClipHunter\RateLimit\RateLimiter;
use ClipHunter\RateLimit\Semaphore;
use ClipHunter\Security\UrlValidator;
use ClipHunter\Support\Clock;
use ClipHunter\Support\Ids;
use Psr\Log\LoggerInterface;

/**
 * POST /api/analyze use case: rate limit → validate URL → bounded yt-dlp run → store result.
 */
final readonly class AnalyzeService
{
    public const RATE_BUCKET = 'analyze';
    private const SEMAPHORE = 'analyze';

    public function __construct(
        private UrlValidator $validator,
        private YtDlpClient $ytDlp,
        private OptionBuilder $optionBuilder,
        private AnalysisRepository $analyses,
        private RateLimiter $rateLimiter,
        private Semaphore $semaphore,
        private Clock $clock,
        private AppConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws ApiException
     */
    public function analyze(string $rawUrl, string $ipHash): Analysis
    {
        // Counted before validation: invalid input also costs DNS lookups and log lines.
        $this->rateLimiter->hit(self::RATE_BUCKET, $ipHash, $this->config->analyzeRateLimit);

        $url = $this->validator->validate($rawUrl);
        if (!$url->platform->isYtDlpSource()) {
            throw new ApiException(ErrorCode::UnsupportedSource, 'platform is played in the browser only');
        }

        $slot = $this->semaphore->tryAcquire(self::SEMAPHORE, $this->config->maxConcurrentAnalyze);
        if ($slot === null) {
            throw new ApiException(ErrorCode::ServerBusy, 'all analyze slots busy', headers: ['Retry-After' => '10']);
        }

        try {
            $media = $this->ytDlp->analyze($url);
        } finally {
            $slot->release();
        }

        $options = $this->optionBuilder->build($media);
        if ($options === []) {
            throw new ApiException(ErrorCode::NoFormats, 'no options within limits');
        }

        $now = $this->clock->now();
        $analysis = new Analysis(
            id: Ids::generate(),
            url: $url->url,
            platformKey: $url->platform->key,
            platformName: $url->platform->name,
            title: $media->title,
            uploader: $media->uploader,
            durationSec: $media->durationSec,
            thumbnailUrl: $media->thumbnailUrl,
            options: $options,
            createdAt: $now,
            expiresAt: $now + $this->config->analysisTtlSec,
        );
        $this->analyses->save($analysis);

        $this->logger->info('analyze.completed', [
            'analysis_id' => $analysis->id,
            'platform' => $url->platform->key,
            'url_hash' => $url->hash(),
            'extractor' => $media->extractor,
            'duration_sec' => $media->durationSec,
            'options' => count($options),
        ]);

        return $analysis;
    }
}
