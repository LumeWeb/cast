<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

use LumeWeb\Cast\Export\CaptureEnvironment;
use LumeWeb\Cast\Export\CaptureOutcomeApplier;
use LumeWeb\Cast\Export\RunRepository;
use LumeWeb\Cast\Export\WorkItemRepository;

/**
 * The one-item capture worker for a specific run: the unit of parallel
 * capture work the coordinator fans out.
 *
 * Safe to fire from stale or duplicate scheduled events: every non-capturing
 * exit claims, captures and saves nothing, and the claim itself is exclusive
 * per row under this worker's unique token (a live lease is never stolen;
 * a lapsed one is reclaimable).
 *
 * Invariant: the worker never mutates the run aggregate, no stage advance,
 * no resume cursor, no capture summary, no save. Closing the capture stage
 * and every other stage transition stay with the coordinator tick, the sole
 * owner of stage progress.
 */
final class CaptureWorker
{
    /** Default claim token for direct calls. */
    public const DEFAULT_WORKER_TOKEN = 'cast-capture-worker';

    public function __construct(
        private RunRepository $runs,
        private WorkItemRepository $workItems,
        private CaptureEnvironment $environment,
        private CaptureOutcomeApplier $outcomeApplier = new CaptureOutcomeApplier(),
        private string $workerToken = self::DEFAULT_WORKER_TOKEN,
        private int $leaseSeconds = WorkItemRepository::DEFAULT_LEASE_SECONDS,
    ) {
    }

    public function work(string $runId): CaptureWorkerOutcome
    {
        $run = $this->runs->find($runId);
        if ($run === null) {
            return CaptureWorkerOutcome::skippedMissing();
        }

        if ($run->isTerminal()) {
            return CaptureWorkerOutcome::skippedTerminal();
        }

        if ($run->superseded) {
            return CaptureWorkerOutcome::skippedSuperseded();
        }

        if (!$run->isAtCaptureStage()) {
            return CaptureWorkerOutcome::skippedNotAtCapture();
        }

        $origin = $run->probe?->origin;
        $workDir = $run->setup?->workDir;
        if ($origin === null || $workDir === null) {
            return CaptureWorkerOutcome::skippedNotReady();
        }

        $item = $this->workItems->claimNext($runId, $this->workerToken, $this->leaseSeconds);
        if ($item === null) {
            // Empty queue, not-yet-due scheduled retries, or a row already
            // held under another worker's live lease: no work for this run.
            return CaptureWorkerOutcome::skippedIdle();
        }

        $result = $this->environment
            ->captureService($origin, $workDir)
            ->capture($item);

        $this->outcomeApplier->apply($runId, $this->workItems, $item, $result);

        return CaptureWorkerOutcome::captured($item->identity());
    }
}
