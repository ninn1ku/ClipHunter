<?php

declare(strict_types=1);

namespace ClipHunter\Http;

use ClipHunter\Exception\ApiException;
use ClipHunter\Exception\ErrorCode;
use Closure;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function FastRoute\simpleDispatcher;

/**
 * Dispatches to single-action controllers (PSR-15 handlers). Route placeholders become request attributes.
 */
final class Router implements RequestHandlerInterface
{
    private Dispatcher $dispatcher;

    /**
     * @param list<Route> $routes
     * @param Closure(class-string<RequestHandlerInterface>): RequestHandlerInterface $resolve
     */
    public function __construct(array $routes, private readonly Closure $resolve)
    {
        $this->dispatcher = simpleDispatcher(static function (RouteCollector $collector) use ($routes): void {
            foreach ($routes as $route) {
                $collector->addRoute($route->method, $route->pattern, $route->handler);
            }
        });
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $result = $this->dispatcher->dispatch($request->getMethod(), $request->getUri()->getPath());

        if ($result[0] === Dispatcher::NOT_FOUND) {
            throw new ApiException(ErrorCode::NotFound);
        }

        if ($result[0] === Dispatcher::METHOD_NOT_ALLOWED) {
            /** @var list<string> $allowed */
            $allowed = $result[1];
            throw new ApiException(ErrorCode::MethodNotAllowed, headers: ['Allow' => implode(', ', $allowed)]);
        }

        /** @var class-string<RequestHandlerInterface> $handlerClass */
        $handlerClass = $result[1];
        /** @var array<string, string> $params */
        $params = $result[2];

        foreach ($params as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }

        return ($this->resolve)($handlerClass)->handle($request);
    }
}
