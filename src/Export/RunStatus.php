<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * The lifecycle status of an export/publish run, driven by the ExportRun
 * aggregate.
 *
 * NotStarted and Running are live; Paused is a deliberate stop that only
 * `resume` may undo (required for WP-Cron ticks, which must never run
 * concurrently); Completed, CompletedWithWarnings, Failed and Cancelled are
 * terminal — no transition may leave them. The superseded flag (see
 * {@see ExportRun}) additionally forces a non-terminal run towards Cancelled
 * so stale runs can never publish.
 *
 * Invalid or missing persisted values normalize to NotStarted so unknown or
 * malformed data cannot wedge the machine.
 */
enum RunStatus: string
{
    case NotStarted = 'not_started';
    case Running = 'running';
    case Paused = 'paused';
    case Completed = 'completed';
    case CompletedWithWarnings = 'completed_with_warnings';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::CompletedWithWarnings, self::Failed, self::Cancelled => true,
            default => false,
        };
    }

    /**
     * The pure transition graph. Terminal states have no outgoing moves.
     */
    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::NotStarted => $to === self::Running || $to === self::Cancelled,
            self::Running => match ($to) {
                self::Paused,
                self::Completed,
                self::CompletedWithWarnings,
                self::Failed,
                self::Cancelled => true,
                default => false,
            },
            self::Paused => $to === self::Running || $to === self::Failed || $to === self::Cancelled,
            default => false,
        };
    }

    /**
     * Normalize a persisted value to a known status, defaulting to NotStarted.
     */
    public static function fromStored(mixed $value): self
    {
        if (is_string($value)) {
            foreach (self::cases() as $status) {
                if ($status->value === $value) {
                    return $status;
                }
            }
        }

        return self::NotStarted;
    }
}
