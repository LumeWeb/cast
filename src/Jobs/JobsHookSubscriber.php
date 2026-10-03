<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

use Closure;
use ComposePress\Core\HookSubscriber;
use ComposePress\Core\Hooks;
use LumeWeb\Cast\Admin\PublishedContentProbe;
use LumeWeb\Cast\Admin\WordPressPublishedContentProbe;

/**
 * Registers the background publish-job hooks.
 *
 *  - the export tick hook fires one {@see ExportTickRunner} tick (WP-Cron);
 *  - the follow-up hook re-runs the debounce/squash scheduler;
 *  - transition_post_status marks content dirty for publish-relevant public
 *    transitions only — autosaves, revisions and non-public status traffic
 *    are ignored unless a published item becomes unavailable.
 *
 * The transition gate consults a {@see PublishedContentProbe} (the WordPress
 * probe by default, injectable in tests) for ENTERING-publish transitions
 * only: while it answers that no publish-eligible public content exists —
 * e.g. a fresh install whose only published items are the WordPress factory
 * defaults — an entering-publish transition is ignored (never
 * marked/scheduled). Leaving-publish transitions (unpublish/trash) are never
 * gated: the probe queries the site after the transition, so trashing the
 * last genuine post would answer "no eligible content" and the removal would
 * never be exported.
 *
 * The save-path callback only marks/schedules through the pure scheduler; no
 * heavy work runs inside the post-save hook. Only actions are registered — no
 * front-end content filters.
 *
 * When a {@see RetentionScheduler} is wired, the retention hook is registered
 * and a terminal tick outcome arms exactly one (deduplicated) retention sweep;
 * the retention handler itself runs the {@see RetentionRunner}, which re-arms
 * its own periodic sweep. Without a retention scheduler nothing extra is
 * registered or armed, so the un-wired subscriber stays byte-for-byte the
 * pre-retention behaviour.
 *
 * When a capture-worker factory is wired, the {@see CaptureWorkerScheduler::WORKER_HOOK}
 * action is registered and its handler runs exactly one bounded one-item
 * capture worker for the (runId, slot) event identity, then coaxes the normal
 * auto-tick so the coordinator keeps the run moving. The coax is a plain
 * single-event schedule the tick scheduler dedupes, so it can never stack a
 * tick storm. Without a factory nothing extra is registered and a stray
 * worker event is ignored, so the un-wired subscriber stays the pre-worker
 * behaviour.
 */
final class JobsHookSubscriber implements HookSubscriber
{
    public const TRANSITION_HOOK = 'transition_post_status';

    /**
     * Emitted (with hook, intended instant and outcome kind) when a
     * rearm-worthy tick could not arm its next auto-tick, so a site can
     * observe/route a loop that is at risk of going quiet.
     */
    public const REARM_FAILED_HOOK = 'cast_export_tick_rearm_failed';

    /**
     * How soon after a Ran/Retried/Watchdog tick the next auto-tick is armed.
     * Kept small so a bounded run keeps moving, while still giving WP-Cron a
     * real timestamp (never `now`) so events cannot pile up into a storm.
     */
    public const DEFAULT_TICK_REARM_DELAY_SECONDS = 2;

    /**
     * How soon a transiently-stalled tick (a queued run parked on identity, or
     * a lock the worker could not take) is retried. Slower than the progress
     * cadence so a parked run — e.g. a first publish waiting on identity —
     * polls cheaply instead of spinning, while still self-healing the instant
     * the blocker clears. There is always exactly one pending event (the
     * scheduler dedupes on hook + args), so this can never build a storm.
     */
    public const DEFAULT_DEFERRED_REARM_DELAY_SECONDS = 60;

    public function __construct(
        private readonly ExportTickRunner $tickRunner,
        private readonly ContentPublishScheduler $scheduler,
        private readonly Scheduler $tickScheduler,
        private readonly Clock $clock,
        private readonly int $rearmDelaySeconds = self::DEFAULT_TICK_REARM_DELAY_SECONDS,
        private readonly ?RetentionScheduler $retentionScheduler = null,
        private readonly ?RetentionRunner $retentionRunner = null,
        private readonly int $deferredRearmDelaySeconds = self::DEFAULT_DEFERRED_REARM_DELAY_SECONDS,
        // Content-eligibility gate for the transition hook: answers whether
        // the site has publish-eligible public content beyond the WordPress
        // factory defaults, so a fresh install's entering-publish
        // transitions on the factory post/page never reach the scheduler.
        // Defaults to the real WordPress probe; injectable so tests can
        // script the site's published content.
        private readonly PublishedContentProbe $contentProbe = new WordPressPublishedContentProbe(),
        // Builds the one-item capture worker a worker event runs: receives
        // the (runId, slot) event identity so the worker carries a
        // slot-specific claim token. Null keeps the pre-worker subscriber.
        private readonly ?Closure $captureWorkerFactory = null,
    ) {
    }

