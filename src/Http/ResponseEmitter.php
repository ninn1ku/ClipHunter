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
            $status = $response->getStatusCode();
            http_response_code($status);
            foreach ($response->getHeaders() as $name => $values) {
                foreach ($values as $i => $value) {
                    // Pass the status every time: PHP silently turns any response carrying a
                    // Location header into a 302 unless told otherwise (e.g. our 202 Accepted).
                    header($name . ': ' . $value, $i === 0, $status);
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
