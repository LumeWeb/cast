<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Jobs;

use ComposePress\Core\Testing\RecordingHooks;
use LumeWeb\Cast\Export\ExportRun;
use LumeWeb\Cast\Export\InMemoryRunRepository;
use LumeWeb\Cast\Export\InMemoryWorkItemRepository;
use LumeWeb\Cast\Export\RunSettings;
use LumeWeb\Cast\Export\RunStatus;
use LumeWeb\Cast\Jobs\PublishScheduleState;
use LumeWeb\Cast\Jobs\ContentPublishScheduler;
use LumeWeb\Cast\Jobs\ExportTickRunner;
use LumeWeb\Cast\Jobs\FixedClock;
use LumeWeb\Cast\Jobs\InMemoryIdentityGateway;
use LumeWeb\Cast\Jobs\InMemoryLock;
use LumeWeb\Cast\Jobs\InMemoryPublishModeStore;
use LumeWeb\Cast\Jobs\InMemoryScheduler;
use LumeWeb\Cast\Jobs\JobsHookSubscriber;
use LumeWeb\Cast\Jobs\PublishMode;
use LumeWeb\Cast\Jobs\RetentionRunner;
use LumeWeb\Cast\Jobs\RetentionScheduler;
use LumeWeb\Cast\Jobs\Scheduler;
use LumeWeb\Cast\Jobs\SchedulingFailedException;
use LumeWeb\Cast\Jobs\TickConfig;
use LumeWeb\Cast\Export\ArtifactRetentionService;
use LumeWeb\Cast\Export\RetentionPolicy;
use LumeWeb\Cast\Tests\Unit\Export\FakeArtifactStore;
use LumeWeb\Cast\Jobs\TickOutcome;
use LumeWeb\Cast\Jobs\TickResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class JobsHookSubscriberTest extends TestCase
{
    private const LOCK_KEY = 'cast:export-tick';

    private FixedClock $clock;

    private InMemoryLock $lock;

    private InMemoryRunRepository $repository;

    private InMemoryScheduler $scheduler;

    private InMemoryIdentityGateway $identity;

    private FakeTick $tick;

    private ContentPublishScheduler $content;

    private JobsHookSubscriber $subscriber;

    /**
     * The error_log target for the duration of a test, so rearm warnings the
     * subscriber emits land in a per-test file instead of the console.
     */
    private string $errorLogFile;

    /**
     * @var string|false
     */
    private string|false $oldErrorLog;

    protected function setUp(): void
    {
        $this->clock = new FixedClock(1000);
        $this->errorLogFile = tempnam(sys_get_temp_dir(), 'cast-tick-log-');
        $this->oldErrorLog = ini_set('error_log', $this->errorLogFile);
        $this->lock = new InMemoryLock($this->clock);
        $this->repository = new InMemoryRunRepository();
        $this->scheduler = new InMemoryScheduler();
        $this->identity = new InMemoryIdentityGateway(true);
        $this->tick = new FakeTick();

        $runner = new ExportTickRunner(
            clock: $this->clock,
            lock: $this->lock,
            repository: $this->repository,
            tick: $this->tick,
            identity: $this->identity,
            config: new TickConfig(lockKey: self::LOCK_KEY, lockTtlSeconds: 60),
        );
        // These fixtures exercise the debounce/squash scheduling path, which is
        // the OnUpdate behaviour; Manual drift is covered elsewhere.
        $this->content = new ContentPublishScheduler(
            clock: $this->clock,
            repository: $this->repository,
            scheduler: $this->scheduler,
            identity: $this->identity,
            modeStore: new InMemoryPublishModeStore(PublishMode::OnUpdate),
            workItems: new InMemoryWorkItemRepository(),
        );

        $this->subscriber = new JobsHookSubscriber(
            $runner,
            $this->content,
            $this->scheduler,
            $this->clock,
            rearmDelaySeconds: 2,
        );
    }

    protected function tearDown(): void
    {
        if ($this->oldErrorLog !== false) {
            ini_set('error_log', $this->oldErrorLog);
        }
        @unlink($this->errorLogFile);
        $GLOBALS['lumeweb_cast_actions_fired'] = [];
    }

    public function testRegistersCronAndTransitionActionsOnly(): void
    {
        $hooks = new RecordingHooks();
        $this->subscriber->subscribe($hooks);

        self::assertSame(
            [
                ContentPublishScheduler::AUTO_HOOK,
                ContentPublishScheduler::FOLLOW_UP_HOOK,
                JobsHookSubscriber::TRANSITION_HOOK,
            ],
            $hooks->actionNames(),
        );
        self::assertSame([], $hooks->filterNames());
    }

    public function testTickHookInvokesTheTickRunnerUnderTheLease(): void
    {
        $this->storePendingDirtyRun('run-1');

        $this->subscriber->runTick();

        self::assertSame(1, $this->tick->calls);
        self::assertSame('run-1', $this->tick->lastRunId);
        self::assertFalse($this->lock->isHeld(self::LOCK_KEY));
    }

    /**
     * The rearm predicate arms a next tick only when a bounded tick left more
     * work behind (Ran / Retried / Watchdog) — never after a terminal outcome
     * and never after a skip. This is the pure decision boundary the
     * auto-tick handler uses before touching the scheduler.
     *
     * @param TickOutcome $outcome
     * @param bool $expected
     */
    #[DataProvider('rearmOutcomes')]
    public function testShouldRearmArmsOnlyForWorkRemainingOutcomes(TickOutcome $outcome, bool $expected): void
    {
        self::assertSame($expected, $this->subscriber->shouldRearm($outcome), $outcome->kind->value);
    }

    /**
     * @return array<string, array{TickOutcome, bool}>
     */
    public static function rearmOutcomes(): array
    {
        return [
            'ran' => [TickOutcome::ran(), true],
            'retried' => [TickOutcome::retried(), true],
            'watchdog' => [TickOutcome::watchdog(), true],
            'deferred' => [TickOutcome::deferred(), true],
            'skipped_locked' => [TickOutcome::skippedLocked(), true],
            'skipped_stale_lock' => [TickOutcome::skippedStaleLock(), true],
            'completed' => [TickOutcome::completed(), false],
            'failed' => [TickOutcome::failed('boom'), false],
            'cancelled' => [TickOutcome::cancelled(), false],
            'skipped_no_run' => [TickOutcome::skippedNoRun(), false],
            'skipped_not_ready' => [TickOutcome::skippedNotReady(), false],
        ];
    }

    public function testRanTickRearmsExactlyOneNextAutoTick(): void
    {
        $this->storePendingDirtyRun('run-1');

        $this->subscriber->runTick();

        // The bounded tick ran with more work left (Ran): exactly one next
        // auto-tick is armed a short cadence after this tick's instant.
        self::assertSame(1, $this->scheduler->count());
        self::assertTrue($this->scheduler->isScheduled(ContentPublishScheduler::AUTO_HOOK));
        self::assertSame(1002, $this->scheduler->nextAt(ContentPublishScheduler::AUTO_HOOK));
    }

    public function testRetriedTickRearmsExactlyOneNextAutoTick(): void
    {
        $this->storePendingDirtyRun('run-1');
        $this->tick->result = TickResult::fail('boom');

        $this->subscriber->runTick();

        // A failure with retry budget left (Retried) keeps the loop alive.
        self::assertSame(1, $this->scheduler->count());
        self::assertTrue($this->scheduler->isScheduled(ContentPublishScheduler::AUTO_HOOK));
        self::assertSame(1002, $this->scheduler->nextAt(ContentPublishScheduler::AUTO_HOOK));
    }

    public function testWatchdogTickRearmsExactlyOneNextAutoTick(): void
    {
        $this->storePendingDirtyRun('run-1');
        $this->tick->result = TickResult::stale();

        $this->subscriber->runTick();

        // A stale run signalled to the watchdog still needs a worker tick.
        self::assertSame(1, $this->scheduler->count());
        self::assertTrue($this->scheduler->isScheduled(ContentPublishScheduler::AUTO_HOOK));
        self::assertSame(1002, $this->scheduler->nextAt(ContentPublishScheduler::AUTO_HOOK));
    }

    public function testConsecutiveTicksDoNotStackDuplicateAutoTicks(): void
    {
        $this->storePendingDirtyRun('run-1');

        $this->subscriber->runTick(); // Ran at 1000 → rearm @1002.
        $this->clock->advance(3);
        $this->subscriber->runTick(); // Ran again at 1003 → rearm attempt @1005 refused.

        // The scheduler dedupes on (hook, args): still exactly one event, still
        // at the first armed instant — no tick storm, no stacked duplicates.
        self::assertSame(1, $this->scheduler->count());
        self::assertSame(1002, $this->scheduler->nextAt(ContentPublishScheduler::AUTO_HOOK));
    }

    public function testRunTickWithTheActionMarkedRunningStillArmsTheNextTick(): void
    {
        // The exact production dead-end this fix removes: the tick is currently
        // RUNNING (WP-Cron already removed the single event, so the backend
        // reports `true`, not a timestamp), and the rearm FROM INSIDE that
        // in-flight action must be admitted as a fresh pending tick — otherwise
        // every rearm category (Ran, Retried, Watchdog, Deferred, lock-skips)
        // becomes a dead end and the loop goes quiet.
        $this->storePendingDirtyRun('run-1');
        $this->scheduler->startRunning(ContentPublishScheduler::AUTO_HOOK);

        $this->subscriber->runTick();

        self::assertSame(RunStatus::Running, $this->repository->latest()?->status);
        self::assertSame(1, $this->scheduler->count());
        self::assertTrue($this->scheduler->isScheduled(ContentPublishScheduler::AUTO_HOOK));
        self::assertSame(1002, $this->scheduler->nextAt(ContentPublishScheduler::AUTO_HOOK));
    }

    public function testRearmFailureWhileAPendingTickExistsWarnsAndDoesNotMutateRunState(): void
    {
        // Another worker/debounce already queued a tick, so this Ran outcome's
        // rearm is refused by dedupe. The refusal must surface as a warning
        // (the loop is at risk of going quiet behind a possibly-stale pending
        // tick) and must never mutate the run to "fix" it.
        $this->storePendingDirtyRun('run-1');
        $this->scheduler->scheduleSingle(ContentPublishScheduler::AUTO_HOOK, 1000);
        $GLOBALS['lumeweb_cast_actions_fired'] = [];

        $this->subscriber->runTick();

        self::assertArrayHasKey(
            JobsHookSubscriber::REARM_FAILED_HOOK,
            $GLOBALS['lumeweb_cast_actions_fired'],
        );
        $log = is_file($this->errorLogFile) ? (string) file_get_contents($this->errorLogFile) : '';
        self::assertStringContainsString('rearm failed', $log);

        // The warning path leaves the run exactly as the tick left it.
        self::assertSame(RunStatus::Running, $this->repository->latest()?->status);
        self::assertSame(1, $this->scheduler->count());
    }

    public function testSchedulerFailureOnRearmRoutesToTheWarningHookInsteadOfEscaping(): void
    {
        // Regression (real Kody finding on JobsHookSubscriber::rearm): the
        // real scheduler backend (WordPressActionScheduler) throws
        // SchedulingFailedException rather than returning false when Action
        // Scheduler is unavailable or declines. That must route to
        // warnRearmFailed (emitting REARM_FAILED_HOOK) instead of escaping the
        // AUTO_HOOK callback and silently marking the action failed — which
        // would bypass this 'loop goes silent' guard.
        $this->storePendingDirtyRun('run-1');
        $declining = new class implements Scheduler {
            public function isScheduled(string $hook, array $args = []): bool
            {
                return false;
            }

            public function scheduleSingle(string $hook, int $at, array $args = []): bool
            {
                throw new SchedulingFailedException('Action Scheduler declined');
            }

            public function cancelSingle(string $hook, array $args = []): void
            {
            }
        };
        $runner = new ExportTickRunner(
            clock: $this->clock,
            lock: $this->lock,
            repository: $this->repository,
            tick: $this->tick,
            identity: $this->identity,
            config: new TickConfig(lockKey: self::LOCK_KEY, lockTtlSeconds: 60),
        );
        $subscriber = new JobsHookSubscriber(
            $runner,
            $this->content,
            $declining,
            $this->clock,
            rearmDelaySeconds: 2,
        );
        $GLOBALS['lumeweb_cast_actions_fired'] = [];

        $subscriber->runTick();

        // The thrown scheduling failure surfaced as a rearm warning (hook
        // emitted, error logged) and never escaped the callback; run state is
        // untouched.
        self::assertArrayHasKey(
            JobsHookSubscriber::REARM_FAILED_HOOK,
            $GLOBALS['lumeweb_cast_actions_fired'],
        );
        $log = is_file($this->errorLogFile) ? (string) file_get_contents($this->errorLogFile) : '';
        self::assertStringContainsString('rearm failed', $log);
        self::assertSame(RunStatus::Running, $this->repository->latest()?->status);
    }

    public function testCompletedTickDoesNotRearm(): void
    {
        $this->storePendingDirtyRun('run-1');
        $this->tick->mutate = function (ExportRun $run): void {
            $run->complete(at: $this->clock->now());
        };
        $this->tick->result = TickResult::done();

        $this->subscriber->runTick();

        self::assertSame(0, $this->scheduler->count());
        self::assertTrue($this->repository->latest()?->isTerminal());
    }

    public function testFailedTickDoesNotRearm(): void
    {
        $run = ExportRun::create('run-1', new RunSettings(hostname: 'blog.example.test', maxRetries: 0), at: 1000);
        $run->markDirty(at: 1000);
        $this->repository->save($run);
        $this->tick->result = TickResult::fail('boom');

        $this->subscriber->runTick();

        self::assertSame(0, $this->scheduler->count());
        self::assertSame(RunStatus::Failed, $this->repository->latest()?->status);
    }

    public function testNoRunAndNotReadySkipsNeverRearm(): void
    {
        // No run stored → SkippedNoRun: nothing to keep a tick alive for.
        $this->subscriber->runTick();
        self::assertSame(0, $this->scheduler->count());

        // A pending run that is NOT dirty → SkippedNotReady: genuinely idle.
        $run = ExportRun::create('run-idle', new RunSettings(hostname: 'blog.example.test'), at: 1000);
        $this->repository->save($run);
        $this->subscriber->runTick();
        self::assertSame(0, $this->scheduler->count());
    }

    public function testLockContendedTickRearmsOnTheSlowCadence(): void
    {
        // Another worker holds the lease → SkippedLocked. The pending dirty
        // run may still progress once the lock frees, so the loop MUST rearm
        // (slow cadence) instead of orphaning the queued run with no tick.
        $this->storePendingDirtyRun('run-1');
        $this->lock->acquire(self::LOCK_KEY, 60);

        $this->subscriber->runTick();

        // Exactly one rearmed event, on the slow deferred cadence.
        self::assertSame(1, $this->scheduler->count());
        self::assertTrue($this->scheduler->isScheduled(ContentPublishScheduler::AUTO_HOOK));
        self::assertSame(1000 + 60, $this->scheduler->nextAt(ContentPublishScheduler::AUTO_HOOK));
    }

    public function testDeferredTickRearmsAtTheSlowCadence(): void
    {
        // A pending dirty queued run parked on a missing identity → Deferred.
        // The loop keeps exactly one tick armed (slow cadence) so the run
        // starts the moment identity arrives.
        $this->identity->setIdentity(false);
        $this->storePendingDirtyRun('run-1');

        $this->subscriber->runTick();

        self::assertSame(1, $this->scheduler->count());
        self::assertTrue($this->scheduler->isScheduled(ContentPublishScheduler::AUTO_HOOK));
        self::assertSame(1000 + 60, $this->scheduler->nextAt(ContentPublishScheduler::AUTO_HOOK));
        self::assertSame(RunStatus::NotStarted, $this->repository->latest()?->status);
    }

    public function testDeferredQueuedRunSurvivesUntilIdentityThenAutoStarts(): void
    {
        // End-to-end regression for the reported "stuck queued, no tick, no
        // cause" state: a pending dirty run with no identity must NEVER be
        // left without a scheduled tick, and it must auto-start the moment
        // identity appears — no new content event required.
        $this->identity->setIdentity(false);
        // Auto mode content lands: pending dirty run + one debounce tick.
        $this->content->onContentPublished(at: 1000);
        self::assertSame(1, $this->scheduler->count());
        self::assertSame(1600, $this->scheduler->nextAt(ContentPublishScheduler::AUTO_HOOK));

        // The debounce fires; identity still missing → Deferred → slow rearm.
        $this->scheduler->cancelSingle(ContentPublishScheduler::AUTO_HOOK);
        $this->clock->advance(600);
        $this->subscriber->runTick();
        self::assertSame(1, $this->scheduler->count());
        self::assertSame(1600 + 60, $this->scheduler->nextAt(ContentPublishScheduler::AUTO_HOOK));
        self::assertSame(RunStatus::NotStarted, $this->repository->latest()?->status);

        // Identity arrives (e.g. after a successful manual publish). The
        // rearmed tick fires and auto-starts the pending run — the parked
        // queued state self-heals with no new content event.
        $this->identity->setIdentity(true);
        $this->scheduler->cancelSingle(ContentPublishScheduler::AUTO_HOOK);
        $this->clock->advance(60);
        $this->subscriber->runTick();
        // PHPStan narrows latest() to non-null from the earlier assertions in
        // this test (the pending run was created and is the same run the
        // rearmed tick auto-starts).
        $latest = $this->repository->latest();
        self::assertSame(RunStatus::Running, $latest->status);
    }

    public function testCancellingASupersededRunDoesNotRearmAndLeavesTheFollowUpArmed(): void
    {
        $run = $this->storePendingDirtyRun('run-1');
        $run->start(at: 900);
        $this->repository->save($run);

        // Content lands mid-run: the scheduler supersedes the active run and
        // arms exactly one follow-up, like a real publish would.
        $outcome = $this->content->onContentPublished(at: 1000);
        self::assertSame(PublishScheduleState::Superseded, $outcome);
        self::assertSame(1, $this->scheduler->count());
        self::assertTrue($this->scheduler->isScheduled(ContentPublishScheduler::FOLLOW_UP_HOOK));

        // The next auto-tick fires but the run was overtaken: the runner
        // cancels it and the outcome is Cancelled — nothing may be re-armed,
        // so the follow-up stays the only pending event.
        $this->subscriber->runTick();

        self::assertSame(RunStatus::Cancelled, $this->repository->latest()?->status);
        self::assertSame(1, $this->scheduler->count());
        self::assertTrue($this->scheduler->isScheduled(ContentPublishScheduler::FOLLOW_UP_HOOK));
        self::assertFalse($this->scheduler->isScheduled(ContentPublishScheduler::AUTO_HOOK));
    }

    public function testFollowUpAfterTerminalRunArmsOneFreshAutoTickNotARearm(): void
    {
        $run = $this->storePendingDirtyRun('run-1');
        $run->start(at: 900);
        $this->repository->save($run);
        $this->content->onContentPublished(at: 1000); // supersede → follow-up @1005.
        $superseded = $this->repository->latest();
        self::assertNotNull($superseded);
        $superseded->cancel(at: 1050);
        $this->repository->save($superseded);

        // The follow-up "fires" (WP-Cron removes single events as they run).
        $this->scheduler->cancelSingle(ContentPublishScheduler::FOLLOW_UP_HOOK);
        $this->clock->advance(50);

        $this->subscriber->runFollowUp();

        // One fresh debounce auto-tick for the new pending run, no duplicate,
        // and the follow-up is consumed.
        self::assertSame(1, $this->scheduler->count());
        self::assertTrue($this->scheduler->isScheduled(ContentPublishScheduler::AUTO_HOOK));
        self::assertSame(1650, $this->scheduler->nextAt(ContentPublishScheduler::AUTO_HOOK));
        self::assertFalse($this->scheduler->isScheduled(ContentPublishScheduler::FOLLOW_UP_HOOK));
    }

    public function testTickLoopSelfRearmsUntilCompletionThenStops(): void
    {
        // A fresh publish arms the first auto-tick (the debounce).
        $this->content->onContentPublished(at: 1000);
        self::assertSame(1600, $this->scheduler->nextAt(ContentPublishScheduler::AUTO_HOOK));

        // The debounce "fires": WP-Cron removes single events as they run.
        $this->scheduler->cancelSingle(ContentPublishScheduler::AUTO_HOOK);

        // Tick 1: the run auto-starts, Ran → rearm @1002.
        $this->subscriber->runTick();
        self::assertSame(1002, $this->scheduler->nextAt(ContentPublishScheduler::AUTO_HOOK));

        // Tick 2 (the rearmed event): Ran again → rearm @1004.
        $this->scheduler->cancelSingle(ContentPublishScheduler::AUTO_HOOK);
        $this->clock->advance(2);
        $this->subscriber->runTick();
        self::assertSame(1004, $this->scheduler->nextAt(ContentPublishScheduler::AUTO_HOOK));

        // Tick 3: the pipeline finishes cleanly → Completed → loop stops.
        $this->tick->mutate = function (ExportRun $run): void {
            $run->complete(at: $this->clock->now());
        };
        $this->tick->result = TickResult::done();
        $this->scheduler->cancelSingle(ContentPublishScheduler::AUTO_HOOK);
        $this->clock->advance(2);
        $this->subscriber->runTick();

        self::assertSame(0, $this->scheduler->count());
        self::assertTrue($this->repository->latest()?->isTerminal());
    }

    public function testFollowUpHookInvokesSchedulerLogic(): void
    {
        $this->subscriber->runFollowUp();

        $latest = $this->repository->latest();
        self::assertNotNull($latest);
        self::assertTrue($latest->dirty);
        self::assertTrue($this->scheduler->isScheduled(ContentPublishScheduler::AUTO_HOOK));
    }

    public function testPublishingMarksContentDirtyAndSchedules(): void
    {
        $this->subscriber->onPostTransition('publish', 'draft', $this->post());

        $latest = $this->repository->latest();
        self::assertNotNull($latest);
        self::assertTrue($latest->dirty);
        self::assertTrue($this->scheduler->isScheduled(ContentPublishScheduler::AUTO_HOOK));
    }

    public function testFirstPublishStaysExplicitUntilIdentityExists(): void
    {
        // Auto mode + no identity yet: the pending dirty run keeps one debounce
        // tick armed (the invariant — a queued run never exists without a
        // scheduled event), but a real tick must NOT auto-start it: the worker
        // parks it as Deferred until identity exists, so the first publish
        // stays manual.
        $this->identity->setIdentity(false);

        $this->subscriber->onPostTransition('publish', 'draft', $this->post());

        $latest = $this->repository->latest();
        self::assertNotNull($latest);
        self::assertTrue($latest->dirty);
        self::assertSame(1, $this->scheduler->count());
        self::assertTrue($this->scheduler->isScheduled(ContentPublishScheduler::AUTO_HOOK));

        // The armed debounce fires while identity is still missing: Deferred,
        // never-started — the run is not auto-published before identity, yet
        // the loop keeps exactly one slow retry armed so it self-heals later.
        $this->scheduler->cancelSingle(ContentPublishScheduler::AUTO_HOOK);
        $this->clock->advance(600);
        $this->subscriber->runTick();
        self::assertSame(RunStatus::NotStarted, $this->repository->latest()?->status);
        self::assertSame(1, $this->scheduler->count());
        self::assertTrue($this->scheduler->isScheduled(ContentPublishScheduler::AUTO_HOOK));
    }

    public function testUpdatingAPublishedPostMarksContentDirty(): void
    {
        $this->subscriber->onPostTransition('publish', 'publish', $this->post());

        self::assertTrue($this->repository->latest()?->dirty);
    }

    /**
     * @param string $newStatus
     * @param string $oldStatus
     */
    #[DataProvider('unavailabilityTransitions')]
    public function testPublishedItemBecomingUnavailableMarksContentDirty(string $newStatus, string $oldStatus): void
    {
        $this->subscriber->onPostTransition($newStatus, $oldStatus, $this->post());

        self::assertTrue($this->repository->latest()?->dirty, sprintf('%s → %s should mark dirty', $oldStatus, $newStatus));
    }

    /**
     * @param string $newStatus
     * @param string $oldStatus
     */
    #[DataProvider('nonPublicTransitions')]
    public function testNonPublicTransitionsNeverMarkDirtyOrSchedule(string $newStatus, string $oldStatus): void
    {
        $this->subscriber->onPostTransition($newStatus, $oldStatus, $this->post());

        self::assertNull($this->repository->latest(), sprintf('%s → %s must not create a run', $oldStatus, $newStatus));
        self::assertSame(0, $this->scheduler->count());
    }

    public function testAutosaveAndRevisionPostsAreNeverEnqueuedEvenOnPublishMoves(): void
    {
        $revision = (object) ['ID' => 9, 'post_type' => 'revision', 'post_status' => 'inherit'];
        $this->subscriber->onPostTransition('publish', 'draft', $revision);

        self::assertNull($this->repository->latest());
        self::assertSame(0, $this->scheduler->count());
    }

    public function testAutosaveInheritPostIsNeverEnqueued(): void
    {
        $autosave = (object) ['ID' => 10, 'post_type' => 'post', 'post_status' => 'inherit'];
        $this->subscriber->onPostTransition('publish', 'draft', $autosave);

        self::assertNull($this->repository->latest());
        self::assertSame(0, $this->scheduler->count());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unavailabilityTransitions(): array
    {
        return [
            'publish to draft' => ['draft', 'publish'],
            'publish to private' => ['private', 'publish'],
            'publish to trash' => ['trash', 'publish'],
            'publish to pending' => ['pending', 'publish'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function nonPublicTransitions(): array
    {
        return [
            'draft to draft' => ['draft', 'draft'],
            'draft to pending' => ['pending', 'draft'],
            'pending to private' => ['private', 'pending'],
            'auto draft to trash' => ['trash', 'auto-draft'],
            'private to trash' => ['trash', 'private'],
            'trash to trash' => ['trash', 'trash'],
        ];
    }

    public function testRegistersTheRetentionActionWhenARetentionSchedulerIsProvided(): void
    {
        [$subscriber] = $this->withRetention();

        $hooks = new RecordingHooks();
        $subscriber->subscribe($hooks);

        self::assertContains(
            RetentionScheduler::RETENTION_HOOK,
            $hooks->actionNames(),
        );
        // The three existing actions stay registered alongside retention.
        self::assertSame(
            [
                ContentPublishScheduler::AUTO_HOOK,
                ContentPublishScheduler::FOLLOW_UP_HOOK,
                JobsHookSubscriber::TRANSITION_HOOK,
                RetentionScheduler::RETENTION_HOOK,
            ],
            $hooks->actionNames(),
        );
        self::assertSame([], $hooks->filterNames());
    }

    public function testRunRetentionInvokesTheRunnerAndRearmsPeriodic(): void
    {
        [$subscriber, $store, $retentionScheduler] = $this->withRetention();
        // The wired clock sits at 1000, so an artifact from the deep past is
        // already far outside the 7-day window.
        $store->add('run-old.zip', -1_000_000);

        $subscriber->runRetention();

        // The GC ran against the wired runner: the expired artifact is gone and
        // the next periodic sweep is armed on the retention scheduler only.
        self::assertSame(['/jail/cast-exports/run-old.zip'], $store->deletedPaths);
        self::assertTrue($retentionScheduler->isArmed());
        // A sweep is already pending, so arming again dedupes to false.
        self::assertFalse($retentionScheduler->armAfterTerminal());
    }

    public function testCompletedTickOutcomeArmsExactlyOneRetentionSweep(): void
    {
        [$subscriber, , $retentionScheduler] = $this->withRetention();
        $this->storePendingDirtyRun('run-1');
        $this->tick->mutate = static function (ExportRun $run): void {
            $run->complete(at: 1000);
        };
        $this->tick->result = TickResult::done();

        $subscriber->runTick();

        // A finished run arms retention on its own scheduler slot; the auto-tick
        // loop itself is NOT re-armed (the tick scheduler count stays 0).
        self::assertTrue($retentionScheduler->isArmed());
        self::assertSame(0, $this->scheduler->count());
    }

    public function testFailedTickOutcomeArmsExactlyOneRetentionSweep(): void
    {
        [$subscriber, , $retentionScheduler] = $this->withRetention();
        // No retry budget: a fail() tick exhausts it and the run becomes Failed.
        $run = ExportRun::create('run-1', new RunSettings(hostname: 'blog.example.test', maxRetries: 0), at: 1000);
        $run->markDirty(at: 1000);
        $this->repository->save($run);
        $this->tick->result = TickResult::fail('boom');

        $subscriber->runTick();

        self::assertSame(RunStatus::Failed, $this->repository->latest()?->status);
        self::assertTrue($retentionScheduler->isArmed());
    }

    public function testCancelledTickOutcomeArmsExactlyOneRetentionSweep(): void
    {
        [$subscriber, , $retentionScheduler] = $this->withRetention();
        $run = $this->storePendingDirtyRun('run-1');
        $run->notifyContentChanged(at: 1000); // overtaken: the runner cancels it.
        $this->repository->save($run);

        $subscriber->runTick();

        self::assertSame(RunStatus::Cancelled, $this->repository->latest()?->status);
        self::assertTrue($retentionScheduler->isArmed());
    }

    /**
     * Ran / Retried / Watchdog outcomes never arm retention: a sweep is only
     * armed once a run actually reaches a terminal status.
     *
     * @param ?\Closure(ExportRun):void $mutate
     * @param TickResult $result
     */
    #[DataProvider('nonTerminalTickResults')]
    public function testNonTerminalTickOutcomesNeverArmRetention(?\Closure $mutate, TickResult $result): void
    {
        [$subscriber, , $retentionScheduler] = $this->withRetention();
        if ($mutate === null) {
            $this->storePendingDirtyRun('run-1');
        } else {
            $run = ExportRun::create('run-1', new RunSettings(hostname: 'blog.example.test', maxRetries: 3), at: 1000);
            $run->markDirty(at: 1000);
            $this->repository->save($run);
        }
        $this->tick->result = $result;

        $subscriber->runTick();

        self::assertFalse($retentionScheduler->isArmed());
    }

    /**
     * @return array<string, array{?\Closure(ExportRun):void, TickResult}>
     */
    public static function nonTerminalTickResults(): array
    {
        return [
            'ran' => [null, TickResult::more()],
            'retried' => [null, TickResult::fail('boom')],
            'watchdog' => [null, TickResult::stale()],
        ];
    }

    public function testSkippedTickOutcomesNeverArmRetention(): void
    {
        [$subscriber, , $retentionScheduler] = $this->withRetention();

        // No run stored → SkippedNoRun.
        $subscriber->runTick();
        self::assertFalse($retentionScheduler->isArmed());

        // Another worker holds the lease → SkippedLocked.
        $this->storePendingDirtyRun('run-1');
        $this->lock->acquire(self::LOCK_KEY, 60);
        $subscriber->runTick();
        self::assertFalse($retentionScheduler->isArmed());

        // A paused run → SkippedNotReady.
        $this->lock->release(self::LOCK_KEY);
        $run = $this->repository->latest();
        self::assertNotNull($run);
        $run->start(at: 1000);
        $run->pause(at: 1000);
        $this->repository->save($run);
        $subscriber->runTick();
        self::assertFalse($retentionScheduler->isArmed());
    }

    public function testWithoutRetentionWiringNoRetentionActionOrArmingHappens(): void
    {
        // The un-wired subscriber (as every pre-existing test constructs it)
        // must register no retention action and arm nothing on a terminal tick.
        $hooks = new RecordingHooks();
        $this->subscriber->subscribe($hooks);
        self::assertNotContains(RetentionScheduler::RETENTION_HOOK, $hooks->actionNames());

        $this->storePendingDirtyRun('run-1');
        $this->tick->mutate = static function (ExportRun $run): void {
            $run->complete(at: 1000);
        };
        $this->tick->result = TickResult::done();
        $this->subscriber->runTick();

        self::assertSame(0, $this->scheduler->count());
        self::assertSame(1, $this->tick->calls);
    }

    /**
     * Build a fully retention-wired subscriber over its own scheduler slot.
     *
     * @return array{JobsHookSubscriber, FakeArtifactStore, RetentionScheduler}
     */
    private function withRetention(): array
    {
        $retentionSchedule = new InMemoryScheduler();
        $retentionScheduler = new RetentionScheduler($retentionSchedule, $this->clock);
        $store = new FakeArtifactStore();
        $runner = new RetentionRunner(
            new ArtifactRetentionService($store, $this->repository),
            $retentionScheduler,
            $this->clock,
            static fn (): RetentionPolicy => RetentionPolicy::fromDays(7),
        );
        $tickRunner = new ExportTickRunner(
            clock: $this->clock,
            lock: $this->lock,
            repository: $this->repository,
            tick: $this->tick,
            identity: $this->identity,
            config: new TickConfig(lockKey: self::LOCK_KEY, lockTtlSeconds: 60),
        );
        $subscriber = new JobsHookSubscriber(
            $tickRunner,
            $this->content,
            $this->scheduler,
            $this->clock,
            rearmDelaySeconds: 2,
            retentionScheduler: $retentionScheduler,
            retentionRunner: $runner,
        );

        return [$subscriber, $store, $retentionScheduler];
    }

    private function storePendingDirtyRun(string $id): ExportRun
    {
        $run = ExportRun::create($id, new RunSettings(hostname: 'blog.example.test'), at: 1000);
        $run->markDirty(at: 1000);
        $this->repository->save($run);

        return $run;
    }

    private function post(): stdClass
    {
        return (object) ['ID' => 1, 'post_type' => 'post', 'post_status' => 'publish'];
    }
}
