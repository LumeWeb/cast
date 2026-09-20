<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

use LumeWeb\Cast\Export\ExportRun;
use LumeWeb\Cast\Export\RunRepository;
use LumeWeb\Cast\Export\RunSettings;
use LumeWeb\Cast\Export\RunStatus;
use LumeWeb\Cast\Export\WorkItemRepository;

/**
 * Debounce/squash scheduling for background export runs.
 *
 * Content publishing marks a run dirty and schedules exactly one single event
 * after a quiet period (10 minutes by default); repeated events never stack
 * duplicates. When a run is already active the new content supersedes it and
 * one follow-up is armed to run once the overtaken run reaches a terminal
 * state. Before the first website/IPNS identity exists nothing is ever
 * auto-scheduled — manual first publish stays the only explicit starter.
 *
 * All state goes through the {@see RunRepository}; nothing in this module
 * touches WordPress globals, registrations or credentials. The WordPress
 * adapters for {@see Scheduler} and {@see IdentityGateway} are a later stage.
 */
final class ContentPublishScheduler
{
    public const DEFAULT_QUIET_SECONDS = 600;

    public const DEFAULT_FOLLOW_UP_DELAY_SECONDS = 5;

    public const AUTO_HOOK = 'cast/export/auto-tick';

    public const FOLLOW_UP_HOOK = 'cast/export/follow-up';

    private int $sequence = 0;

    public function __construct(
        private Clock $clock,
        private RunRepository $repository,
        private Scheduler $scheduler,
        private IdentityGateway $identity,
        private PublishModeStore $modeStore,
        private WorkItemRepository $workItems,
        private int $quietSeconds = self::DEFAULT_QUIET_SECONDS,
        private int $followUpDelaySeconds = self::DEFAULT_FOLLOW_UP_DELAY_SECONDS,
    ) {
    }

    /**
     * React to a published/updated post.
     */
    public function onContentPublished(?int $at = null): PublishScheduleState
    {
        $now = $at ?? $this->clock->now();

        if ($this->modeStore->mode() === PublishMode::Manual) {
            // Manual mode is drift-only: content events record dirty but never
            // schedule, supersede or arm a follow-up.
            $this->recordManualDrift($now);

            return PublishScheduleState::Held;
        }

        $run = $this->repository->latest();

        if ($run !== null && !$run->isTerminal() && !$run->superseded) {
            if ($run->status === RunStatus::NotStarted) {
                // A queued, not-yet-started run absorbs new content directly.
                $run->markDirty(at: $now);
                $this->repository->save($run);

                // Same invariant as ensureFreshRunAndSchedule: the pending
                // queued run keeps a debounce armed (deferred outcome until
                // identity arrives), so it is never orphaned without a tick.
                $scheduled = $this->scheduleDebounce($now);

                if (!$this->identity->hasIdentity()) {
                    return PublishScheduleState::Deferred;
                }

                return $scheduled;
            }

            // A started run is overtaken: supersede it and wait for terminal.
            $run->notifyContentChanged(at: $now);
            $this->repository->save($run);
            $this->scheduleFollowUp($now);

            return PublishScheduleState::Superseded;
        }

        if ($run !== null && !$run->isTerminal()) {
            // Already superseded but still live: keep one follow-up armed.
            $this->scheduleFollowUp($now);

            return PublishScheduleState::Waiting;
        }

        return $this->ensureFreshRunAndSchedule($now);
    }

