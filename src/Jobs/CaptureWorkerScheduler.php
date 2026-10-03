<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

use LumeWeb\Cast\Export\RunRepository;

/**
 * The coordinator-side scheduling for capture-worker fanout on one run.
 *
 * The export tick arms up to maxWorkersPerRun one-item worker events per
 * run at capture. Each slot is its own (hook, args) identity, the run id
 * plus the slot number, and Scheduler::scheduleSingle is a no-op for an
 * already-armed slot, so repeated coordination stores nothing. The fanout
 * is bounded twice: by this slot cap and by the exclusive per-row claim,
 * so a duplicate or stale event never captures an item twice.
 */
final class CaptureWorkerScheduler
{
    public const WORKER_HOOK = 'cast/export/capture-worker';

    /**
     * Default fanout bound: the coordinator tick plus one parallel worker.
     * The tick keeps draining the queue on its own cadence, so the extra
     * armed worker is additive parallelism, not a replacement.
     */
    public const DEFAULT_MAX_WORKERS_PER_RUN = 1;

    public function __construct(
        private RunRepository $runs,
        private Scheduler $scheduler,
        private int $maxWorkersPerRun = self::DEFAULT_MAX_WORKERS_PER_RUN,
    ) {
    }

    /**
     * Arm worker events for the run at unix $at; returns how many new events
     * were armed. A run that is missing, terminal, superseded or not at
     * capture arms nothing: a stale coordination is always a no-op.
     */
    public function scheduleWorkers(string $runId, int $at): int
    {
        if (!$this->isCaptureWork($runId)) {
            return 0;
        }

        $armed = 0;
        for ($slot = 1; $slot <= $this->maxWorkersPerRun; ++$slot) {
            if ($this->scheduler->scheduleSingle(self::WORKER_HOOK, $at, [$runId, $slot])) {
                ++$armed;
            }
        }

        return $armed;
    }

    /**
     * Cancel every armed worker event for the run, safe to call when none
     * is scheduled. The tick calls this when the run leaves capture, so a
     * stale event cannot fire into a run that moved on; the worker's own
     * validation is the second, independent guard.
     */
    public function clearWorkers(string $runId): void
    {
        for ($slot = 1; $slot <= $this->maxWorkersPerRun; ++$slot) {
            $this->scheduler->cancelSingle(self::WORKER_HOOK, [$runId, $slot]);
        }
    }

    private function isCaptureWork(string $runId): bool
    {
        $run = $this->runs->find($runId);
        if ($run === null) {
            return false;
        }

        return !$run->isTerminal() && !$run->superseded && $run->isAtCaptureStage();
    }
}
