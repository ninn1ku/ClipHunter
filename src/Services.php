<?php

declare(strict_types=1);

namespace ClipHunter;

use ClipHunter\Analysis\AnalysisRepository;
use ClipHunter\Analysis\AnalyzeService;
use ClipHunter\Config\AppConfig;
use ClipHunter\Http\Controller\AnalyzeController;
use ClipHunter\Http\Controller\DownloadController;
use ClipHunter\Http\Controller\DownloadFileController;
use ClipHunter\Http\Controller\DownloadStatusController;
use ClipHunter\Http\Controller\HealthController;
use ClipHunter\Http\Controller\WatchMediaFileController;
use ClipHunter\Http\Controller\WatchMediaStatusController;
use ClipHunter\Http\Controller\WatchSourceController;
use ClipHunter\Http\JsonResponder;
use ClipHunter\Http\Middleware\AccessLogMiddleware;
use ClipHunter\Http\Middleware\ErrorHandlerMiddleware;
use ClipHunter\Http\Middleware\OriginGuardMiddleware;
use ClipHunter\Http\Middleware\RequestIdMiddleware;
use ClipHunter\Http\MiddlewarePipeline;
use ClipHunter\Http\RequestContext;
use ClipHunter\Http\Route;
use ClipHunter\Http\Router;
use ClipHunter\Job\DownloadService;
use ClipHunter\Job\FilesystemJobRepository;
use ClipHunter\Job\JobRepository;
use ClipHunter\Job\JobRunner;
use ClipHunter\Logging\LoggerFactory;
use ClipHunter\Media\MediaProbe;
use ClipHunter\Media\MetadataSanitizer;
use ClipHunter\Media\OptionBuilder;
use ClipHunter\Media\PlatformRegistry;
use ClipHunter\Media\YtDlpClient;
use ClipHunter\Process\ProcessRunner;
use ClipHunter\RateLimit\RateLimiter;
use ClipHunter\RateLimit\Semaphore;
use ClipHunter\Security\DnsHostResolver;
use ClipHunter\Security\HostResolver;
use ClipHunter\Security\IpHasher;
use ClipHunter\Security\UrlValidator;
use ClipHunter\Storage\Cleaner;
use ClipHunter\Storage\Filesystem;
use ClipHunter\Storage\StorageGuard;
use ClipHunter\Storage\StoragePaths;
use ClipHunter\Support\Clock;
use ClipHunter\Support\SystemClock;
use ClipHunter\Watch\MediaTicket;
use ClipHunter\Watch\WatchMediaService;
use ClipHunter\Watch\WatchSourceService;
use Monolog\Logger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service wiring for the whole application.
 */
