<?php

declare(strict_types=1);

namespace ClipHunter\Http;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A read-only PSR-7 stream over one region of a file, read in chunks (never loaded into memory).
 */
final class FileRangeStream implements StreamInterface
{
    /** @var resource|null */
    private $handle;
    private int $position = 0;

    public function __construct(string $path, private readonly int $offset, private readonly int $length)
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot open file for streaming.');
        }
        $this->handle = $handle;
    }

    public function __destruct()
    {
        $this->close();
    }

    public function __toString(): string
    {
        try {
            $this->rewind();

            return $this->getContents();
        } catch (RuntimeException) {
            return '';
        }
    }

    public function close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function detach()
    {
        $handle = $this->handle;
        $this->handle = null;

        return $handle;
    }

    public function getSize(): int
    {
        return $this->length;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->handle === null || $this->position >= $this->length;
    }

    public function isSeekable(): bool
    {
        return $this->handle !== null;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $target = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => $this->length + $offset,
            default => throw new RuntimeException('Invalid whence.'),
        };
        if ($target < 0 || $target > $this->length) {
            throw new RuntimeException('Seek outside the range.');
        }
        $this->position = $target;
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('Stream is read-only.');
    }

    public function isReadable(): bool
    {
        return $this->handle !== null;
    }

    public function read(int $length): string
    {
        if ($this->handle === null) {
            throw new RuntimeException('Stream is closed.');
        }
        $length = min($length, $this->length - $this->position);
        if ($length <= 0) {
            return '';
        }
        if (fseek($this->handle, $this->offset + $this->position) !== 0) {
            throw new RuntimeException('Cannot seek in file.');
        }
        $data = fread($this->handle, $length);
        if ($data === false) {
            throw new RuntimeException('Cannot read file.');
        }
        $this->position += strlen($data);

        return $data;
    }

    public function getContents(): string
    {
        $out = '';
        while (!$this->eof()) {
            $chunk = $this->read(65536);
            if ($chunk === '') {
                break;
            }
            $out .= $chunk;
        }

        return $out;
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $key === null ? [] : null;
    }
}
