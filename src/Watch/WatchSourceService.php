<?php

declare(strict_types=1);

namespace ClipHunter\Watch;

use ClipHunter\Analysis\Analysis;
use ClipHunter\Analysis\AnalyzeService;
use ClipHunter\Config\AppConfig;
use ClipHunter\Exception\ApiException;
use ClipHunter\Exception\ErrorCode;
use ClipHunter\Job\DownloadService;
use ClipHunter\Media\OptionKind;
use ClipHunter\RateLimit\RateLimiter;
use ClipHunter\Security\UrlValidator;
use ClipHunter\Security\ValidatedUrl;
use ClipHunter\Support\Clock;
use Psr\Log\LoggerInterface;

/**
 * POST /api/watch/sources use case: turns a user URL into media a watch room can play.
 *
 * Links the platforms' official players can show (YouTube, VK Video) are parsed locally and played
 * in the browser (no yt-dlp, no server bandwidth). Everything else, and any link in forced file
 * mode, goes through the regular analysis and is prepared by the download worker as a watch job
 * capped at WATCH_MAX_HEIGHT.
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

        $source = $mode === self::MODE_AUTO ? $this->embedded($url) : null;
        if ($source !== null) {
            $status = WatchMediaService::STATUS_READY;
        } else {
            $analysis = $this->analyzer->analyze($url->url, $ipHash);
            $variants = $this->variants($analysis);
            $option = $this->pickDefault($variants);
            $job = $this->downloads->createWatch(
                $analysis->id,
                $analysis->url,
                $analysis->platformKey,
                $analysis->title,
                $analysis->durationSec,
                $option['id'],
                $option['sizeBytes'],
                $variants,
                $ipHash,
            );
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
     * The media an official embed can play, or null when the link has to be prepared as a file.
     *
     * @throws ApiException INVALID_URL / PLAYLIST_NOT_SUPPORTED for YouTube links without a video
     */
    private function embedded(ValidatedUrl $url): ?WatchSource
    {
        $platform = $url->platform->name;

        switch ($url->platform->key) {
            case 'youtube':
                $video = YouTubeId::fromUrl($url->url);

                return new WatchSource(WatchSourceKind::YouTube, $video->id, $platform, null, null, $video->thumbnailUrl(), $video->startSec);
            case 'vk':
                $video = VkVideoId::fromUrl($url->url);

                return $video === null ? null : new WatchSource(WatchSourceKind::Vk, $video->ref(), $platform, null, null, null, $video->startSec);
            default:
                return null;
        }
    }

    /**
     * The video variants members may choose from: up to WATCH_MAX_HEIGHT, highest first. Variants
     * known to exceed MAX_FILE_SIZE are already left out by the option builder.
     *
     * @return non-empty-list<array{id: string, label: string, height: int, sizeBytes: ?int}>
     *
     * @throws ApiException NO_FORMATS
     */
    private function variants(Analysis $analysis): array
    {
        $variants = [];
        foreach ($analysis->options as $option) {
            if ($option->kind === OptionKind::Video && $option->height !== null && $option->height <= $this->config->watchMaxHeight) {
                $variants[] = ['id' => $option->id, 'label' => $option->height . 'p', 'height' => $option->height, 'sizeBytes' => $option->sizeBytes];
            }
        }
        usort($variants, static fn (array $a, array $b): int => $b['height'] <=> $a['height']);

        return $variants !== [] ? $variants : throw new ApiException(ErrorCode::NoFormats, 'no video option within WATCH_MAX_HEIGHT');
    }

    /**
     * What a room starts with: the largest variant up to WATCH_DEFAULT_HEIGHT (the outgoing bandwidth
     * per viewer is small), or the smallest one if all are larger.
     *
     * @param non-empty-list<array{id: string, label: string, height: int, sizeBytes: ?int}> $variants highest first
     *
     * @return array{id: string, label: string, height: int, sizeBytes: ?int}
     */
    private function pickDefault(array $variants): array
    {
        foreach ($variants as $variant) {
            if ($variant['height'] <= $this->config->watchDefaultHeight) {
                return $variant;
            }
        }

        return $variants[count($variants) - 1];
    }
}