    public function subscribe(Hooks $hooks): void
    {
        $hooks->action(ContentPublishScheduler::AUTO_HOOK, [$this, 'runTick']);
        $hooks->action(ContentPublishScheduler::FOLLOW_UP_HOOK, [$this, 'runFollowUp']);
        $hooks->action(self::TRANSITION_HOOK, [$this, 'onPostTransition'], 10, 3);
        if ($this->retentionScheduler !== null) {
            $hooks->action(RetentionScheduler::RETENTION_HOOK, [$this, 'runRetention']);
        }
        if ($this->captureWorkerFactory !== null) {
            $hooks->action(CaptureWorkerScheduler::WORKER_HOOK, [$this, 'runCaptureWorker'], 10, 2);
        }
    }

    public function runTick(): void
    {
        $now = $this->clock->now();
        // Deliberately unpinned: the runner reads the wall clock per unit so a
        // batched tick stamps each unit with its real instant, while the
        // captured $now above anchors the rearm and retention scheduling.
        $outcome = $this->tickRunner->tick();
        $this->rearm($outcome, $now);
        $this->armRetention($outcome, $now);
    }

    /**
     * The WP-Cron retention handler: run one artifact GC sweep through the
     * wired {@see RetentionRunner}. A no-op when retention is not wired.
     */
    public function runRetention(): void
    {
        $this->retentionRunner?->run();
    }

    /**
     * The capture-worker handler: run exactly one bounded one-item worker for
     * the (runId, slot) event identity, then coax the normal auto-tick so
     * the coordinator records the capture progress and keeps advancing. The
     * coax is a plain single-event schedule the tick scheduler dedupes on
     * (hook, args), so an already-pending auto-tick is never stacked and a
     * duplicate fanout cannot build a tick storm. Stale and malformed events
     * (missing run id, slot below one, a run that moved on or went
     * terminal) build no worker and coax nothing: the worker's own
     * validation is the guard, and a skip coaxes no tick.
     */
    public function runCaptureWorker(?string $runId = null, int $slot = 0): void
    {
        if ($this->captureWorkerFactory === null) {
            return;
        }

        if ($runId === null || $runId === '' || $slot < 1) {
            return;
        }

        $worker = ($this->captureWorkerFactory)($runId, $slot);
        if (!$worker instanceof CaptureWorker) {
            return;
        }

        $outcome = $worker->work($runId);
        if ($outcome->kind !== CaptureWorkerOutcomeKind::Captured) {
            return;
        }

        try {
            $this->tickScheduler->scheduleSingle(
                ContentPublishScheduler::AUTO_HOOK,
                $this->clock->now() + $this->rearmDelaySeconds,
            );
        } catch (SchedulingFailedException) {
            // The action backend declined (Action Scheduler unavailable): the
            // capture is already persisted in the work queue, and the next
            // debounce or rearmed tick picks the run up. Nothing else to do
            // here — the failure must not escape the worker callback.
        }
    }

    /**
     * Arm exactly one retention sweep after a run reached a terminal status —
     * Completed, Failed or Cancelled. The arming is deduplicated by the
     * scheduler (hook + args), so a sweep already pending never stacks, and an
     * auto-tick rearm is never triggered behind a terminal run (the follow-up
     * / periodic retention machinery owns the loop from there).
     */
    private function armRetention(TickOutcome $outcome, int $now): void
    {
        if ($this->retentionScheduler === null) {
            return;
        }

        if (!$outcome->kind->isTerminal()) {
            return;
        }

        $this->retentionScheduler->armAfterTerminal($now);
    }

    /**
     * Arm exactly one next auto-tick when the outcome left more work behind.
     *
     * Deduplication is left to the {@see Scheduler} (events are keyed by hook
     * + args, exactly like WP-Cron), so a debounce or an already-armed rearm
     * wins and nothing can stack into a tick storm. Terminal outcomes never
     * rearm (Completed/Failed/Cancelled hand the loop over to the
     * follow-up/debounce scheduler), and genuinely idle skips (no run, or a
     * terminal/paused/not-dirty run) rearm nothing — a skip that leaves a
     * pending dirty run behind DOES rearm so the loop survives it.
     */
    private function rearm(TickOutcome $outcome, int $now): void
    {
        if (!$this->shouldRearm($outcome)) {
            return;
        }

        $at = $now + $this->rearmDelayFor($outcome);
        try {
            $armed = $this->tickScheduler->scheduleSingle(
                ContentPublishScheduler::AUTO_HOOK,
                $at,
            );
        } catch (SchedulingFailedException $exception) {
            // The real scheduler backend throws instead of returning false when
            // Action Scheduler is unavailable or declines the insert (see
            // WordPressActionScheduler). Route that to the same warning path so
            // the failure never escapes the AUTO_HOOK callback and silently
            // marks the action failed, bypassing this 'loop goes silent' guard.
            $this->warnRearmFailed($outcome, $at);
            return;
        }

        // A rearm-worthy outcome that still could not arm a next tick is the
        // exact production dead end this pipeline guards against: no debounce,
        // no retry, no watchdog — the loop goes silent with a dirty run parked.
        // Surface it (the scheduler refused, or a pending tick already claims
        // the slot so the loop may be about to stall) without mutating run
        // state; operators can route the action hook to their logger.
        if (!$armed) {
            $this->warnRearmFailed($outcome, $at);
        }
    }

