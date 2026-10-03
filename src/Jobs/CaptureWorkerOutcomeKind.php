<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

/**
 * The outcome kinds of a one-item capture worker run.
 *
 * Every skipped kind is a harmless exit: the worker claimed nothing,
 * captured nothing and mutated no run, because its run is not, or is
 * no longer, capture work.
 */
enum CaptureWorkerOutcomeKind: string
{
    case Captured = 'captured';

    case SkippedMissing = 'skipped_missing';

    case SkippedTerminal = 'skipped_terminal';

    case SkippedSuperseded = 'skipped_superseded';

    case SkippedNotAtCapture = 'skipped_not_at_capture';

    /** At capture, but the run carries no probe origin or setup work dir. */
    case SkippedNotReady = 'skipped_not_ready';

    /** Nothing was claimable: the queue is empty, or holds only not-yet-due
     * scheduled retries, or a live lease held by another worker. */
    case SkippedIdle = 'skipped_idle';
}
