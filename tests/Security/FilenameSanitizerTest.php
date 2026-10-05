<?php

declare(strict_types=1);

namespace ClipHunter\Tests\Security;

use ClipHunter\Media\MediaProbe;
use ClipHunter\Security\FilenameSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FilenameSanitizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function names(): iterable
    {
        yield 'plain' => ['Me at the zoo', 'Me at the zoo.mp4'];
        yield 'cyrillic' => ['Котик играет', 'Котик играет.mp4'];
        yield 'traversal' => ['../../etc/passwd', 'etc passwd.mp4'];
        yield 'windows traversal' => ['..\\..\\Windows\\win.ini', 'Windows win.ini.mp4'];
        yield 'absolute' => ['/etc/shadow', 'etc shadow.mp4'];
        yield 'reserved chars' => ['a:b*c?d"e<f>g|h', 'a b c d e f g h.mp4'];
        yield 'header injection' => ["evil\r\nSet-Cookie: x=1", 'evil Set-Cookie x=1.mp4'];
        yield 'dots only' => ['....', 'cliphunter-video.mp4'];
        yield 'empty' => ['', 'cliphunter-video.mp4'];
        yield 'windows device' => ['CON', 'cliphunter-video.mp4'];
        yield 'bidi spoof' => ["invoice\u{202E}4pm.exe", 'invoice 4pm.exe.mp4'];
    }

    #[DataProvider('names')]
    public function testDisplayNamesContainNoPathOrControlCharacters(string $title, string $expected): void
    {
        $name = FilenameSanitizer::displayName($title, 'mp4');

        self::assertSame($expected, $name);
        self::assertStringNotContainsString('/', $name);
        self::assertStringNotContainsString('\\', $name);
        self::assertDoesNotMatchRegularExpression('~[\x00-\x1F\x7F]~', $name);
    }

    public function testLongTitlesAreTruncated(): void
    {
        self::assertSame(FilenameSanitizer::MAX_LENGTH + 4, mb_strlen(FilenameSanitizer::displayName(str_repeat('ж', 500), 'mp3')));
    }

    public function testContentDispositionHasAnAsciiFallbackAndEncodedUtf8(): void
    {
        $header = FilenameSanitizer::contentDisposition('Котик "играет".mp4');

        self::assertSame('attachment; filename="Kotik _igraet_.mp4"; filename*=UTF-8\'\'%D0%9A%D0%BE%D1%82%D0%B8%D0%BA%20%22%D0%B8%D0%B3%D1%80%D0%B0%D0%B5%D1%82%22.mp4', $header);
        self::assertStringNotContainsString("\n", $header);
    }

    public function testProbeMatchingChecksContainerStreamsAndDuration(): void
    {
        $video = ['formatName' => 'mov,mp4,m4a,3gp,3g2,mj2', 'durationSec' => 19.06, 'hasVideo' => true, 'hasAudio' => true];
        $audioOnly = ['formatName' => 'mov,mp4,m4a,3gp,3g2,mj2', 'durationSec' => 19.0, 'hasVideo' => false, 'hasAudio' => true];

        self::assertTrue(MediaProbe::matches($video, 'mp4', 19));
        self::assertTrue(MediaProbe::matches($video, 'mp4', null));
        self::assertFalse(MediaProbe::matches($video, 'mp4', 600), 'truncated download');
        self::assertFalse(MediaProbe::matches($audioOnly, 'mp4', 19), 'video expected');
        self::assertTrue(MediaProbe::matches($audioOnly, 'm4a', 19));
        self::assertFalse(MediaProbe::matches(['formatName' => 'html', 'durationSec' => null, 'hasVideo' => false, 'hasAudio' => false], 'mp3', null));
    }
}
