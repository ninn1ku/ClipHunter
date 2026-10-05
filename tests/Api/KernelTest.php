<?php

declare(strict_types=1);

namespace ClipHunter\Tests\Api;

use ClipHunter\Container;
use ClipHunter\Http\Route;
use ClipHunter\Http\Router;
use ClipHunter\Tests\Support\TestApp;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class KernelTest extends TestCase
{
    public function testHealthReturnsOkForPublicClients(): void
    {
        $app = new TestApp();

        $response = $app->request('GET', '/api/health');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame(['status' => 'ok'], TestApp::decode($response));
    }

    public function testHealthShowsDiagnosticsOnlyToLoopback(): void
    {
        $app = new TestApp();

        $body = TestApp::decode($app->request('GET', '/api/health', server: ['REMOTE_ADDR' => '127.0.0.1']));

        self::assertSame('ok', $body['status']);
        self::assertArrayHasKey('checks', $body);
    }

    public function testEveryResponseCarriesARequestId(): void
    {
        $app = new TestApp();

        $ok = $app->request('GET', '/api/health');
        $notFound = $app->request('GET', '/api/nope');

        self::assertMatchesRegularExpression('~^[a-f0-9]{16}$~', $ok->getHeaderLine('X-Request-Id'));
        self::assertMatchesRegularExpression('~^[a-f0-9]{16}$~', $notFound->getHeaderLine('X-Request-Id'));
    }

    public function testUnknownRouteUsesTheStructuredErrorFormat(): void
    {
        $app = new TestApp();

        $response = $app->request('GET', '/api/does-not-exist');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['error' => ['code' => 'NOT_FOUND', 'message' => 'Не найдено.']], TestApp::decode($response));
    }

    public function testWrongMethodReturns405WithAllowHeader(): void
    {
        $app = new TestApp();

        $response = $app->request('DELETE', '/api/health');

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET', $response->getHeaderLine('Allow'));
        self::assertSame('METHOD_NOT_ALLOWED', self::errorCode($response));
    }

    public function testUnhandledExceptionsNeverLeakDetailsToClients(): void
    {
        $app = new TestApp();
        $app->container->set(Router::class, static fn (Container $c): Router => new Router(
            [new Route('GET', '/api/boom', ExplodingHandler::class)],
            static fn (): RequestHandlerInterface => new ExplodingHandler(),
        ));

        $response = $app->request('GET', '/api/boom');
        $raw = (string) $response->getBody();

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('INTERNAL_ERROR', self::errorCode($response));
        self::assertStringNotContainsString('secret-internal-detail', $raw);
        self::assertStringNotContainsString('.php', $raw);
        self::assertStringNotContainsString('#0', $raw);
        self::assertTrue($app->logs->hasErrorThatContains('http.unhandled_exception'));
    }

    public function testRequestsAreLoggedWithCorrelationButWithoutRawIp(): void
    {
        $app = new TestApp();

        $response = $app->request('GET', '/api/health?token=should-not-be-logged');

        $records = $app->logs->getRecords();
        self::assertNotEmpty($records);
        $record = $records[array_key_last($records)];
        self::assertSame('http.request', $record->message);
        self::assertSame('/api/health', $record->context['path']);
        self::assertSame($response->getHeaderLine('X-Request-Id'), $record->extra['request_id']);
        $ipHash = $record->extra['ip_hash'];
        self::assertIsString($ipHash);
        self::assertMatchesRegularExpression('~^[a-f0-9]{16}$~', $ipHash);

        $serialized = json_encode(array_map(static fn ($r): array => $r->toArray(), $records), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('203.0.113.10', $serialized);
        self::assertStringNotContainsString('should-not-be-logged', $serialized);
    }

    private static function errorCode(ResponseInterface $response): mixed
    {
        $error = TestApp::decode($response)['error'] ?? null;

        return is_array($error) ? ($error['code'] ?? null) : null;
    }
}

final class ExplodingHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        throw new LogicException('secret-internal-detail in /var/www/app/src/Foo.php');
    }
}
