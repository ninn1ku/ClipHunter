<?php

declare(strict_types=1);

/*
 * Pre-flight / health smoke test, used by deploy.sh and the yt-dlp updater.
 *
 *   php bin/smoke.php            config, binaries, storage
 *   php bin/smoke.php --network  + analyse a reference video with the real yt-dlp
 *
 * Exit code 0 = healthy. Prints one line per check.
 */

use ClipHunter\Bootstrap;
use ClipHunter\Config\AppConfig;
use ClipHunter\Media\YtDlpClient;
use ClipHunter\Process\ProcessOptions;
use ClipHunter\Process\ProcessRunner;
use ClipHunter\Security\UrlValidator;
use ClipHunter\Storage\StoragePaths;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

const REFERENCE_URL = 'https://www.youtube.com/watch?v=jNQXAC9IVRw';

$failed = false;
$check = static function (string $name, callable $fn) use (&$failed): void {
    try {
        $detail = $fn();
        echo "ok    {$name}" . (is_string($detail) && $detail !== '' ? " ({$detail})" : '') . PHP_EOL;
    } catch (Throwable $e) {
        $failed = true;
        echo "FAIL  {$name}: " . $e->getMessage() . PHP_EOL;
    }
};

try {
    $container = Bootstrap::container(dirname(__DIR__));
} catch (Throwable $e) {
    echo 'FAIL  config: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
$config = $container->get(AppConfig::class);
$runner = $container->get(ProcessRunner::class);

$version = static function (string $binary, string $flag) use ($runner): string {
    $result = $runner->run([$binary, $flag], new ProcessOptions(timeoutSec: 20, maxStdoutBytes: 65536));
    if (!$result->succeeded()) {
        throw new RuntimeException('cannot run ' . $binary);
    }

    return trim(strtok($result->stdout, "\n") ?: '');
};

$check('config', static fn (): string => $config->env);
$check('storage', static function () use ($container): string {
    $paths = $container->get(StoragePaths::class);
    $probe = $paths->dir('tmp') . '/.smoke-' . bin2hex(random_bytes(4));
    if (@file_put_contents($probe, 'x') !== 1) {
        throw new RuntimeException('storage is not writable');
    }
    unlink($probe);

    return sprintf('%d MB free', (int) (disk_free_space($paths->root) / 1024 / 1024));
});
$check('yt-dlp', static fn (): string => $version($config->ytDlpPath, '--version'));
$check('ffmpeg', static fn (): string => substr($version($config->ffmpegPath, '-version'), 0, 40));
$check('ffprobe', static fn (): string => substr($version($config->ffprobePath, '-version'), 0, 40));

if (in_array('--network', $argv, true)) {
    $check('analyze reference video', static function () use ($container): string {
        $url = $container->get(UrlValidator::class)->validate(REFERENCE_URL);
        $media = $container->get(YtDlpClient::class)->analyze($url);

        return $media->title . ', ' . count($media->formats) . ' formats';
    });
}

exit($failed ? 1 : 0);
