<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

use InvalidArgumentException;

/**
 * Result DTO of a pack run (or of a validation that refuses to pack): the outcome
 * status, the number of files added/skipped by the stager, the produced ZIP
 * size, the artifact path and its adjacent manifest path, plus every warning
 * (pre-pack validation leftover-origin/broken-reference findings and the
 * packer's own jail-skipped files) the job runner reports and records.
 */
final class PackResult
{
    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly PackStatus $status,
        public readonly int $filesAdded,
        public readonly int $filesSkipped,
        public readonly int $bytesWritten,
        public readonly string $zipPath,
        public readonly ?string $manifestPath,
        public readonly array $warnings = [],
    ) {
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }

    /**
     * A pack that never happened: a fatal packer problem (missing ext-zip,
     * missing work dir, artifact path inside the jail) so no ZIP was produced.
     *
     * @param list<string> $warnings
     */
    public static function failed(string $zipPath, array $warnings = []): self
    {
        return new self(PackStatus::Failed, 0, 0, 0, $zipPath, null, $warnings);
    }

    /**
     * The persisted, JSON-friendly shape of the outcome so ExportRun can carry
     * the pack result across WP-Cron requests and rehydrate PipelineState
     * without re-packing.
     *
     * @return array{
     *     status: string,
     *     files_added: int,
     *     files_skipped: int,
     *     bytes_written: int,
     *     zip_path: string,
     *     manifest_path: string|null,
     *     warnings: list<string>,
     * }
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'files_added' => $this->filesAdded,
            'files_skipped' => $this->filesSkipped,
            'bytes_written' => $this->bytesWritten,
            'zip_path' => $this->zipPath,
            'manifest_path' => $this->manifestPath,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * Strict, lossless read-back of a persisted pack result. Any malformed
     * part is rejected loudly so a corrupt run row is never silently
     * reinterpreted.
     *
     * @return self
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data)) {
            throw new InvalidArgumentException('Pack result is not an array.');
        }

        $status = $data['status'] ?? null;
        $status = is_string($status) ? PackStatus::tryFrom($status) : null;
        if ($status === null) {
            throw new InvalidArgumentException('Pack result status is malformed.');
        }

        $filesAdded = $data['files_added'] ?? null;
        if (!is_int($filesAdded) || $filesAdded < 0) {
            throw new InvalidArgumentException('Pack result files_added is malformed.');
        }

        $filesSkipped = $data['files_skipped'] ?? null;
        if (!is_int($filesSkipped) || $filesSkipped < 0) {
            throw new InvalidArgumentException('Pack result files_skipped is malformed.');
        }

        $bytesWritten = $data['bytes_written'] ?? null;
        if (!is_int($bytesWritten) || $bytesWritten < 0) {
            throw new InvalidArgumentException('Pack result bytes_written is malformed.');
        }

        $zipPath = $data['zip_path'] ?? null;
        if (!is_string($zipPath) || $zipPath === '') {
            throw new InvalidArgumentException('Pack result zip_path is malformed.');
        }

        $manifestPath = $data['manifest_path'] ?? null;
        if ($manifestPath !== null && !is_string($manifestPath)) {
            throw new InvalidArgumentException('Pack result manifest_path is malformed.');
        }

        $warnings = $data['warnings'] ?? null;
        if (!is_array($warnings) || array_values($warnings) !== $warnings) {
            throw new InvalidArgumentException('Pack result warnings are malformed.');
        }
        foreach ($warnings as $warning) {
            if (!is_string($warning)) {
                throw new InvalidArgumentException('Pack result warnings are malformed.');
            }
        }

        return new self(
            $status,
            $filesAdded,
            $filesSkipped,
            $bytesWritten,
            $zipPath,
            $manifestPath,
            $warnings,
        );
    }
}
