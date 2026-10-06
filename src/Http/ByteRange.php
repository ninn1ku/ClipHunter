<?php

declare(strict_types=1);

namespace ClipHunter\Http;

/**
 * A single HTTP byte range (RFC 9110 §14), for streaming media without Nginx in development.
 *
 * Only one range is supported: multi-range requests are answered with the whole file, which the
 * RFC allows and which no media element relies on.
 */
final readonly class ByteRange
{
    private function __construct(
        public int $start,
        public int $end,
        public bool $satisfiable,
    ) {
    }

    /**
     * @return self|null null when the header is absent, malformed or asks for several ranges (serve the full file)
     */
    public static function parse(string $header, int $size): ?self
    {
        if (preg_match('~^bytes=(\d{0,18})-(\d{0,18})$~D', trim($header), $m) !== 1 || ($m[1] === '' && $m[2] === '')) {
            return null;
        }

        if ($m[1] === '') {
            // Suffix range: the last N bytes.
            $length = (int) $m[2];
            if ($length === 0 || $size === 0) {
                return new self(0, 0, false);
            }

            return new self(max(0, $size - $length), $size - 1, true);
        }

        $start = (int) $m[1];
        $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
        if ($start >= $size || $end < $start) {
            return new self(0, 0, false);
        }

        return new self($start, $end, true);
    }

    public function length(): int
    {
        return $this->end - $this->start + 1;
    }

    public function contentRange(int $size): string
    {
        return $this->satisfiable ? sprintf('bytes %d-%d/%d', $this->start, $this->end, $size) : 'bytes */' . $size;
    }
}
