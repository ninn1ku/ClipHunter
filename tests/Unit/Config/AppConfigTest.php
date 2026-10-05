<?php

declare(strict_types=1);

namespace ClipHunter\Tests\Unit\Config;

use ClipHunter\Config\AppConfig;
use ClipHunter\Config\ConfigException;
use ClipHunter\Config\RateLimitRule;
use Monolog\Level;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AppConfigTest extends TestCase
{
    private const SECRET = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testDefaultsAreSensibleForTheSmallProductionServer(): void
    {
        $config = AppConfig::fromEnvironment(['APP_SECRET' => self::SECRET], '/srv/app');

        self::assertSame('production', $config->env);
        self::assertFalse($config->debug);
        self::assertSame(1024 * 1024 * 1024, $config->maxFileSizeBytes);
        self::assertSame(7200, $config->maxVideoDurationSec);
        self::assertSame(1, $config->maxConcurrentDownloads);
        self::assertSame(30 * 60, $config->fileRetentionSec);
        self::assertSame(20, $config->analyzeRateLimit->limit);
        self::assertSame(600, $config->analyzeRateLimit->windowSec);
        self::assertSame(Level::Info, $config->logLevel);
        self::assertSame('/srv/app/storage', $config->storagePath);
    }

    public function testAbsoluteStoragePathIsKept(): void
    {
        $config = AppConfig::fromEnvironment(['APP_ENV' => 'testing', 'STORAGE_PATH' => '/var/lib/x/'], '/srv/app');

        self::assertSame('/var/lib/x', $config->storagePath);
    }

    public function testProductionRequiresASecret(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('APP_SECRET');

        AppConfig::fromEnvironment(['APP_ENV' => 'production'], '/srv/app');
    }

    public function testProductionForbidsDebug(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('APP_DEBUG');

        AppConfig::fromEnvironment(['APP_SECRET' => self::SECRET, 'APP_DEBUG' => 'true'], '/srv/app');
    }

    public function testDevelopmentFallsBackToAnInsecureSecret(): void
    {
        $config = AppConfig::fromEnvironment(['APP_ENV' => 'development'], '/srv/app');

        self::assertNotSame('', $config->appSecret);
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidEnvironments(): iterable
    {
        yield 'non-numeric size' => [['MAX_FILE_SIZE_MB' => '1gb'], 'MAX_FILE_SIZE_MB'];
        yield 'zero size' => [['MAX_FILE_SIZE_MB' => '0'], 'MAX_FILE_SIZE_MB'];
        yield 'negative timeout' => [['ANALYZE_TIMEOUT_SEC' => '-1'], 'ANALYZE_TIMEOUT_SEC'];
        yield 'huge concurrency' => [['MAX_CONCURRENT_DOWNLOADS' => '1000'], 'MAX_CONCURRENT_DOWNLOADS'];
        yield 'bad bool' => [['APP_DEBUG' => 'maybe'], 'APP_DEBUG'];
        yield 'bad env' => [['APP_ENV' => 'staging'], 'APP_ENV'];
        yield 'bad log level' => [['LOG_LEVEL' => 'verbose'], 'LOG_LEVEL'];
        yield 'bad rate limit' => [['RATE_LIMIT_ANALYZE' => 'twenty'], 'rate limit'];
        yield 'short secret' => [['APP_SECRET' => 'abc'], 'APP_SECRET'];
    }

    /**
     * @param array<string, string> $env
     */
    #[DataProvider('invalidEnvironments')]
    public function testInvalidValuesAreRejectedWithTheVariableName(array $env, string $expectedInMessage): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($expectedInMessage);

        AppConfig::fromEnvironment(array_merge(['APP_ENV' => 'testing'], $env), '/srv/app');
    }

    public function testRateLimitRuleParsing(): void
    {
        $rule = RateLimitRule::fromString(' 10 / 3600 ');

        self::assertSame(10, $rule->limit);
        self::assertSame(3600, $rule->windowSec);
    }
}
