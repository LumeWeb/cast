<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

use InvalidArgumentException;

/**
 * Records the jailed work directory created for an export run.
 *
 * The value's persisted shape ({@see toArray()} / {@see fromArray()}) lets
 * ExportRun carry the work directory across WP-Cron requests so a fresh
 * request rehydrates it into PipelineState instead of re-creating it.
 */
final class SetupResult
{
    public function __construct(
        public readonly string $workDir,
    ) {
    }

    /**
     * @return array{work_dir: string}
     */
    public function toArray(): array
    {
        return ['work_dir' => $this->workDir];
    }

    /**
     * Strict, lossless read-back of a persisted setup result.
     */
    public static function fromArray(mixed $data): self
    {
        $workDir = is_array($data) ? ($data['work_dir'] ?? null) : null;
        if (!is_string($workDir) || $workDir === '') {
            throw new InvalidArgumentException('Setup state is malformed.');
        }

        return new self($workDir);
    }
}
