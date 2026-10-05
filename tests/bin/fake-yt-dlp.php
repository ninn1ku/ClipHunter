<?php

/*
 * Fake yt-dlp for tests. Invoked as: php fake-yt-dlp.php <yt-dlp args...> -- <url>
 *
 * The scenario is the "v" query parameter of the URL (default "ok"), e.g.
 * https://www.youtube.com/watch?v=private. If FAKE_YTDLP_ARGV_FILE is set, argv is written there.
 */

declare(strict_types=1);

$args = array_slice($argv, 1);
$sep = array_search('--', $args, true);
$url = $sep === false ? '' : (string) ($args[$sep + 1] ?? '');
parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
$scenario = is_string($query['v'] ?? null) ? $query['v'] : 'ok';

$argvFile = getenv('FAKE_YTDLP_ARGV_FILE');
if (is_string($argvFile) && $argvFile !== '') {
    file_put_contents($argvFile, json_encode($args, JSON_UNESCAPED_SLASHES));
}

$fixtures = dirname(__DIR__) . '/fixtures/yt-dlp';
$fixture = static fn (string $name): array => json_decode((string) file_get_contents("$fixtures/$name.json"), true, 512, JSON_THROW_ON_ERROR);
$fail = static function (string $message): never {
    fwrite(STDERR, "WARNING: [youtube] some harmless warning\n");
    fwrite(STDERR, $message . "\n");
    exit(1);
};

if (!in_array('--dump-single-json', $args, true)) {
    fwrite(STDERR, "fake-yt-dlp: download mode is not implemented for scenario $scenario\n");
    exit(2);
}

switch ($scenario) {
    case 'ok':
        echo json_encode($fixture('youtube'));
        exit(0);
    case 'reddit':
    case 'hd':
        echo json_encode($fixture($scenario));
        exit(0);
    case 'live':
        echo json_encode(['_type' => 'video', 'title' => 'Live now', 'is_live' => true, 'live_status' => 'is_live', 'formats' => []]);
        exit(0);
    case 'playlist':
        echo json_encode(['_type' => 'playlist', 'title' => 'A list', 'entries' => []]);
        exit(0);
    case 'long':
        echo json_encode(['duration' => 999999] + $fixture('youtube'));
        exit(0);
    case 'drm':
        $d = $fixture('youtube');
        $d['formats'] = array_map(static fn (array $f): array => ['has_drm' => true] + $f, $d['formats']);
        echo json_encode($d);
        exit(0);
    case 'xss':
        echo json_encode([
            'title' => "<img src=x onerror=alert(1)>\u{202E}gpj.exe\u{0000}\u{200B}  ",
            'uploader' => '../../etc/passwd',
            'thumbnail' => 'javascript:alert(1)',
            'duration' => 'NaN',
            'extractor' => 'youtube; rm -rf /',
        ] + $fixture('youtube'));
        exit(0);
    case 'list':
        echo '[1,2,3]';
        exit(0);
    case 'garbage':
        echo 'this is not json {';
        exit(0);
    case 'huge':
        $chunk = str_repeat('a', 1024 * 1024);
        for ($i = 0; $i < 40; $i++) {
            echo $chunk;
        }
        exit(0);
    case 'sleep':
        sleep(60);
        exit(0);
    case 'private':
        $fail('ERROR: [youtube] private: Private video. Sign in if you\'ve been granted access to this video');
        // no break
    case 'unavailable':
        $fail('ERROR: [youtube] gone: Video unavailable. This video has been removed by the uploader');
        // no break
    case 'bot':
        $fail('ERROR: [youtube] bot: Sign in to confirm you’re not a bot. Use --cookies-from-browser or --cookies for the authentication.');
        // no break
    case 'age':
        $fail('ERROR: [youtube] age: Sign in to confirm your age. This video may be inappropriate for some users.');
        // no break
    case 'geo':
        $fail('ERROR: [youtube] geo: The uploader has not made this video available in your country');
        // no break
    case 'unsupported':
        $fail('ERROR: No suitable extractor found for URL ' . $url);
        // no break
    default:
        $fail('ERROR: [youtube] x: Something completely unexpected happened (code 7)');
}
