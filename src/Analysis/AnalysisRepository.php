<?php

declare(strict_types=1);

namespace ClipHunter\Analysis;

use ClipHunter\Storage\AtomicFile;
use ClipHunter\Storage\StoragePaths;
use ClipHunter\Support\Clock;
use ClipHunter\Support\Ids;
use InvalidArgumentException;

/**
 * Analyses as JSON files in storage/analyses/. Expired entries are invisible and removed by the cleaner.
 */
final readonly class AnalysisRepository
{
    public function __construct(
        private StoragePaths $paths,
        private Clock $clock,
    ) {
    }

    public function save(Analysis $analysis): void
    {
        AtomicFile::writeJson($this->paths->analysisFile($analysis->id), $analysis->toArray());
    }

    public function find(string $id): ?Analysis
    {
        if (!Ids::isValid($id)) {
            return null;
        }
        $data = AtomicFile::readJson($this->paths->analysisFile($id));
        if ($data === null) {
            return null;
        }

        try {
            $analysis = Analysis::fromArray($data);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $analysis->expiresAt > $this->clock->now() && $analysis->id === $id ? $analysis : null;
    }
}
