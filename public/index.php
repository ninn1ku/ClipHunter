<?php

declare(strict_types=1);

/*
 * Front controller for /api/*. In production Nginx serves static files itself and
 * only forwards /api/ here. With the PHP built-in dev server, static files fall through.
 */

use ClipHunter\Bootstrap;
use ClipHunter\Http\ResponseEmitter;
use ClipHunter\Kernel;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

if (PHP_SAPI === 'cli-server') {
    $path = parse_url(is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
    if (is_string($path) && !str_starts_with($path, '/api/')) {
        return false;
    }
}

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';

try {
    $container = Bootstrap::container($projectRoot);
} catch (Throwable $e) {
    // Misconfiguration: log server-side, reveal nothing to the client.
    error_log('ClipHunter bootstrap failed: ' . $e::class . ': ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo '{"error":{"code":"INTERNAL_ERROR","message":"Сервис временно недоступен."}}';

    return;
}

$factory = new Psr17Factory();
$request = (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();

ResponseEmitter::emit($container->get(Kernel::class)->handle($request));
