<?php

declare(strict_types=1);

namespace ClipHunter\Watch;

use ClipHunter\Analysis\Analysis;
use ClipHunter\Analysis\AnalyzeService;
use ClipHunter\Config\AppConfig;
use ClipHunter\Exception\ApiException;
use ClipHunter\Exception\ErrorCode;
use ClipHunter\Job\DownloadService;
use ClipHunter\Job\JobPurpose;
use ClipHunter\Media\DownloadOption;
use ClipHunter\Media\OptionKind;
use ClipHunter\RateLimit\RateLimiter;
use ClipHunter\Security\UrlValidator;
use ClipHunter\Support\Clock;
use Psr\Log\LoggerInterface;

/**
 * POST /api/watch/sources use case: turns a user URL into media a watch room can play.
 *
 * YouTube links are parsed locally and played through the official embed (no yt-dlp, no server
 * bandwidth). Everything else, and YouTube in forced file mode, goes through the regular analysis
 * and is prepared by the download worker as a watch job capped at WATCH_MAX_HEIGHT.
 */
final readonly class WatchSourceService
{
    public const RATE_BUCKET = 'watch_sources';
    public const MODE_AUTO = 'auto';
    public const MODE_FILE = 'file';

    public function __construct(
        private UrlValidator $validator,
        private AnalyzeService $analyzer,
        private DownloadService $downloads,
        private WatchMediaService $media,
        private MediaTicket $tickets,
        private RateLimiter $rateLimiter,
        private Clock $clock,
        private AppConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{source: WatchSource, status: string, ticket: string}
     *
     * @throws ApiException
     */
    public function resolve(string $rawUrl, string $mode, string $ipHash): array
    {
        $this->rateLimiter->hit(self::RATE_BUCKET, $ipHash, $this->config->watchSourcesRateLimit);

        $url = $this->validator->validate($rawUrl);

        if ($url->platform->key === 'youtube' && $mode === self::MODE_AUTO) {
            $video = YouTubeId::fromUrl($url->url);
            $source = new WatchSource(WatchSourceKind::YouTube, $video->id, $url->platform->name, null, null, $video->thumbnailUrl(), $video->startSec);
            $status = WatchMediaService::STATUS_READY;
        } else {
            $analysis = $this->analyzer->analyze($url->url, $ipHash);
            $option = $this->pickOption($analysis);
            $job = $this->downloads->create($analysis->id, $option->id, $ipHash, JobPurpose::Watch);
            $source = new WatchSource(WatchSourceKind::File, $job->id, $analysis->platformName, $analysis->title, $analysis->durationSec, $analysis->thumbnailUrl, 0);
            $status = $this->media->status($job->id)['status'];
        }

        $this->logger->info('watch.source', [
            'kind' => $source->kind->value,
            'platform' => $url->platform->key,
            'url_hash' => $url->hash(),
            'media_id' => $source->kind === WatchSourceKind::File ? $source->ref : null,
        ]);

        return ['source' => $source, 'status' => $status, 'ticket' => $this->tickets->issue($source, $this->clock->now())];
    }

    /**
     * The largest video variant that fits WATCH_MAX_HEIGHT: the outgoing bandwidth per viewer is small.
     *
     * @throws ApiException NO_FORMATS
     */
    private function pickOption(Analysis $analysis): DownloadOption
    {
        $best = null;
        foreach ($analysis->options as $option) {
            if ($option->kind === OptionKind::Video && $option->height !== null && $option->height <= $this->config->watchMaxHeight
                && ($best === null || $option->height > (int) $best->height)) {
                $best = $option;
            }
        }

        return $best ?? throw new ApiException(ErrorCode::NoFormats, 'no video option within WATCH_MAX_HEIGHT');
    }
}
