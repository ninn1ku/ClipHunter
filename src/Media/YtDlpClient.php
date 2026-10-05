<?php

declare(strict_types=1);

namespace ClipHunter\Media;

use ClipHunter\Exception\ApiException;
use ClipHunter\Exception\ErrorCode;
use ClipHunter\Process\ProcessOptions;
use ClipHunter\Process\ProcessResult;
use ClipHunter\Process\ProcessRunner;
use ClipHunter\Process\TerminationReason;
use ClipHunter\Security\ValidatedUrl;
use JsonException;
use Psr\Log\LoggerInterface;

/**
 * The only component that talks to yt-dlp.
 */
final readonly class YtDlpClient
{
    private const ANALYZE_NICE = 5;
    private const MAX_JSON_BYTES = 16 * 1024 * 1024;
    private const STDERR_LOG_BYTES = 2048;

    /**
     * @param non-empty-list<string> $binary the yt-dlp command (a path, or e.g. [php, fake-script] in tests)
     */
    public function __construct(
        private array $binary,
        private ProcessRunner $runner,
        private MetadataSanitizer $sanitizer,
        private LoggerInterface $logger,
        private string $cacheDir,
        private int $analyzeTimeoutSec,
    ) {
    }

    /**
     * @throws ApiException
     */
    public function analyze(ValidatedUrl $url): MediaInfo
    {
        $command = [
            ...$this->binary,
            ...$this->baseArgs($url),
            '--skip-download',
            '--dump-single-json',
            '--',
            $url->url,
        ];

        $result = $this->runner->run($command, new ProcessOptions(
            timeoutSec: $this->analyzeTimeoutSec,
            maxStdoutBytes: self::MAX_JSON_BYTES,
            niceLevel: self::ANALYZE_NICE,
        ));
        $this->logExec('analyze', $url, $result);

        if ($result->reason === TerminationReason::Timeout) {
            throw new ApiException(ErrorCode::AnalyzeTimeout, 'yt-dlp analyze timeout');
        }
        if ($result->reason === TerminationReason::OutputLimit) {
            throw new ApiException(ErrorCode::ExtractorFailed, 'yt-dlp json exceeded size limit');
        }
        if (!$result->succeeded()) {
            throw $this->failure($result, ErrorCode::ExtractorFailed);
        }

        try {
            $data = json_decode($result->stdout, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ApiException(ErrorCode::ExtractorFailed, 'yt-dlp returned invalid json', previous: $e);
        }
        if (!is_array($data)) {
            throw new ApiException(ErrorCode::ExtractorFailed, 'yt-dlp json is not an object');
        }

        return $this->sanitizer->sanitize($data);
    }

    /**
     * Arguments shared by every invocation. The platform's extractor allowlist keeps yt-dlp's
     * generic extractor (which would fetch arbitrary URLs) switched off.
     *
     * @return list<string>
     */
    public function baseArgs(ValidatedUrl $url): array
    {
        return [
            '--ignore-config',
            '--no-plugin-dirs',
            '--no-playlist',
            '--no-warnings',
            '--use-extractors', implode(',', $url->platform->extractors),
            '--socket-timeout', '15',
            '--retries', '3',
            '--extractor-retries', '2',
            '--cache-dir', $this->cacheDir,
        ];
    }

    /**
     * @param list<string> $args
     *
     * @return non-empty-list<string>
     */
    public function command(array $args): array
    {
        return [...$this->binary, ...$args];
    }

    public function failure(ProcessResult $result, ErrorCode $fallback): ApiException
    {
        $code = YtDlpErrorClassifier::classify($result->stderrTail, $fallback);
        $publicMessage = $code === ErrorCode::UnsupportedSource
            ? 'По этой ссылке не нашлось видео. Вставьте ссылку на конкретный ролик.'
            : null;

        $this->logger->warning('ytdlp.failed', [
            'code' => $code->value,
            'exit_code' => $result->exitCode,
            'stderr' => self::redact(substr($result->stderrTail, -self::STDERR_LOG_BYTES)),
        ]);

        return new ApiException($code, 'yt-dlp exit ' . ($result->exitCode ?? 'null'), $publicMessage);
    }

    /**
     * Full URLs are never logged (only host and hash elsewhere); yt-dlp echoes them in errors.
     */
    public static function redact(string $stderr): string
    {
        return (string) preg_replace('~https?://[^\s\'"]+~i', '<url>', $stderr);
    }

    public function logExec(string $operation, ValidatedUrl $url, ProcessResult $result): void
    {
        $this->logger->info('process.exec', [
            'binary' => 'yt-dlp',
            'operation' => $operation,
            'platform' => $url->platform->key,
            'url_hash' => $url->hash(),
            'exit_code' => $result->exitCode,
            'reason' => $result->reason->value,
            'duration_ms' => $result->durationMs,
        ]);
    }
}
