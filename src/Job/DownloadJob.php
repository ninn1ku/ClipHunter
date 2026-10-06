<?php

declare(strict_types=1);

namespace ClipHunter\Job;

use ClipHunter\Exception\ErrorCode;
use ClipHunter\Support\Ids;
use InvalidArgumentException;

/**
 * One download request and its lifecycle. Persisted as storage/jobs/<id>.json.
 */
final class DownloadJob
{
    public function __construct(
        public readonly string $id,
        public readonly string $analysisId,
        public readonly string $optionId,
        public readonly string $url,
        public readonly string $platformKey,
        public readonly string $title,
        public readonly ?int $expectedDurationSec,
        public readonly ?int $expectedSizeBytes,
        public readonly string $ipHash,
        public readonly int $createdAt,
        public readonly JobPurpose $purpose = JobPurpose::Download,
        public JobStatus $status = JobStatus::Queued,
        public ?Progress $progress = null,
        public ?ErrorCode $error = null,
        public ?string $fileName = null,
        public ?string $fileExt = null,
        public ?int $fileSizeBytes = null,
        public ?int $startedAt = null,
        public ?int $finishedAt = null,
        public ?int $expiresAt = null,
    ) {
        if (!Ids::isValid($id) || !Ids::isValid($analysisId)) {
            throw new InvalidArgumentException('Invalid job id.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'analysisId' => $this->analysisId,
            'optionId' => $this->optionId,
            'url' => $this->url,
            'platformKey' => $this->platformKey,
            'title' => $this->title,
            'expectedDurationSec' => $this->expectedDurationSec,
            'expectedSizeBytes' => $this->expectedSizeBytes,
            'ipHash' => $this->ipHash,
            'createdAt' => $this->createdAt,
            'purpose' => $this->purpose->value,
            'status' => $this->status->value,
            'progress' => $this->progress?->toArray(),
            'error' => $this->error?->value,
            'fileName' => $this->fileName,
            'fileExt' => $this->fileExt,
            'fileSizeBytes' => $this->fileSizeBytes,
            'startedAt' => $this->startedAt,
            'finishedAt' => $this->finishedAt,
            'expiresAt' => $this->expiresAt,
        ];
    }

    /**
     * @param array<array-key, mixed> $d
     */
    public static function fromArray(array $d): self
    {
        $str = static fn (string $k): string => is_string($d[$k] ?? null) ? $d[$k] : throw new InvalidArgumentException('Malformed job: ' . $k);
        $nStr = static fn (string $k): ?string => is_string($d[$k] ?? null) ? $d[$k] : null;
        $nInt = static fn (string $k): ?int => is_int($d[$k] ?? null) ? $d[$k] : null;

        $status = JobStatus::tryFrom($str('status')) ?? throw new InvalidArgumentException('Malformed job: status');
        // Job files written before watch rooms existed have no purpose: they are downloads.
        $purpose = JobPurpose::tryFrom($nStr('purpose') ?? JobPurpose::Download->value) ?? throw new InvalidArgumentException('Malformed job: purpose');
        $progress = $d['progress'] ?? null;
        $error = $nStr('error');
        $ext = $nStr('fileExt');
        if ($ext !== null && preg_match('~^(mp4|m4a|mp3)$~D', $ext) !== 1) {
            throw new InvalidArgumentException('Malformed job: fileExt');
        }

        return new self(
            id: $str('id'),
            analysisId: $str('analysisId'),
            optionId: $str('optionId'),
            url: $str('url'),
            platformKey: $str('platformKey'),
            title: $str('title'),
            expectedDurationSec: $nInt('expectedDurationSec'),
            expectedSizeBytes: $nInt('expectedSizeBytes'),
            ipHash: $str('ipHash'),
            createdAt: $nInt('createdAt') ?? throw new InvalidArgumentException('Malformed job: createdAt'),
            purpose: $purpose,
            status: $status,
            progress: is_array($progress) ? Progress::fromArray($progress) : null,
            error: $error === null ? null : ErrorCode::tryFrom($error),
            fileName: $nStr('fileName'),
            fileExt: $ext,
            fileSizeBytes: $nInt('fileSizeBytes'),
            startedAt: $nInt('startedAt'),
            finishedAt: $nInt('finishedAt'),
            expiresAt: $nInt('expiresAt'),
        );
    }
}
