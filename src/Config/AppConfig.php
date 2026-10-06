<?php

declare(strict_types=1);

namespace ClipHunter\Config;

use Monolog\Level;

/**
 * Typed, validated application configuration built from environment variables.
 */
final readonly class AppConfig
{
    public const ENV_PRODUCTION = 'production';
    public const ENV_DEVELOPMENT = 'development';
    public const ENV_TESTING = 'testing';

    /** Nginx streams files via X-Accel-Redirect (production). */
    public const FILE_DELIVERY_XACCEL = 'xaccel';
    /** PHP streams files itself (development without Nginx). */
    public const FILE_DELIVERY_PHP = 'php';

    private const MB = 1024 * 1024;

    /** Used only outside production when APP_SECRET is empty. */
    private const INSECURE_DEV_SECRET = 'cliphunter-insecure-development-secret';

    /**
     * Used only outside production when ROOMS_SECRET is empty. The rooms service (rooms/src/config.ts)
     * falls back to the same value, so local development works without configuration.
     */
    public const INSECURE_DEV_ROOMS_SECRET = 'adbe838fa134f78ad76a2cc074629ecf810aaf14f68a716aee0a8bdc77aa9c27';

    public function __construct(
        public string $env,
        public bool $debug,
        public string $appUrl,
        public string $appSecret,
        public string $projectRoot,
        public string $ytDlpPath,
        public string $ffmpegPath,
        public string $ffprobePath,
        public string $storagePath,
        public int $maxFileSizeBytes,
        public int $maxVideoDurationSec,
        public int $maxVideoHeight,
        public int $analyzeTimeoutSec,
        public int $downloadTimeoutSec,
        public int $maxConcurrentAnalyze,
        public int $maxConcurrentDownloads,
        public int $maxQueueLength,
        public int $maxActiveJobsPerIp,
        public RateLimitRule $analyzeRateLimit,
        public RateLimitRule $downloadRateLimit,
        public int $storageQuotaBytes,
        public int $minFreeDiskBytes,
        public int $fileRetentionSec,
        public int $analysisTtlSec,
        public int $jobTtlSec,
        public Level $logLevel,
        public string $fileDelivery,
        public string $roomsSecret,
        public int $watchMaxHeight,
        public int $watchDefaultHeight,
        public int $watchFileRetentionSec,
        public int $watchIdleTtlSec,
        public RateLimitRule $watchSourcesRateLimit,
    ) {
    }

    /**
     * @param array<mixed> $env raw environment (e.g. $_ENV)
     *
     * @throws ConfigException
     */
    public static function fromEnvironment(array $env, string $projectRoot): self
    {
        $reader = new EnvReader($env);

        $appEnv = $reader->choice('APP_ENV', [self::ENV_PRODUCTION, self::ENV_DEVELOPMENT, self::ENV_TESTING], self::ENV_PRODUCTION);
        $isProduction = $appEnv === self::ENV_PRODUCTION;

        $debug = $reader->bool('APP_DEBUG', false);
        if ($isProduction && $debug) {
            throw new ConfigException('APP_DEBUG must be false in production.');
        }

        $secret = $reader->string('APP_SECRET', '');
        if ($secret === '') {
            if ($isProduction) {
                throw new ConfigException('APP_SECRET is required in production.');
            }
            $secret = self::INSECURE_DEV_SECRET;
        } elseif (preg_match('~^[a-f0-9]{64,}$~', $secret) !== 1) {
            throw new ConfigException('APP_SECRET must be at least 64 lowercase hex characters.');
        }

        $roomsSecret = $reader->string('ROOMS_SECRET', '');
        if ($roomsSecret === '') {
            if ($isProduction) {
                throw new ConfigException('ROOMS_SECRET is required in production.');
            }
            $roomsSecret = self::INSECURE_DEV_ROOMS_SECRET;
        } elseif (preg_match('~^[a-f0-9]{64,}$~', $roomsSecret) !== 1) {
            throw new ConfigException('ROOMS_SECRET must be at least 64 lowercase hex characters.');
        }

        $root = rtrim(str_replace('\\', '/', $projectRoot), '/');

        return new self(
            env: $appEnv,
            debug: $debug,
            appUrl: rtrim($reader->string('APP_URL', 'http://localhost:8080'), '/'),
            appSecret: $secret,
            projectRoot: $root,
            ytDlpPath: $reader->string('YTDLP_PATH', 'yt-dlp'),
            ffmpegPath: $reader->string('FFMPEG_PATH', 'ffmpeg'),
            ffprobePath: $reader->string('FFPROBE_PATH', 'ffprobe'),
            storagePath: self::resolvePath($reader->string('STORAGE_PATH', 'storage'), $root),
            maxFileSizeBytes: $reader->int('MAX_FILE_SIZE_MB', 1024, 1, 100_000) * self::MB,
            maxVideoDurationSec: $reader->int('MAX_VIDEO_DURATION_SEC', 7200, 1, 86_400),
            maxVideoHeight: $reader->int('MAX_VIDEO_HEIGHT', 2160, 144, 4320),
            analyzeTimeoutSec: $reader->int('ANALYZE_TIMEOUT_SEC', 30, 5, 300),
            downloadTimeoutSec: $reader->int('DOWNLOAD_TIMEOUT_SEC', 900, 5, 86_400),
            maxConcurrentAnalyze: $reader->int('MAX_CONCURRENT_ANALYZE', 2, 1, 64),
            maxConcurrentDownloads: $reader->int('MAX_CONCURRENT_DOWNLOADS', 1, 1, 64),
            maxQueueLength: $reader->int('MAX_QUEUE_LENGTH', 10, 1, 10_000),
            maxActiveJobsPerIp: $reader->int('MAX_ACTIVE_JOBS_PER_IP', 1, 1, 100),
            analyzeRateLimit: RateLimitRule::fromString($reader->string('RATE_LIMIT_ANALYZE', '20/600')),
            downloadRateLimit: RateLimitRule::fromString($reader->string('RATE_LIMIT_DOWNLOADS', '10/3600')),
            storageQuotaBytes: $reader->int('STORAGE_QUOTA_MB', 4096, 64, 10_000_000) * self::MB,
            minFreeDiskBytes: $reader->int('MIN_FREE_DISK_MB', 1536, 0, 10_000_000) * self::MB,
            fileRetentionSec: $reader->int('FILE_RETENTION_MIN', 30, 1, 10_080) * 60,
            analysisTtlSec: $reader->int('ANALYSIS_TTL_MIN', 30, 1, 1_440) * 60,
            jobTtlSec: $reader->int('JOB_TTL_HOURS', 24, 1, 720) * 3600,
            logLevel: match ($reader->choice('LOG_LEVEL', ['debug', 'info', 'notice', 'warning', 'error'], 'info')) {
                'debug' => Level::Debug,
                'notice' => Level::Notice,
                'warning' => Level::Warning,
                'error' => Level::Error,
                default => Level::Info,
            },
            fileDelivery: $reader->choice(
                'FILE_DELIVERY',
                [self::FILE_DELIVERY_XACCEL, self::FILE_DELIVERY_PHP],
                $isProduction ? self::FILE_DELIVERY_XACCEL : self::FILE_DELIVERY_PHP,
            ),
            roomsSecret: $roomsSecret,
            watchMaxHeight: $reader->int('WATCH_MAX_HEIGHT', 1080, 144, 2160),
            watchDefaultHeight: $reader->int('WATCH_DEFAULT_HEIGHT', 720, 144, 2160),
            watchFileRetentionSec: $reader->int('WATCH_FILE_RETENTION_MIN', 360, 30, 1_440) * 60,
            watchIdleTtlSec: $reader->int('WATCH_IDLE_TTL_MIN', 10, 2, 1_440) * 60,
            watchSourcesRateLimit: RateLimitRule::fromString($reader->string('RATE_LIMIT_WATCH_SOURCES', '20/3600')),
        );
    }

    public function isProduction(): bool
    {
        return $this->env === self::ENV_PRODUCTION;
    }

    private static function resolvePath(string $path, string $root): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $isAbsolute = str_starts_with($path, '/') || preg_match('~^[A-Za-z]:/~', $path) === 1;

        return $isAbsolute ? $path : $root . '/' . $path;
    }
}