final class Services
{
    public static function build(AppConfig $config): Container
    {
        $c = new Container();

        // Infrastructure
        $c->set(AppConfig::class, static fn (): AppConfig => $config);
        $c->set(Clock::class, static fn (): Clock => new SystemClock());
        $c->set(Psr17Factory::class, static fn (): Psr17Factory => new Psr17Factory());
        $c->set(RequestContext::class, static fn (): RequestContext => new RequestContext());
        $c->set(IpHasher::class, static fn (): IpHasher => new IpHasher($config->appSecret));
        $c->set(StoragePaths::class, static function () use ($config): StoragePaths {
            $paths = new StoragePaths($config->storagePath);
            $paths->ensureDirectories();

            return $paths;
        });
        $c->set(LoggerInterface::class, static fn (Container $c): Logger => LoggerFactory::create($config, $c->get(RequestContext::class)));
        $c->set(ProcessRunner::class, static fn (): ProcessRunner => new ProcessRunner());

        // Security
        $c->set(PlatformRegistry::class, static fn (): PlatformRegistry => PlatformRegistry::fromFile($config->projectRoot . '/config/platforms.php'));
        $c->set(HostResolver::class, static fn (): HostResolver => new DnsHostResolver());
        $c->set(UrlValidator::class, static fn (Container $c): UrlValidator => new UrlValidator(
            $c->get(PlatformRegistry::class),
            $c->get(HostResolver::class),
        ));
        $c->set(RateLimiter::class, static fn (Container $c): RateLimiter => new RateLimiter(
            $c->get(StoragePaths::class)->dir('ratelimit'),
            $c->get(Clock::class),
        ));
        $c->set(Semaphore::class, static fn (Container $c): Semaphore => new Semaphore($c->get(StoragePaths::class)->dir('locks')));

        // Media
        $c->set(YtDlpClient::class, static fn (Container $c): YtDlpClient => new YtDlpClient(
            [$config->ytDlpPath],
            $c->get(ProcessRunner::class),
            new MetadataSanitizer($config->maxVideoDurationSec),
            $c->get(LoggerInterface::class),
            $c->get(StoragePaths::class)->dir('cache/yt-dlp'),
            $config->analyzeTimeoutSec,
        ));
        $c->set(OptionBuilder::class, static fn (): OptionBuilder => new OptionBuilder($config->maxVideoHeight, $config->maxFileSizeBytes));

        // Analysis
        $c->set(AnalysisRepository::class, static fn (Container $c): AnalysisRepository => new AnalysisRepository(
            $c->get(StoragePaths::class),
            $c->get(Clock::class),
        ));
        $c->set(AnalyzeService::class, static fn (Container $c): AnalyzeService => new AnalyzeService(
            $c->get(UrlValidator::class),
            $c->get(YtDlpClient::class),
            $c->get(OptionBuilder::class),
            $c->get(AnalysisRepository::class),
            $c->get(RateLimiter::class),
            $c->get(Semaphore::class),
            $c->get(Clock::class),
            $config,
            $c->get(LoggerInterface::class),
        ));

        // Downloads
        $c->set(Filesystem::class, static fn (Container $c): Filesystem => new Filesystem($c->get(StoragePaths::class)->root));
        $c->set(StorageGuard::class, static fn (Container $c): StorageGuard => new StorageGuard(
            $c->get(StoragePaths::class),
            $config->storageQuotaBytes,
            $config->minFreeDiskBytes,
        ));
        $c->set(JobRepository::class, static fn (Container $c): JobRepository => new FilesystemJobRepository($c->get(StoragePaths::class)));
        $c->set(MediaProbe::class, static fn (Container $c): MediaProbe => new MediaProbe([$config->ffprobePath], $c->get(ProcessRunner::class)));
        $c->set(JobRunner::class, static fn (Container $c): JobRunner => new JobRunner(
            $c->get(JobRepository::class),
            $c->get(UrlValidator::class),
            $c->get(YtDlpClient::class),
            $c->get(MediaProbe::class),
            $c->get(StoragePaths::class),
            $c->get(StorageGuard::class),
            $c->get(Filesystem::class),
            $c->get(Clock::class),
            $config,
            $c->get(LoggerInterface::class),
        ));
        $c->set(DownloadService::class, static fn (Container $c): DownloadService => new DownloadService(
            $c->get(JobRepository::class),
            $c->get(AnalysisRepository::class),
            $c->get(RateLimiter::class),
            $c->get(StorageGuard::class),
            $c->get(StoragePaths::class),
            $c->get(Filesystem::class),
            $c->get(Clock::class),
            $config,
            $c->get(LoggerInterface::class),
        ));
        $c->set(Cleaner::class, static fn (Container $c): Cleaner => new Cleaner(
            $c->get(JobRepository::class),
            $c->get(StoragePaths::class),
            $c->get(Filesystem::class),
            $c->get(Clock::class),
            $config,
            $c->get(LoggerInterface::class),
        ));

        // Watch rooms
        $c->set(MediaTicket::class, static fn (): MediaTicket => new MediaTicket($config->roomsSecret));
        $c->set(WatchMediaService::class, static fn (Container $c): WatchMediaService => new WatchMediaService(
            $c->get(DownloadService::class),
            $c->get(Clock::class),
        ));
        $c->set(WatchSourceService::class, static fn (Container $c): WatchSourceService => new WatchSourceService(
            $c->get(UrlValidator::class),
            $c->get(AnalyzeService::class),
            $c->get(DownloadService::class),
            $c->get(WatchMediaService::class),
            $c->get(MediaTicket::class),
            $c->get(RateLimiter::class),
            $c->get(Clock::class),
            $config,
            $c->get(LoggerInterface::class),
        ));

        // HTTP
        $c->set(JsonResponder::class, static fn (Container $c): JsonResponder => new JsonResponder(
            $c->get(Psr17Factory::class),
            $c->get(Psr17Factory::class),
        ));
        $c->set(Router::class, static fn (Container $c): Router => new Router(
            Route::all(),
            static fn (string $handler): RequestHandlerInterface => $c->get($handler),
        ));
        $c->set(Kernel::class, static fn (Container $c): Kernel => new Kernel(new MiddlewarePipeline(
            [
                new RequestIdMiddleware($c->get(RequestContext::class), $c->get(IpHasher::class)),
                new AccessLogMiddleware($c->get(LoggerInterface::class)),
                new ErrorHandlerMiddleware($c->get(JsonResponder::class), $c->get(LoggerInterface::class)),
                new OriginGuardMiddleware($config->appUrl),
            ],
            $c->get(Router::class),
        )));

        // Controllers
        $c->set(HealthController::class, static fn (Container $c): HealthController => new HealthController(
            $c->get(JsonResponder::class),
            $config,
        ));
        $c->set(AnalyzeController::class, static fn (Container $c): AnalyzeController => new AnalyzeController(
            $c->get(AnalyzeService::class),
            $c->get(JsonResponder::class),
        ));
        $c->set(DownloadController::class, static fn (Container $c): DownloadController => new DownloadController(
            $c->get(DownloadService::class),
            $c->get(JsonResponder::class),
        ));
        $c->set(DownloadStatusController::class, static fn (Container $c): DownloadStatusController => new DownloadStatusController(
            $c->get(DownloadService::class),
            $c->get(JsonResponder::class),
        ));
        $c->set(DownloadFileController::class, static fn (Container $c): DownloadFileController => new DownloadFileController(
            $c->get(DownloadService::class),
            $c->get(Psr17Factory::class),
            $c->get(Psr17Factory::class),
            $config,
        ));
        $c->set(WatchSourceController::class, static fn (Container $c): WatchSourceController => new WatchSourceController(
            $c->get(WatchSourceService::class),
            $c->get(JsonResponder::class),
        ));
        $c->set(WatchMediaStatusController::class, static fn (Container $c): WatchMediaStatusController => new WatchMediaStatusController(
            $c->get(WatchMediaService::class),
            $c->get(JsonResponder::class),
        ));
        $c->set(WatchMediaFileController::class, static fn (Container $c): WatchMediaFileController => new WatchMediaFileController(
            $c->get(WatchMediaService::class),
            $c->get(Psr17Factory::class),
            $c->get(Psr17Factory::class),
            $config,
        ));

        return $c;
    }
}