    /**
     * Manual-run-wins "publish now": schedule a tick immediately.
     *
     * A queued NotStarted run is the work itself, so it is absorbed (its
     * quiet-period debounce is cancelled) and the SAME run ticks now. When no
     * live run exists a fresh dirty run is created and also ticks now. Only an
     * actively executing (Running/Paused) or superseded run is refused — there
     * is no second run to start and the follow-up machinery already owns the
     * coalesced re-publish. The invariant is exactly one active run plus at
     * most one coalesced follow-up.
     */
    public function startNow(?int $at = null): PublishNowResult
    {
        $now = $at ?? $this->clock->now();
        $run = $this->repository->latest();

        if ($run !== null && !$run->isTerminal()) {
            if ($run->superseded) {
                return new PublishNowResult(false);
            }

            if ($run->status === RunStatus::NotStarted) {
                // Absorb the queued auto work: it stays the single active run,
                // and the user's explicit click marks it so a tick may begin it
                // even before a publish identity exists (first publish / stuck
                // queued recovery — the escape from a deferred run).
                $run->markDirty(at: $now);
                $run->markExplicitStart(at: $now);
                $this->repository->save($run);
            } else {
                // Actively executing: refuse rather than stack a second run.
                return new PublishNowResult(false);
            }

            $this->scheduleImmediately($now);

            return new PublishNowResult(true, $run->runId);
        }

        // No live run (or only a terminal record): start a fresh dirty run,
        // explicitly (the user asked for it) so the first publish proceeds
        // without identity.
        $this->ensurePendingRun($now, explicit: true);
        $fresh = $this->repository->latest();
        $this->scheduleImmediately($now);

        return new PublishNowResult(true, $fresh?->runId);
    }

    /**
     * Clear every pending publish event (quiet-period debounce and coalesced
     * follow-up), safe to call when none is scheduled.
     *
     * Used by the explicit cancel path so cancelling a live run stops any ARM
     * auto-tick or re-publish follow-up that belongs to it.
     */
    public function clearPending(): void
    {
        $this->scheduler->cancelSingle(self::AUTO_HOOK);
        $this->scheduler->cancelSingle(self::FOLLOW_UP_HOOK);
    }

    /**
     * Seed a publish-only run that reuses an intact existing artifact.
     *
     * The terminal source run (which owns the packed ZIP + manifest) is
     * replaced by one fresh NotStarted run carrying the same settings snapshot
     * and the intact pack, jumping straight to the publish boundary
     * (resumeCursor 'publish|') so a later tick publishes the existing
     * artifact without re-exporting. Any previously recorded publish
     * identifiers (CID/website/IPNS) are carried over so the status surface
     * keeps reporting the last published state while the replay is queued.
     * Exactly one immediate AUTO_HOOK tick is scheduled; no follow-up is armed
     * (single active run, single-run-slot semantics).
     */
    public function publishExisting(ExportRun $source, ?int $at = null): PublishNowResult
    {
        $now = $at ?? $this->clock->now();

        // Clear the terminal slot so the single-option storage has room.
        $this->repository->delete($source->runId);

        $seeded = ExportRun::create($this->nextRunId($now), $source->settings, at: $now);
        if ($source->pack !== null) {
            $seeded->recordPack($source->pack, at: $now);
        }
        $seeded->recordResumeCursor(ExportRun::RESUME_PUBLISH_ONLY, at: $now);
        $seeded->markDirty(at: $now);
        // The artifact republish is an explicit user action, not auto work.
        $seeded->markExplicitStart(at: $now);
        if ($source->publishCid !== null && $source->websiteId !== null && $source->ipnsKey !== null) {
            $seeded->recordPublishIdentifiers($source->publishCid, $source->websiteId, $source->ipnsKey, at: $now);
        }
        $this->repository->create($seeded);

        $this->scheduleImmediately($now);

        return new PublishNowResult(true, $seeded->runId);
    }

    /**
     * React to a follow-up event: fold pending content into a fresh run once
     * the overtaken run has reached a terminal state.
     */
    public function onFollowUp(?int $at = null): PublishScheduleState
    {
        if ($this->modeStore->mode() === PublishMode::Manual) {
            // Manual mode never auto-schedules, so a stray follow-up fires into
            // the held state without arming a fresh debounce.
            return PublishScheduleState::Held;
        }

        $now = $at ?? $this->clock->now();
        $run = $this->repository->latest();

        if ($run !== null && !$run->isTerminal()) {
            $this->scheduleFollowUp($now);

            return PublishScheduleState::Waiting;
        }

        return $this->ensureFreshRunAndSchedule($now);
    }

