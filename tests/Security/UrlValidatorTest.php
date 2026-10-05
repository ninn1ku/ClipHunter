<?php

declare(strict_types=1);

namespace ClipHunter\Tests\Security;

use ClipHunter\Exception\ApiException;
use ClipHunter\Exception\ErrorCode;
use ClipHunter\Media\PlatformRegistry;
use ClipHunter\Security\UrlValidator;
use ClipHunter\Tests\Support\FakeResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlValidatorTest extends TestCase
{
    private static function validator(?FakeResolver $resolver = null): UrlValidator
    {
        return new UrlValidator(
            PlatformRegistry::fromFile(dirname(__DIR__, 2) . '/config/platforms.php'),
            $resolver ?? new FakeResolver(),
        );
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function accepted(): iterable
    {
        yield 'youtube watch' => ['https://www.youtube.com/watch?v=jNQXAC9IVRw', 'https://www.youtube.com/watch?v=jNQXAC9IVRw', 'youtube'];
        yield 'youtu.be' => ['https://youtu.be/jNQXAC9IVRw?t=5', 'https://youtu.be/jNQXAC9IVRw?t=5', 'youtube'];
        yield 'shorts' => ['https://youtube.com/shorts/abc123', 'https://youtube.com/shorts/abc123', 'youtube'];
        yield 'no scheme' => ['youtube.com/watch?v=x', 'https://youtube.com/watch?v=x', 'youtube'];
        yield 'http upgraded' => ['http://vk.com/video-1_2', 'https://vk.com/video-1_2', 'vk'];
        yield 'surrounding whitespace' => ["  https://www.tiktok.com/@u/video/1 \n", 'https://www.tiktok.com/@u/video/1', 'tiktok'];
        yield 'uppercase host and scheme' => ['HTTPS://WWW.REDDIT.COM/r/x/comments/1/', 'https://www.reddit.com/r/x/comments/1/', 'reddit'];
        yield 'trailing dot' => ['https://x.com./u/status/1', 'https://x.com/u/status/1', 'twitter'];
        yield 'explicit 443' => ['https://rutube.ru:443/video/abc/', 'https://rutube.ru/video/abc/', 'rutube'];
        yield 'fragment dropped' => ['https://www.twitch.tv/videos/1#t=1m', 'https://www.twitch.tv/videos/1', 'twitch'];
        yield 'no path' => ['https://dai.ly', 'https://dai.ly/', 'dailymotion'];
        yield 'cyrillic in query' => ['https://ok.ru/video/1?q=видео', 'https://ok.ru/video/1?q=видео', 'ok'];
    }

    #[DataProvider('accepted')]
    public function testAcceptsAndCanonicalisesSupportedUrls(string $input, string $expectedUrl, string $expectedPlatform): void
    {
        $result = self::validator()->validate($input);

        self::assertSame($expectedUrl, $result->url);
        self::assertSame($expectedPlatform, $result->platform->key);
    }

    /**
     * @return iterable<string, array{string, ErrorCode}>
     */
    public static function rejected(): iterable
    {
        $invalid = ErrorCode::InvalidUrl;
        $unsupported = ErrorCode::UnsupportedSource;

        // Malformed
        yield 'empty' => ['', $invalid];
        yield 'spaces only' => ['   ', $invalid];
        yield 'too long' => ['https://youtube.com/watch?v=' . str_repeat('a', 2100), $invalid];
        yield 'inner space' => ['https://youtube.com/watch?v=a b', $invalid];
        yield 'newline injection' => ["https://youtube.com/watch?v=a\nHost: evil", $invalid];
        yield 'null byte' => ["https://youtube.com/\0watch", $invalid];
        yield 'tab' => ["https://youtube.com/\twatch", $invalid];
        yield 'invalid utf8' => ["https://youtube.com/\xC3\x28", $invalid];
        yield 'zero-width space in host' => ["https://you\u{200B}tube.com/watch", $invalid];
        yield 'bidi override' => ["https://youtube.com/\u{202E}moc.live", $invalid];
        yield 'not a url' => ['hello world', $invalid];
        yield 'scheme without authority' => ['https:youtube.com/watch', $invalid];

        // Dangerous schemes
        yield 'file' => ['file:///etc/passwd', $invalid];
        yield 'javascript' => ['javascript:alert(1)', $invalid];
        yield 'data' => ['data:text/html,<script>alert(1)</script>', $invalid];
        yield 'ftp' => ['ftp://youtube.com/video', $invalid];
        yield 'gopher' => ['gopher://127.0.0.1:6379/_FLUSHALL', $invalid];
        yield 'dict' => ['dict://localhost:11211/stat', $invalid];
        yield 'php filter' => ['php://filter/resource=/etc/passwd', $invalid];
        yield 'protocol relative' => ['//youtube.com/watch?v=x', $invalid];

        // Userinfo / parser confusion
        yield 'userinfo' => ['https://user:pass@youtube.com/watch', $invalid];
        yield 'at confusion' => ['https://youtube.com@127.0.0.1/', $invalid];
        yield 'at confusion 2' => ['https://youtube.com:80@evil.example/', $invalid];
        yield 'backslash confusion' => ['https://evil.example\@youtube.com/', $invalid];
        yield 'backslash host' => ['https://youtube.com\.evil.example/', $invalid];
        yield 'encoded host' => ['https://%79outube.com/watch', $invalid];

        // Ports
        yield 'custom port' => ['https://youtube.com:8080/watch', $invalid];
        yield 'redis port' => ['http://youtube.com:6379/', $invalid];

        // SSRF: loopback / private / metadata by literal
        yield 'localhost' => ['http://localhost/admin', $invalid];
        yield 'localhost port' => ['http://localhost:8080/', $invalid];
        yield 'loopback v4' => ['http://127.0.0.1/', $invalid];
        yield 'loopback short' => ['http://127.1/', $invalid];
        yield 'decimal ip' => ['http://2130706433/', $invalid];
        yield 'hex ip' => ['http://0x7f.0x0.0x0.0x1/', $invalid];
        yield 'octal ip' => ['http://0177.0.0.1/', $invalid];
        yield 'zero ip' => ['http://0/', $invalid];
        yield 'loopback v6' => ['http://[::1]/', $invalid];
        yield 'mapped v6' => ['http://[::ffff:127.0.0.1]/', $invalid];
        yield 'metadata' => ['http://169.254.169.254/latest/meta-data/', $invalid];
        yield 'metadata gcp' => ['http://metadata.google.internal/computeMetadata/v1/', $unsupported];
        yield 'private 10' => ['http://10.0.0.1/', $invalid];
        yield 'private 192' => ['https://192.168.0.1/', $invalid];
        yield 'internal hostname' => ['http://intranet/', $invalid];
        yield 'internal fqdn' => ['http://db.internal.local/', $unsupported];

        // Allowlist
        yield 'unsupported site' => ['https://example.com/video.mp4', $unsupported];
        yield 'lookalike suffix' => ['https://youtube.com.evil.example/watch', $unsupported];
        yield 'lookalike prefix' => ['https://notyoutube.com/watch', $unsupported];
        yield 'cyrillic homograph' => ['https://yоutube.com/watch?v=x', $unsupported];
        yield 'nip.io style' => ['https://127.0.0.1.nip.io/', $unsupported];
    }

    #[DataProvider('rejected')]
    public function testRejectsUnsafeOrUnsupportedInput(string $input, ErrorCode $expected): void
    {
        try {
            self::validator()->validate($input);
            self::fail('Expected rejection for: ' . $input);
        } catch (ApiException $e) {
            self::assertSame($expected, $e->errorCode, 'reason: ' . $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function nonPublicDns(): iterable
    {
        yield 'loopback' => [['127.0.0.1']];
        yield 'metadata' => [['169.254.169.254']];
        yield 'private' => [['10.1.2.3']];
        yield 'mixed public and private' => [['142.250.74.46', '192.168.1.10']];
        yield 'ipv6 ula' => [['fd00::1']];
        yield 'ipv6 mapped loopback' => [['::ffff:127.0.0.1']];
    }

    /**
     * @param list<string> $ips
     */
    #[DataProvider('nonPublicDns')]
    public function testRejectsAllowlistedHostsThatResolveToNonPublicAddresses(array $ips): void
    {
        $validator = self::validator(new FakeResolver(['www.youtube.com' => $ips]));

        try {
            $validator->validate('https://www.youtube.com/watch?v=x');
            self::fail('Expected rejection');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::UnsupportedSource, $e->errorCode);
            self::assertSame('dns_resolves_to_non_public_address', $e->getMessage());
        }
    }

    public function testRejectsUnresolvableHosts(): void
    {
        $validator = self::validator(new FakeResolver(['youtube.com' => []]));

        try {
            $validator->validate('https://youtube.com/watch?v=x');
            self::fail('Expected rejection');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::InvalidUrl, $e->errorCode);
        }
    }

    public function testDnsIsOnlyQueriedForAllowlistedHosts(): void
    {
        $resolver = new FakeResolver();

        try {
            self::validator($resolver)->validate('https://attacker-controlled.example/');
        } catch (ApiException) {
        }

        self::assertSame([], $resolver->queried);
    }
}
