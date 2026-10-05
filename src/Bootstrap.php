<?php

declare(strict_types=1);

namespace ClipHunter;

use ClipHunter\Config\AppConfig;
use Dotenv\Dotenv;

/**
 * Loads `.env` (if present) and builds the container. Shared by the web front controller and CLI scripts.
 */
final class Bootstrap
{
    public static function container(string $projectRoot): Container
    {
        if (is_file($projectRoot . '/.env')) {
            Dotenv::createImmutable($projectRoot)->safeLoad();
        }

        // Process env plus whatever .env added. The immutable loader never overrides a variable that
        // is already set, so the real environment wins. $_ENV alone is not enough: PHP leaves it
        // empty when variables_order lacks "E".
        $config = AppConfig::fromEnvironment(array_merge(getenv(), $_ENV), $projectRoot);
        self::configurePhp($config);

        return Services::build($config);
    }

    private static function configurePhp(AppConfig $config): void
    {
        // Errors are logged by our handler; never rendered into responses.
        ini_set('display_errors', $config->debug && PHP_SAPI === 'cli' ? '1' : '0');
        error_reporting(E_ALL);
        umask(0027);
        date_default_timezone_set('UTC');
    }
}
