<?php

declare(strict_types=1);

namespace ClipHunter;

use ClipHunter\Config\AppConfig;
use ClipHunter\Http\Controller\HealthController;
use ClipHunter\Http\JsonResponder;
use ClipHunter\Http\Middleware\AccessLogMiddleware;
use ClipHunter\Http\Middleware\ErrorHandlerMiddleware;
use ClipHunter\Http\Middleware\RequestIdMiddleware;
use ClipHunter\Http\MiddlewarePipeline;
use ClipHunter\Http\RequestContext;
use ClipHunter\Http\Route;
use ClipHunter\Http\Router;
use ClipHunter\Logging\LoggerFactory;
use ClipHunter\Media\PlatformRegistry;
use ClipHunter\Security\DnsHostResolver;
use ClipHunter\Security\HostResolver;
use ClipHunter\Security\IpHasher;
use ClipHunter\Security\UrlValidator;
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

        $c->set(AppConfig::class, static fn (): AppConfig => $config);
        $c->set(Psr17Factory::class, static fn (): Psr17Factory => new Psr17Factory());
        $c->set(RequestContext::class, static fn (): RequestContext => new RequestContext());
        $c->set(IpHasher::class, static fn (): IpHasher => new IpHasher($config->appSecret));

        $c->set(LoggerInterface::class, static fn (Container $c): Logger => LoggerFactory::create($config, $c->get(RequestContext::class)));

        // Security
        $c->set(PlatformRegistry::class, static fn (): PlatformRegistry => PlatformRegistry::fromFile($config->projectRoot . '/config/platforms.php'));
        $c->set(HostResolver::class, static fn (): HostResolver => new DnsHostResolver());
        $c->set(UrlValidator::class, static fn (Container $c): UrlValidator => new UrlValidator(
            $c->get(PlatformRegistry::class),
            $c->get(HostResolver::class),
        ));

        $c->set(JsonResponder::class, static fn (Container $c): JsonResponder => new JsonResponder(
            $c->get(Psr17Factory::class),
            $c->get(Psr17Factory::class),
        ));

        // HTTP pipeline
        $c->set(Router::class, static fn (Container $c): Router => new Router(
            Route::all(),
            static fn (string $handler): RequestHandlerInterface => $c->get($handler),
        ));
        $c->set(Kernel::class, static fn (Container $c): Kernel => new Kernel(new MiddlewarePipeline(
            [
                new RequestIdMiddleware($c->get(RequestContext::class), $c->get(IpHasher::class)),
                new AccessLogMiddleware($c->get(LoggerInterface::class)),
                new ErrorHandlerMiddleware($c->get(JsonResponder::class), $c->get(LoggerInterface::class)),
            ],
            $c->get(Router::class),
        )));

        // Controllers
        $c->set(HealthController::class, static fn (Container $c): HealthController => new HealthController(
            $c->get(JsonResponder::class),
            $config,
        ));

        return $c;
    }
}
