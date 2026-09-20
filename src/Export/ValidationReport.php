<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Result of the pre-pack validation: a PackStatus derived from the
 * findings plus the raw findings, warnings/errors views, and the state counts
 * (work-item statuses plus staged file count) a job runner needs for the
 * manifest. Findings are stored hard-first so the manifest samples read in a
 * stable, deterministic order.
 */
final class ValidationReport
{
    /**
     * @var list<ValidationFinding>
     */
    public readonly array $findings;

    /**
     * @param list<ValidationFinding> $findings
     * @param array<string, int>      $counts
     */
    public function __construct(
        public readonly PackStatus $status,
        array $findings,
        public readonly array $counts = [],
    ) {
        $this->findings = self::sortFindings($findings);
    }

    /**
     * @param list<ValidationFinding> $findings
     * @param array<string, int>      $counts
     */
    public static function summarize(array $findings, array $counts = []): self
    {
        $status = PackStatus::Completed;
        foreach ($findings as $finding) {
            if ($finding->severity === ValidationSeverity::Hard) {
                $status = PackStatus::Failed;
                break;
            }
            $status = PackStatus::CompletedWithWarnings;
        }

        return new self($status, $findings, $counts);
    }

    /**
     * @return list<ValidationFinding>
     */
    public function warnings(): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (ValidationFinding $f): bool => $f->severity === ValidationSeverity::Warning,
        ));
    }

    /**
     * @return list<ValidationFinding>
     */
    public function errors(): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (ValidationFinding $f): bool => $f->severity === ValidationSeverity::Hard,
        ));
    }

    public function hasViolation(): bool
    {
        return $this->status !== PackStatus::Completed;
    }

    /**
     * @param list<ValidationFinding> $findings
     *
     * @return list<ValidationFinding> hard findings first, then warnings, each
     *                                 group in insertion order
     */
    private static function sortFindings(array $findings): array
    {
        $hard = [];
        $warn = [];
        foreach ($findings as $finding) {
            if ($finding->severity === ValidationSeverity::Hard) {
                $hard[] = $finding;
            } else {
                $warn[] = $finding;
            }
        }

        return array_merge($hard, $warn);
    }
}
