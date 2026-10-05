<?php

declare(strict_types=1);

/*
 * Download worker. Run one process per concurrent download slot, e.g. via systemd:
 *   cliphunter-worker@1.service → php bin/worker.php 1
 */

use ClipHunter\Bootstrap;
use ClipHunter\Job\JobRepository;
use ClipHunter\Job\JobRunner;
use ClipHunter\Job\Worker;
use ClipHunter\Storage\StoragePaths;
use Psr\Log\LoggerInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$instance = $argv[1] ?? '1';
if (preg_match('~^[a-z0-9-]{1,16}$~D', $instance) !== 1) {
    fwrite(STDERR, "Invalid worker instance name.\n");
    exit(2);
}

$container = Bootstrap::container(dirname(__DIR__));

$worker = new Worker(
    $container->get(JobRepository::class),
    $container->get(JobRunner::class),
    $container->get(LoggerInterface::class),
    $container->get(StoragePaths::class)->dir('locks') . '/worker-' . $instance . '.alive',
);
$worker->run();
