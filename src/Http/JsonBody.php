<?php

declare(strict_types=1);

namespace ClipHunter\Http;

use ClipHunter\Exception\ApiException;
use ClipHunter\Exception\ErrorCode;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Strict JSON request body parsing.
 */
final class JsonBody
{
    public const MAX_BYTES = 4096;

    /**
     * @return array<string, mixed>
     *
     * @throws ApiException UNSUPPORTED_MEDIA_TYPE, PAYLOAD_TOO_LARGE, INVALID_JSON
     */
    public static function parse(ServerRequestInterface $request, int $maxBytes = self::MAX_BYTES): array
    {
        $contentType = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0]));
        if ($contentType !== 'application/json') {
            throw new ApiException(ErrorCode::UnsupportedMediaType, 'content-type=' . substr($contentType, 0, 64));
        }

        $body = $request->getBody();
        $size = $body->getSize();
        if ($size !== null && $size > $maxBytes) {
            throw new ApiException(ErrorCode::PayloadTooLarge, 'size=' . $size);
        }
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $raw = $body->read($maxBytes + 1);
        if (strlen($raw) > $maxBytes) {
            throw new ApiException(ErrorCode::PayloadTooLarge, 'streamed body too large');
        }

        $data = json_decode($raw, true, 8);
        if (!is_array($data) || array_is_list($data) && $data !== []) {
            throw new ApiException(ErrorCode::InvalidJson, 'body is not a JSON object');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws ApiException INVALID_REQUEST
     */
    public static function string(array $data, string $field, int $maxLength): string
    {
        $value = $data[$field] ?? null;
        if (!is_string($value) || $value === '' || strlen($value) > $maxLength) {
            throw new ApiException(ErrorCode::InvalidRequest, 'field ' . $field . ' must be a non-empty string', sprintf('Поле «%s» заполнено неверно.', $field));
        }

        return $value;
    }
}
