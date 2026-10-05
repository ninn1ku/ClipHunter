<?php

declare(strict_types=1);

/*
 * Storage cleanup (retention policy). Run every few minutes by a systemd timer.
 */

use ClipHunter\Bootstrap;
use ClipHunter\Storage\Cleaner;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$stats = Bootstrap::container(dirname(__DIR__))->get(Cleaner::class)->run();

echo json_encode($stats, JSON_THROW_ON_ERROR), PHP_EOL;
