<?php

declare(strict_types=1);

namespace ClipHunter\Http;

use ClipHunter\Exception\ErrorCode;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Builds JSON responses, including the single error envelope used by the whole API.
 */
final readonly class JsonResponder
{
    private const FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    public function __construct(
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     * @param array<string, string> $headers
     */
    public function json(array $data, int $status = 200, array $headers = []): ResponseInterface
    {
        $response = $this->responses->createResponse($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($this->streams->createStream(json_encode($data, self::FLAGS)));

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * @param array<string, string> $headers
     */
    public function error(ErrorCode $code, ?string $message = null, array $headers = []): ResponseInterface
    {
        return $this->json(
            ['error' => ['code' => $code->value, 'message' => $message ?? $code->defaultMessage()]],
            $code->httpStatus(),
            $headers,
        );
    }

    public function noContent(): ResponseInterface
    {
        return $this->responses->createResponse(204)->withHeader('Cache-Control', 'no-store');
    }
}