    /**
     * Report a refused rearm as a lightweight warning. Emits an
     * `cast_export_tick_rearm_failed` action carrying the hook, intended
     * instant and outcome kind so a site can observe/route it; run state is
     * deliberately untouched here.
     */
    private function warnRearmFailed(TickOutcome $outcome, int $at): void
    {
        error_log(sprintf(
            '[cast] tick rearm failed for "%s" at %d (kind %s): a pending tick may already be armed or the scheduler refused the work',
            ContentPublishScheduler::AUTO_HOOK,
            $at,
            $outcome->kind->value,
        ));
        do_action(self::REARM_FAILED_HOOK, ContentPublishScheduler::AUTO_HOOK, $at, $outcome->kind->value);
    }

    /**
     * The delay before the next auto-tick for a given outcome. Progress
     * outcomes use the fast cadence; a transiently-stalled tick that still
     * left a queued run behind (deferred on identity, lock contended, stale
     * lock) retries on the slower {@see self::DEFAULT_DEFERRED_REARM_DELAY_SECONDS}
     * so a parked run polls cheaply without spinning.
     */
    private function rearmDelayFor(TickOutcome $outcome): int
    {
        return match ($outcome->kind) {
            TickOutcomeKind::Ran,
            TickOutcomeKind::Retried,
            TickOutcomeKind::Watchdog => $this->rearmDelaySeconds,
            default => $this->deferredRearmDelaySeconds,
        };
    }

    /**
     * Whether a tick outcome leaves more work that a later tick should pick up:
     * `Ran` (one bounded unit ran, more remains), `Retried` (a failure was
     * recorded but the retry budget is not spent), `Watchdog` (a stale run was
     * signalled and a worker must reclaim it), `Deferred` (a queued run is
     * parked because no identity exists yet — keep a tick armed so it moves
     * the moment identity arrives) and the lock skips (a transiently-busy
     * worker — retry it). Terminal outcomes (Completed / Failed / Cancelled)
     * never rearm — the terminal path hands the loop over to the
     * follow-up/debounce scheduler. A skip with no run behind it (SkippedNoRun)
     * or a genuinely idle run (SkippedNotReady — terminal, paused, or not
     * dirty) is a stable no-op and rearms nothing.
     */
    public function shouldRearm(TickOutcome $outcome): bool
    {
        return match ($outcome->kind) {
            TickOutcomeKind::Ran,
            TickOutcomeKind::Retried,
            TickOutcomeKind::Watchdog,
            TickOutcomeKind::Deferred,
            TickOutcomeKind::SkippedLocked,
            TickOutcomeKind::SkippedStaleLock => true,
            default => false,
        };
    }

    public function runFollowUp(): void
    {
        $this->scheduler->onFollowUp();
    }

    /**
     * Marks content dirty (and later schedules) only for publish-relevant
     * public transitions. WordPress fires this for every status change,
     * including autosaves and revisions, so the pure decision helpers filter
     * the delegation.
     *
     * @param object $post The WP_Post being transitioned.
     */
    public function onPostTransition(string $newStatus, string $oldStatus, object $post): void
    {
        if (!$this->isPublishRelevantTransition($newStatus, $oldStatus)) {
            return;
        }

        if ($this->isAutosaveOrRevision($post)) {
            return;
        }

        // A fresh WordPress install fires this hook for the factory-default
        // "Hello world!" post and "Sample Page" page as they ENTER publish,
        // which are not user content: while the probe sees nothing beyond the
        // factory defaults, an entering-publish transition must not reach the
        // scheduler at all. Leaving-publish transitions (unpublish/trash) are
        // deliberately NOT gated: the probe runs after the transition, so
        // trashing the last genuine post would answer "no eligible content"
        // and the removal would never be exported.
        if ($newStatus === 'publish' && !$this->contentProbe->hasEligibleContent()) {
            return;
        }

        // Only mark/schedule — the debounce and identity checks live in the
        // scheduler, keeping the save path free of long work.
        $this->scheduler->onContentPublished();
    }

    /**
     * A transition matters when content enters or leaves the public view:
     * anything touching `publish`. Draft/private/trash/revision traffic that
     * never involves a published item is ignored.
     */
    public function isPublishRelevantTransition(string $newStatus, string $oldStatus): bool
    {
        return $newStatus === 'publish' || $oldStatus === 'publish';
    }

    /**
     * Revisions and autosaves carry the `inherit`/`revision` markers and must
     * never enqueue, even if the surrounding statuses look publish-relevant.
     *
     * @param object $post
     */
    public function isAutosaveOrRevision(object $post): bool
    {
        return ($post->post_type ?? '') === 'revision'
            || ($post->post_status ?? '') === 'inherit';
    }
}
