<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * The pipeline stage an export/publish run currently occupies.
 *
 * Stages are a monotonic forward-only progression — Idle → Exporting →
 * Uploading → Publishing → Finished — so a run can never regress or skip a
 * step, and a persisted run resumes at exactly the stage it left off at.
 * Terminal lifecycle statuses force the stage to Finished.
 *
 * Invalid or missing persisted values normalize to Idle.
 */
enum RunStage: string
{
    case Idle = 'idle';
    case Exporting = 'exporting';
    case Uploading = 'uploading';
    case Publishing = 'publishing';
    case Finished = 'finished';

    /**
     * Position in the pipeline; used to validate forward-only moves.
     */
    public function order(): int
    {
        return match ($this) {
            self::Idle => 0,
            self::Exporting => 1,
            self::Uploading => 2,
            self::Publishing => 3,
            self::Finished => 4,
        };
    }

    /**
     * A stage may only advance to the immediate next position.
     */
    public function canAdvanceTo(self $to): bool
    {
        return $to->order() === $this->order() + 1;
    }

    /**
     * Normalize a persisted value to a known stage, defaulting to Idle.
     */
    public static function fromStored(mixed $value): self
    {
        if (is_string($value)) {
            foreach (self::cases() as $stage) {
                if ($stage->value === $value) {
                    return $stage;
                }
            }
        }

        return self::Idle;
    }
}
