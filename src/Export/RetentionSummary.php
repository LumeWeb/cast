<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * The typed outcome of one artifact retention sweep: what the store found,
 * what was collected, what was protected and why, plus every warning a
 * deployment should hear (a deletion refusal, an unavailable jail). Counts
 * are kept separate so the dashboard/CLI can render a single sweep without
 * re-scanning the filesystem.
 *
 * @phpstan-type SummaryShape array{
 *     now: int,
 *     retention_days: int,
 *     disabled: bool,
 *     jail_available: bool,
 *     scanned: int,
 *     deleted: int,
 *     deleted_names: list<string>,
 *     protected: list<string>,
 *     skipped_protected: int,
 *     skipped_not_due: int,
 *     deletion_failures: int,
 *     warnings: list<string>
 * }
 */
final class RetentionSummary
{
    /**
     * @param list<string> $warnings
     * @param list<string> $protected    artifact names kept because a
     *                                   live/latest/resumable run still
     *                                   references them
     * @param list<string> $deletedNames artifact names actually collected
     */
    public function __construct(
        public readonly int $now,
        public readonly int $retentionDays,
        public readonly bool $disabled,
        public readonly bool $jailAvailable,
        public readonly int $scanned,
        public readonly int $deleted,
        public readonly array $deletedNames = [],
        public readonly array $protected = [],
        public readonly int $skippedProtected = 0,
        public readonly int $skippedNotDue = 0,
        public readonly int $deletionFailures = 0,
        public readonly array $warnings = [],
    ) {
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }

    /**
     * A sweep run under a disabled policy: nothing was scanned or deleted.
     */
    public static function disabled(int $now, RetentionPolicy $policy): self
    {
        return new self(
            now: $now,
            retentionDays: $policy->retentionDays,
            disabled: true,
            jailAvailable: false,
            scanned: 0,
            deleted: 0,
        );
    }

    /**
     * A sweep that could not resolve the artifact jail (fresh site, missing
     * uploads/exports directory): nothing was scanned and the operator is
     * warned instead of GC silently no-op'ing.
     */
    public static function unavailable(int $now, RetentionPolicy $policy, string $warning): self
    {
        return new self(
            now: $now,
            retentionDays: $policy->retentionDays,
            disabled: false,
            jailAvailable: false,
            scanned: 0,
            deleted: 0,
            warnings: [$warning],
        );
    }

    /**
     * @param list<string> $deletedNames
     * @param list<string> $protected
     * @param list<string> $warnings
     */
    public static function ran(
        int $now,
        RetentionPolicy $policy,
        int $scanned,
        int $deleted,
        array $deletedNames,
        array $protected,
        int $skippedProtected,
        int $skippedNotDue,
        int $deletionFailures,
        array $warnings,
    ): self {
        return new self(
            now: $now,
            retentionDays: $policy->retentionDays,
            disabled: false,
            jailAvailable: true,
            scanned: $scanned,
            deleted: $deleted,
            deletedNames: $deletedNames,
            protected: $protected,
            skippedProtected: $skippedProtected,
            skippedNotDue: $skippedNotDue,
            deletionFailures: $deletionFailures,
            warnings: $warnings,
        );
    }

    /**
     * @return SummaryShape
     */
    public function toArray(): array
    {
        return [
            'now' => $this->now,
            'retention_days' => $this->retentionDays,
            'disabled' => $this->disabled,
            'jail_available' => $this->jailAvailable,
            'scanned' => $this->scanned,
            'deleted' => $this->deleted,
            'deleted_names' => $this->deletedNames,
            'protected' => $this->protected,
            'skipped_protected' => $this->skippedProtected,
            'skipped_not_due' => $this->skippedNotDue,
            'deletion_failures' => $this->deletionFailures,
            'warnings' => $this->warnings,
        ];
    }
}
