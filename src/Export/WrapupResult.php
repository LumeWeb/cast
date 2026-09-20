<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

use InvalidArgumentException;

/**
 * Outcome of the final wrap-up stage for one export run: whether the run's
 * jailed work directory was safely deleted after the artifact passed its
 * integrity check, together with any artifact-integrity or deletion-failure
 * details the operator should see.
 */
final class WrapupResult
{
    /**
     * @param list<string> $integrityErrors integrity findings that blocked a
     *                                      clean finalize (e.g. a missing,
     *                                      undersized or entry-mismatched ZIP)
     *                                      while the ZIP/manifest evidence is
     *                                      preserved for inspection
     * @param list<string> $warnings        the pack warnings carried forward
     *                                      so a completed-with-warnings pack
     *                                      keeps the run flagged
     */
    public function __construct(
        public readonly bool $success,
        public readonly bool $workDirDeleted,
        public readonly array $integrityErrors = [],
        public readonly ?string $deletionFailure = null,
        public readonly array $warnings = [],
    ) {
    }

    public function hasIntegrityFailure(): bool
    {
        return $this->integrityErrors !== [];
    }

    /**
     * @param list<string> $warnings
     */
    public static function completed(bool $workDirDeleted, array $warnings = []): self
    {
        return new self(true, $workDirDeleted, [], null, $warnings);
    }

    /**
     * @param list<string> $integrityErrors
     * @param list<string> $warnings
     */
    public static function integrityFailure(array $integrityErrors, bool $workDirDeleted, array $warnings = []): self
    {
        return new self(false, $workDirDeleted, $integrityErrors, null, $warnings);
    }

    /**
     * @param list<string> $warnings
     */
    public static function deletionFailure(string $reason, array $warnings = []): self
    {
        return new self(false, false, [], $reason, $warnings);
    }

    /**
     * The persisted, JSON-friendly shape of the outcome so ExportRun can carry
     * the wrap-up result across WP-Cron requests and rehydrate PipelineState
     * without re-running wrap-up.
     *
     * @return array{
     *     success: bool,
     *     work_dir_deleted: bool,
     *     integrity_errors: list<string>,
     *     deletion_failure: string|null,
     *     warnings: list<string>,
     * }
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'work_dir_deleted' => $this->workDirDeleted,
            'integrity_errors' => $this->integrityErrors,
            'deletion_failure' => $this->deletionFailure,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * Strict, lossless read-back of a persisted wrap-up result. Any malformed
     * part is rejected loudly so a corrupt run row is never silently
     * reinterpreted.
     *
     * @return self
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data)) {
            throw new InvalidArgumentException('Wrapup result is not an array.');
        }

        $success = $data['success'] ?? null;
        if (!is_bool($success)) {
            throw new InvalidArgumentException('Wrapup result success is malformed.');
        }

        $workDirDeleted = $data['work_dir_deleted'] ?? null;
        if (!is_bool($workDirDeleted)) {
            throw new InvalidArgumentException('Wrapup result work_dir_deleted is malformed.');
        }

        $integrityErrors = $data['integrity_errors'] ?? null;
        if (!is_array($integrityErrors) || array_values($integrityErrors) !== $integrityErrors) {
            throw new InvalidArgumentException('Wrapup result integrity_errors are malformed.');
        }
        foreach ($integrityErrors as $integrityError) {
            if (!is_string($integrityError)) {
                throw new InvalidArgumentException('Wrapup result integrity_errors are malformed.');
            }
        }

        $deletionFailure = $data['deletion_failure'] ?? null;
        if ($deletionFailure !== null && !is_string($deletionFailure)) {
            throw new InvalidArgumentException('Wrapup result deletion_failure is malformed.');
        }

        $warnings = $data['warnings'] ?? null;
        if (!is_array($warnings) || array_values($warnings) !== $warnings) {
            throw new InvalidArgumentException('Wrapup result warnings are malformed.');
        }
        foreach ($warnings as $warning) {
            if (!is_string($warning)) {
                throw new InvalidArgumentException('Wrapup result warnings are malformed.');
            }
        }

        return new self(
            $success,
            $workDirDeleted,
            $integrityErrors,
            $deletionFailure,
            $warnings,
        );
    }
}
