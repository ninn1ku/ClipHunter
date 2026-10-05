<?php

declare(strict_types=1);

namespace ClipHunter\Logging;

use ClipHunter\Config\AppConfig;
use ClipHunter\Http\RequestContext;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use Monolog\Processor\PsrLogMessageProcessor;

/**
 * Structured JSON logging: one object per line, correlated by request id.
 */
final class LoggerFactory
{
    /**
     * @param list<HandlerInterface>|null $handlers override the default file handler (tests)
     */
    public static function create(AppConfig $config, RequestContext $context, string $channel = 'app', ?array $handlers = null): Logger
    {
        if ($handlers === null) {
            $handler = new StreamHandler($config->storagePath . '/logs/' . $channel . '.log', $config->logLevel, true, 0640);
            $handler->setFormatter(new JsonFormatter(JsonFormatter::BATCH_MODE_NEWLINES, true, false, true));
            $handlers = [$handler];
        }

        $logger = new Logger($channel, $handlers);
        $logger->pushProcessor(new PsrLogMessageProcessor());
        $logger->pushProcessor(static function (LogRecord $record) use ($context): LogRecord {
            $extra = $record->extra;
            if ($context->requestId !== null) {
                $extra['request_id'] = $context->requestId;
            }
            if ($context->ipHash !== null) {
                $extra['ip_hash'] = $context->ipHash;
            }

            return $record->with(extra: $extra);
        });

        return $logger;
    }
}
