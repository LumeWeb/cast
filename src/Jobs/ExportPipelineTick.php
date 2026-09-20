<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

use LumeWeb\Cast\Export\ExportRun;
use LumeWeb\Cast\Export\PipelineContext;
use LumeWeb\Cast\Export\PipelineStage;
use LumeWeb\Cast\Export\PipelineStageKey;
use LumeWeb\Cast\Export\RunSettings;
use LumeWeb\Cast\Export\RunStatus;
use LumeWeb\Cast\Export\StageResult;

/**
 * The real export orchestrator behind {@see BoundTick}: advances exactly one
 * bounded pipeline stage unit per tick.
 *
 * Each {@see perform()} run:
 *  - loads the current position from the run's saved coarse stage + resume
 *    cursor (the cursor encodes `"<PipelineStageKey>|<stage cursor>"`);
 *  - keeps the coarse {@see RunStage} in sync as boundaries cross from
 *    export → upload → publish;
 *  - executes exactly ONE unit of the current stage (never unbounded work,
 *    never sleeps);
 *  - applies the returned cursor, progress and warnings to the aggregate and
 *    persists it through the injected {@see PipelineContext} repository;
 *  - handles pause/cancel/failure explicitly: a paused, terminal or superseded
 *    run never advances; a stage failure surfaces as {@see TickResult::fail()}
 *    (retry bookkeeping stays in {@see ExportTickRunner}); a stage cancel ends
 *    the run as Cancelled.
 *
 * A run reaches a terminal status (Completed / CompletedWithWarnings) only
 * after the pack boundary, the wrapup boundary and the publish boundary have
 * all finished, so an export is never declared complete while it is still
 * mid-pipeline.
 */
final class ExportPipelineTick implements BoundTick
{
    public function __construct(private readonly PipelineContext $context)
    {
    }

    public function perform(ExportRun $run, int $now): TickResult
    {
        // Never advance a finished run or a deliberately paused one.
        if ($run->isTerminal() || $run->status === RunStatus::Paused) {
            return TickResult::more();
        }

        // An overtaken run may only be cancelled.
        if ($run->superseded) {
            $run->cancel(at: $now);
            $this->context->runs->save($run);

            return TickResult::more();
        }

        // A fresh WP-Cron request resumes from the persisted runtime state:
        // re-hydrate the shared PipelineState's Probe/Setup slots before any
        // stage unit runs so it never has to re-run a completed probe/setup.
        $this->hydrateState($run);

        [$stageKey, $cursor] = $this->currentPosition($run);
        $this->ensureCoarseStage($run, $stageKey, $now);

        $result = $this->stage($stageKey, $run->runId, $run->settings)->execute($cursor);
        $this->recordOutcome($run, $result, $now);
        // Mirror Probe/Setup results back onto the run so the save() below
        // persists them for the next request.
        $this->mirrorState($run, $now);

        if ($result->cancelled) {
            $run->cancel(at: $now);
            $this->context->runs->save($run);

            return TickResult::more();
        }

        if ($result->failure !== null) {
            $this->context->runs->save($run);

            return TickResult::fail($result->failure);
        }

        if ($result->done) {
            $next = $stageKey->next();
            if ($next === null) {
                // Only now — after pack, wrapup and publish — is a terminal
                // state legal.
                if ($run->warningCount > 0) {
                    $run->completeWithWarnings(at: $now);
                } else {
                    $run->complete(at: $now);
                }
                $this->context->runs->save($run);

                return TickResult::done();
            }

            $this->ensureCoarseStage($run, $next, $now);
            $run->recordResumeCursor($this->position($next, ''), at: $now);
            $this->context->runs->save($run);

            return TickResult::more();
        }

        $run->recordResumeCursor($this->position($stageKey, $result->cursor), at: $now);
        $this->context->runs->save($run);

        return TickResult::more();
    }

    /**
     * The current boundary and its resume cursor. An empty or unparseable
     * cursor restarts at probe.
     *
     * @return array{PipelineStageKey, string}
     */
    private function currentPosition(ExportRun $run): array
    {
        $cursor = $run->resumeCursor;
        if ($cursor === '') {
            return [PipelineStageKey::Probe, ''];
        }

        $pieces = explode('|', $cursor, 2);
        $key = PipelineStageKey::tryFrom($pieces[0]);

        return $key === null ? [PipelineStageKey::Probe, ''] : [$key, $pieces[1] ?? ''];
    }

    private function stage(PipelineStageKey $key, string $runId, RunSettings $settings): PipelineStage
    {
        return $this->context->stage($key, $runId, $settings);
    }

    /**
     * Rehydrate the shared PipelineState's Probe/Setup/Discover/Capture/
     * Rewrite/Pack/Wrapup/Publish slots from the persisted run so a fresh
     * WP-Cron request resumes where the previous one stopped instead of
     * re-running the probe/setup/discovery/capture/rewrite/pack/wrap-up/
     * publish process.
     */
    private function hydrateState(ExportRun $run): void
    {
        $state = $this->context->state;
        $state->probe = $run->probe;
        $state->setup = $run->setup;
        $state->discover = $run->discover;
        $state->capture = $run->capture;
        $state->rewrite = $run->rewrite;
        $state->pack = $run->pack;
        $state->wrapup = $run->wrapup;
        $state->publish = $run->publish;
    }

    /**
     * Mirror the shared PipelineState's Probe/Setup/Discover/Capture/Rewrite/
     * Pack/Wrapup/Publish results back onto the run so the orchestrator's
     * save() persists them across WP-Cron requests.
     */
    private function mirrorState(ExportRun $run, int $now): void
    {
        $state = $this->context->state;
        if ($state->probe !== null) {
            $run->recordProbe($state->probe, at: $now);
        }
        if ($state->setup !== null) {
            $run->recordSetup($state->setup, at: $now);
        }
        if ($state->discover !== null) {
            $run->recordDiscover($state->discover, at: $now);
        }
        if ($state->capture !== null) {
            $run->recordCapture($state->capture, at: $now);
        }
        if ($state->rewrite !== null) {
            $run->recordRewrite($state->rewrite, at: $now);
        }
        if ($state->pack !== null) {
            $run->recordPack($state->pack, at: $now);
        }
        if ($state->wrapup !== null) {
            $run->recordWrapup($state->wrapup, at: $now);
        }
        if ($state->publish !== null) {
            $run->recordPublishBoundary($state->publish, at: $now);
            // A completed publish also wires the run's top-level publish
            // identifiers so status reports carry the CID/website/IPNS identity.
            // The boundary guarantees a Completed outcome has all three.
            if ($state->publish->isSuccess()) {
                $cid = $state->publish->cid;
                $websiteId = $state->publish->websiteId;
                $ipnsKey = $state->publish->ipnsKey;
                if ($cid !== null && $websiteId !== null && $ipnsKey !== null) {
                    $run->recordPublishIdentifiers($cid, $websiteId, $ipnsKey, at: $now);
                }
            }
        }
    }

    private function position(PipelineStageKey $key, string $cursor): string
    {
        return $key->value . '|' . $cursor;
    }

    private function ensureCoarseStage(ExportRun $run, PipelineStageKey $key, int $now): void
    {
        $coarse = $key->coarseRunStage();
        if ($run->stage === $coarse) {
            return;
        }

        $run->advanceStage($coarse, at: $now);
    }

    private function recordOutcome(ExportRun $run, StageResult $result, int $now): void
    {
        if ($result->progress > 0) {
            $run->recordProgress($run->progressCount + $result->progress, at: $now);
        }

        foreach ($result->warnings as $warning) {
            $run->recordWarning($warning, at: $now);
        }
    }
}
