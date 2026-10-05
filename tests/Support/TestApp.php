<?php

declare(strict_types=1);

namespace ClipHunter\Tests\Support;

use ClipHunter\Config\AppConfig;
use ClipHunter\Container;
use ClipHunter\Http\RequestContext;
use ClipHunter\Kernel;
use ClipHunter\Logging\LoggerFactory;
use ClipHunter\Services;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A fully wired application with isolated temp storage and an in-memory log.
 */
final class TestApp
{
    public readonly Container $container;
    public readonly TestHandler $logs;
    public readonly AppConfig $config;
    private readonly Psr17Factory $factory;

    /**
     * @param array<string, string> $env overrides on top of the test defaults
     */
    public function __construct(array $env = [])
    {
        $storage = TempDir::create('cliphunter-test-');

        $this->config = AppConfig::fromEnvironment(
            array_merge(['APP_ENV' => 'testing', 'STORAGE_PATH' => $storage, 'LOG_LEVEL' => 'debug'], $env),
            dirname(__DIR__, 2),
        );
        $this->container = Services::build($this->config);
        $this->logs = new TestHandler();
        $logs = $this->logs;
        $this->container->set(
            LoggerInterface::class,
            static fn (Container $c): Logger => LoggerFactory::create($c->get(AppConfig::class), $c->get(RequestContext::class), 'app', [$logs]),
        );
        $this->factory = new Psr17Factory();
    }

    /**
     * @param array<array-key, mixed>|null $json
     * @param array<string, string> $headers
     * @param array<string, string> $server
     */
    public function request(string $method, string $uri, ?array $json = null, array $headers = [], array $server = []): ResponseInterface
    {
        $request = $this->factory->createServerRequest($method, $uri, array_merge(['REMOTE_ADDR' => '203.0.113.10'], $server));
        if ($json !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->factory->createStream(json_encode($json, JSON_THROW_ON_ERROR)));
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->container->get(Kernel::class)->handle($request);
    }

    /**
     * @return array<string, mixed>
     */
    public static function decode(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Response body is not a JSON object.');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
