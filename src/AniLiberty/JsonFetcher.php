<?php

declare(strict_types=1);

namespace ClipHunter\AniLiberty;

use ClipHunter\Exception\ApiException;

/**
 * GETs a JSON document from an already validated https URL.
 */
interface JsonFetcher
{
    /**
     * @return array<array-key, mixed> the decoded JSON value (an object or a list)
     *
     * @throws ApiException VIDEO_UNAVAILABLE for 404, ANALYZE_TIMEOUT on timeout, EXTRACTOR_FAILED otherwise
     */
    public function get(string $url): array;
}
