<?php

declare(strict_types=1);

namespace ClipHunter\Tests\Unit\RateLimit;

use ClipHunter\Config\RateLimitRule;
use ClipHunter\Exception\ApiException;
use ClipHunter\Exception\ErrorCode;
use ClipHunter\Media\YtDlpClient;
use ClipHunter\Media\YtDlpErrorClassifier;
use ClipHunter\RateLimit\RateLimiter;
use ClipHunter\RateLimit\Semaphore;
use ClipHunter\Tests\Support\FrozenClock;
use ClipHunter\Tests\Support\TempDir;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RateLimitTest extends TestCase
{
    public function testFixedWindowCountsAndResets(): void
    {
        $clock = new FrozenClock();
        $limiter = new RateLimiter(TempDir::create('rl-'), $clock);
        $rule = new RateLimitRule(2, 60);

        $limiter->hit('analyze', 'abcdef0123456789', $rule);
        $limiter->hit('analyze', 'abcdef0123456789', $rule);
        $clock->advance(15);

        try {
            $limiter->hit('analyze', 'abcdef0123456789', $rule);
            self::fail('Expected rate limit');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::RateLimited, $e->errorCode);
            self::assertSame(['Retry-After' => '45'], $e->headers);
        }

        $limiter->hit('downloads', 'abcdef0123456789', $rule); // separate bucket
        $clock->advance(45);
        $limiter->hit('analyze', 'abcdef0123456789', $rule); // new window
        $this->addToAssertionCount(1);
    }

    public function testKeysCannotEscapeTheDirectory(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new RateLimiter(TempDir::create('rl-'), new FrozenClock()))->hit('analyze', '../../etc/passwd', new RateLimitRule(1, 1));
    }

    public function testSemaphoreLimitsConcurrentHolders(): void
    {
        $semaphore = new Semaphore(TempDir::create('sem-'));

        $a = $semaphore->tryAcquire('analyze', 2);
        $b = $semaphore->tryAcquire('analyze', 2);
        $c = $semaphore->tryAcquire('analyze', 2);

        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertNull($c);

        $a->release();
        self::assertNotNull($semaphore->tryAcquire('analyze', 2));
    }

    public function testClassifierPrefersErrorLinesAndKnownPatterns(): void
    {
        self::assertSame(ErrorCode::SourceTemporarilyBlocked, YtDlpErrorClassifier::classify("WARNING: private video mention\nERROR: [youtube] x: Sign in to confirm you're not a bot", ErrorCode::ExtractorFailed));
        self::assertSame(ErrorCode::FileTooLarge, YtDlpErrorClassifier::classify('ERROR: File is larger than max-filesize (1.00GiB > 1.00GiB)', ErrorCode::DownloadFailed));
        self::assertSame(ErrorCode::VideoUnavailable, YtDlpErrorClassifier::classify('ERROR: [youtube] xxxxxxxxxxx: This video is unavailable', ErrorCode::ExtractorFailed));
        self::assertSame(ErrorCode::VideoUnavailable, YtDlpErrorClassifier::classify('ERROR: [twitter] 1: No video could be found in this tweet', ErrorCode::ExtractorFailed));
        self::assertSame(ErrorCode::GeoRestricted, YtDlpErrorClassifier::classify('ERROR: [TikTok] 1: Your IP address is blocked from accessing this post', ErrorCode::ExtractorFailed));
        self::assertSame(ErrorCode::ExtractorFailed, YtDlpErrorClassifier::classify('ERROR: weird', ErrorCode::ExtractorFailed));
        self::assertSame('ERROR: No suitable extractor found for URL <url>', YtDlpClient::redact('ERROR: No suitable extractor found for URL https://www.youtube.com/@x?a=1'));
        self::assertSame(ErrorCode::DownloadFailed, YtDlpErrorClassifier::classify('', ErrorCode::DownloadFailed));
    }
}
