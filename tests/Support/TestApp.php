<?php

declare(strict_types=1);

namespace ClipHunter\Tests\Support;

use ClipHunter\Config\AppConfig;
use ClipHunter\Container;
use ClipHunter\Http\RequestContext;
use ClipHunter\Kernel;
use ClipHunter\Logging\LoggerFactory;
use ClipHunter\Media\MetadataSanitizer;
use ClipHunter\Media\YtDlpClient;
use ClipHunter\Process\ProcessRunner;
use ClipHunter\Security\HostResolver;
use ClipHunter\Services;
use ClipHunter\Storage\StoragePaths;
use ClipHunter\Support\Clock;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A fully wired application with isolated temp storage, an in-memory log, a frozen clock,
 * fake DNS and the fake yt-dlp binary (tests/bin/fake-yt-dlp.php).
 */
final class TestApp
{
    public const APP_URL = 'https://cliphunter.test';

    public readonly Container $container;
    public readonly TestHandler $logs;
    public readonly AppConfig $config;
    public readonly FrozenClock $clock;
    public readonly FakeResolver $resolver;
    private readonly Psr17Factory $factory;

    /**
     * @param array<string, string> $env overrides on top of the test defaults
     */
    public function __construct(array $env = [])
    {
        $storage = TempDir::create('cliphunter-test-');

        $this->config = AppConfig::fromEnvironment(
            array_merge(['APP_ENV' => 'testing', 'APP_URL' => self::APP_URL, 'STORAGE_PATH' => $storage, 'LOG_LEVEL' => 'debug'], $env),
            dirname(__DIR__, 2),
        );
        $this->container = Services::build($this->config);
        $this->logs = new TestHandler();
        $this->clock = new FrozenClock();
        $this->resolver = new FakeResolver();

        $logs = $this->logs;
        $clock = $this->clock;
        $resolver = $this->resolver;
        $config = $this->config;

        $this->container->set(
            LoggerInterface::class,
            static fn (Container $c): Logger => LoggerFactory::create($config, $c->get(RequestContext::class), 'app', [$logs]),
        );
        $this->container->set(Clock::class, static fn (): Clock => $clock);
        $this->container->set(HostResolver::class, static fn (): HostResolver => $resolver);
        $this->container->set(YtDlpClient::class, static fn (Container $c): YtDlpClient => new YtDlpClient(
            self::fakeYtDlp(),
            $c->get(ProcessRunner::class),
            new MetadataSanitizer($config->maxVideoDurationSec),
            $c->get(LoggerInterface::class),
            $c->get(StoragePaths::class)->dir('cache/yt-dlp'),
            $config->analyzeTimeoutSec,
        ));
        $this->factory = new Psr17Factory();
    }

    /**
     * @return non-empty-list<string>
     */
    public static function fakeYtDlp(): array
    {
        return [PHP_BINARY, dirname(__DIR__) . '/bin/fake-yt-dlp.php'];
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
     * POST /api/analyze as a same-origin browser would send it.
     *
     * @param array<string, string> $server
     */
    public function analyze(string $url, array $server = []): ResponseInterface
    {
        return $this->request('POST', '/api/analyze', ['url' => $url], ['Origin' => self::APP_URL, 'Sec-Fetch-Site' => 'same-origin'], $server);
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

    public static function errorCode(ResponseInterface $response): ?string
    {
        $error = self::decode($response)['error'] ?? null;
        $code = is_array($error) ? ($error['code'] ?? null) : null;

        return is_string($code) ? $code : null;
    }
}
