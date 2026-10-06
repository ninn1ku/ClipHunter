<?php

declare(strict_types=1);

namespace ClipHunter\AniLiberty;

use ClipHunter\Exception\ApiException;
use ClipHunter\Exception\ErrorCode;
use CurlHandle;
use JsonException;

/**
 * {@see JsonFetcher} over ext-curl: https only, no redirects, bounded time and response size.
 */
final readonly class CurlJsonFetcher implements JsonFetcher
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    /**
     * @param non-empty-string $userAgent
     */
    public function __construct(
        private int $timeoutSec,
        private string $userAgent,
    ) {
    }

    public function get(string $url): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new ApiException(ErrorCode::ExtractorFailed, 'curl_init failed');
        }

        $body = '';
        $tooLarge = false;
        curl_setopt_array($handle, [
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(5, $this->timeoutSec),
            CURLOPT_TIMEOUT => $this->timeoutSec,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_ENCODING => '',
            CURLOPT_WRITEFUNCTION => static function (CurlHandle $ch, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                    $tooLarge = true;

                    return 0; // aborts the transfer
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($handle);
        $errno = curl_errno($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($tooLarge) {
            throw new ApiException(ErrorCode::ExtractorFailed, 'response too large');
        }
        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            throw new ApiException(ErrorCode::AnalyzeTimeout, 'service timed out');
        }
        if ($ok === false || $errno !== 0) {
            throw new ApiException(ErrorCode::ExtractorFailed, 'service unreachable (curl ' . $errno . ')');
        }
        if ($status === 404) {
            throw new ApiException(ErrorCode::VideoUnavailable, 'service returned 404');
        }
        if ($status !== 200) {
            throw new ApiException(ErrorCode::ExtractorFailed, 'service returned ' . $status);
        }

        try {
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiException(ErrorCode::ExtractorFailed, 'service returned invalid json');
        }

        return is_array($data) ? $data : throw new ApiException(ErrorCode::ExtractorFailed, 'service returned a scalar');
    }
}
