<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * The capture stage: drains the deduplicated work queue one bounded unit
 * per tick, claims exactly one queued row per tick (lowest numeric priority
 * first, attempt counted, retry scheduled when a retryable outcome remains
 * within budget), maps every {@see CaptureService} outcome to a work-item
 * status, and reports done('') only at the queue fixed point.
 *
 * The queue transition is the only cursor: once no row is queued, in flight,
 * or waiting on a scheduled retry, pendingCount() reaches zero and the stage
 * reads the terminal status counts into {@see PipelineState::$capture} so a
 * fresh stage restarting from a persisted run reports the same summary. No
 * sleeps, no loops, no direct run mutation — one bounded unit per tick.
 */
final class CaptureStage implements PipelineStage
{
    public function __construct(
        private readonly CaptureEnvironment $environment,
        private readonly PipelineState $state,
        private readonly WorkItemRepository $repository,
        private readonly CaptureOutcomeApplier $outcomeApplier = new CaptureOutcomeApplier(),
    ) {
    }

    public function key(): PipelineStageKey
    {
        return PipelineStageKey::Capture;
    }

    public function execute(string $cursor): StageResult
    {
        $origin = $this->state->probe?->origin;
        if ($origin === null) {
            return StageResult::fail('Capture requires a successful probe first.');
        }

        $workDir = $this->state->setup?->workDir;
        if ($workDir === null) {
            return StageResult::fail('Capture requires a successful setup first.');
        }

        $item = $this->repository->claimNext();
        if ($item === null) {
            // Nothing is claimable. At the fixed point the whole queue has
            // drained (nothing queued, in flight, or waiting on a retry), so
            // the terminal summary is final and the stage is done; otherwise
            // a scheduled retry is simply not due yet and we wait one tick.
            if ($this->repository->pendingCount() === 0) {
                $this->state->capture = new CaptureSummary(
                    $this->repository->countByStatus(WorkItemStatus::Done),
                    $this->repository->countByStatus(WorkItemStatus::Failed),
                    $this->repository->countByStatus(WorkItemStatus::Skipped),
                );

                return StageResult::done('', 0);
            }

            return StageResult::more('', 0);
        }

        $result = $this->environment
            ->captureService($origin, $workDir)
            ->capture($item);

        $this->outcomeApplier->apply($this->repository, $item, $result);

        return StageResult::more('', 1);
    }
}