    private function ensureFreshRunAndSchedule(int $now): PublishScheduleState
    {
        $this->ensurePendingRun($now);

        // The pending dirty queued run must ALWAYS keep a debounce tick armed
        // in auto mode — even before identity exists. When it fires the worker
        // reports Deferred and re-arms on the slow cadence, so a queued run can
        // never be orphaned without a scheduled event (the field observing a
        // "not_started + dirty + no tick" dead-end). Once identity appears the
        // same armed tick auto-starts the run. Identity still gates the EVENT
        // outcome, never the arming; the first publish stays manual because a
        // run cannot start until identity exists or the user explicitly clicks.
        $scheduled = $this->scheduleDebounce($now);

        if (!$this->identity->hasIdentity()) {
            return PublishScheduleState::Deferred;
        }

        return $scheduled;
    }

    /**
     * Record content drift under Manual mode.
     *
     * A live run just accumulates the dirty flag; a terminal (or absent) record
     * is replaced by a single fresh pending dirty run so the dashboard can show
     * the drift. Nothing is ever scheduled, superseded or followed up.
     */
    private function recordManualDrift(int $now): void
    {
        $run = $this->repository->latest();
        if ($run !== null && !$run->isTerminal()) {
            $run->markDirty(at: $now);
            $this->repository->save($run);

            return;
        }

        $this->ensurePendingRun($now);
    }

    /**
     * Guarantee a single pending (NotStarted, dirty) run exists, clearing any
     * terminal record first so the single-option storage has room.
     *
     * An explicitly-created run (an explicit Publish action) is marked as
     * user-started so a tick may begin it before a publish identity exists;
     * drift-only runs and auto-created runs are left implicit so the worker
     * keeps them deferred until identity arrives.
     */
    private function ensurePendingRun(int $now, bool $explicit = false): void
    {
        $run = $this->repository->latest();
        if ($run !== null && !$run->isTerminal()) {
            return;
        }

        if ($run !== null) {
            $this->repository->delete($run->runId);
        }

        $pending = ExportRun::create($this->nextRunId($now), new RunSettings(), at: $now);
        $pending->markDirty(at: $now);
        if ($explicit) {
            $pending->markExplicitStart(at: $now);
        }
        $this->repository->create($pending);

        // A NEW run id was just persisted, so the work-item queue is cleared
        // here and only here: the next discovery refills it, and the prior
        // (terminal, cancelled or failed) run's done/rewritten rows must not
        // survive — insertCanonical is first-seen-wins and never re-queues a
        // finished row, so a stale row would starve the fresh per-run work
        // directory and pack would fail with "Work tree has no root
        // index.html". Mid-run absorption (startNow on a queued NotStarted
        // run, publishExisting replay, ticks and status reads) never reaches
        // this branch and never clears.
        $this->workItems->clear();
    }

    private function scheduleDebounce(int $now): PublishScheduleState
    {
        if ($this->scheduler->isScheduled(self::AUTO_HOOK)) {
            return PublishScheduleState::Folded;
        }

        $this->scheduler->scheduleSingle(self::AUTO_HOOK, $now + $this->quietSeconds);

        return PublishScheduleState::Scheduled;
    }

    /**
     * Queue the auto-tick at the given instant, cancelling any quiet-period
     * debounce first so the manual run wins and no duplicate event remains.
     */
    private function scheduleImmediately(int $now): void
    {
        $this->scheduler->cancelSingle(self::AUTO_HOOK);
        $this->scheduler->scheduleSingle(self::AUTO_HOOK, $now);
    }

    private function scheduleFollowUp(int $now): void
    {
        if (!$this->scheduler->isScheduled(self::FOLLOW_UP_HOOK)) {
            $this->scheduler->scheduleSingle(self::FOLLOW_UP_HOOK, $now + $this->followUpDelaySeconds);
        }
    }

    private function nextRunId(int $now): string
    {
        return sprintf('run-%d-%d', $now, ++$this->sequence);
    }
}
