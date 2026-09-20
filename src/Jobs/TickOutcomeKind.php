<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

/**
 * What one {@see ExportTickRunner::tick()} call decided, so the WP-Cron hook
 * handler (a later stage) can log, reschedule a retry, or hand a stale run to
 * the watchdog without re-reading state.
 */
enum TickOutcomeKind: string
{
    /** The injected tick ran and left more work for a later tick. */
    case Ran = 'ran';

    /** The injected tick finished the run cleanly. */
    case Completed = 'completed';

    /** The run failed and is now terminal. */
    case Failed = 'failed';

    /** The tick failed but retries remain; the run stays live for a retry. */
    case Retried = 'retried';

    /** The injected tick signalled a stale run; the watchdog boundary. */
    case Watchdog = 'watchdog';

    /** Another worker holds the lock, or we lost the acquire race. */
    case SkippedLocked = 'skipped_locked';

    /** A stale lease exists and the configured policy says do not reclaim. */
    case SkippedStaleLock = 'skipped_stale_lock';

    /** No run is stored yet. */
    case SkippedNoRun = 'skipped_no_run';

    /** No publishable content/identity yet (or the run is paused/terminal). */
    case SkippedNotReady = 'skipped_not_ready';

    /** The run was overtaken by newer content and has been cancelled. */
    case Cancelled = 'cancelled';

    /**
     * A pending dirty (queued) run exists but the worker cannot claim it yet:
     * no publish identity exists and the run was not explicitly started. The
     * worker is parked — a later tick must retry so the run progresses the
     * moment identity (or an explicit start) becomes available.
     */
    case Deferred = 'deferred';

    public function isSkipped(): bool
    {
        return match ($this) {
            self::SkippedLocked, self::SkippedStaleLock, self::SkippedNoRun, self::SkippedNotReady => true,
            default => false,
        };
    }

    /**
     * Whether the outcome means the run reached a terminal status — the moment
     * the subscriber may arm a retention sweep (nothing else does).
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled => true,
            default => false,
        };
    }
}
