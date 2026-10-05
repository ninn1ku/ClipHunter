<?php

declare(strict_types=1);

namespace ClipHunter\Config;

use RuntimeException;

/**
 * Invalid or missing configuration. Thrown at boot, never mid-request.
 */
final class ConfigException extends RuntimeException
{
}
