<?php

declare(strict_types=1);

namespace ClipHunter\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Writes a PSR-7 response to the SAPI.
 */
final class ResponseEmitter
{
    public static function emit(ResponseInterface $response): void
    {
        if (!headers_sent()) {
            http_response_code($response->getStatusCode());
            foreach ($response->getHeaders() as $name => $values) {
                foreach ($values as $i => $value) {
                    header($name . ': ' . $value, $i === 0);
                }
            }
        }

        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        while (!$body->eof()) {
            echo $body->read(65536);
        }
    }
}
