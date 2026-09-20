<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Jobs;

use LumeWeb\Cast\Export\ExportRun;
use LumeWeb\Cast\Export\InMemoryRunRepository;
use LumeWeb\Cast\Export\InMemoryWorkItemRepository;
use LumeWeb\Cast\Export\RunSettings;
use LumeWeb\Cast\Export\RunStatus;
use LumeWeb\Cast\Export\WorkItemFactory;
use LumeWeb\Cast\Export\WorkItemRepository;
use LumeWeb\Cast\Export\WorkItemStatus;
use LumeWeb\Cast\Jobs\ContentPublishScheduler;
use LumeWeb\Cast\Jobs\FixedClock;
use LumeWeb\Cast\Jobs\InMemoryIdentityGateway;
use LumeWeb\Cast\Jobs\InMemoryPublishModeStore;
use LumeWeb\Cast\Jobs\InMemoryScheduler;
use LumeWeb\Cast\Jobs\PublishMode;
use LumeWeb\Cast\Jobs\PublishModeStore;
use LumeWeb\Cast\Jobs\PublishScheduleState;
use PHPUnit\Framework\TestCase;

final class ContentPublishSchedulerTest extends TestCase
{
    private FixedClock $clock;

    private InMemoryRunRepository $repository;

    private InMemoryScheduler $scheduler;

    private InMemoryIdentityGateway $identity;

    protected function setUp(): void
    {
        $this->clock = new FixedClock(1000);
        $this->repository = new InMemoryRunRepository();
        $this->scheduler = new InMemoryScheduler();
        $this->identity = new InMemoryIdentityGateway(true);
    }

    private function makeService(
        int $quietSeconds = 600,
        int $followUpSeconds = 5,
        ?PublishModeStore $modeStore = null,
        ?WorkItemRepository $workItems = null,
    ): ContentPublishScheduler {
        // The pre-existing fixtures exercise the debounce/squash/supersede
        // behaviour, which is the OnUpdate-path; Manual drift is covered by the
        // dedicated ContentPublishSchedulerManualModeTest.
        return new ContentPublishScheduler(
            clock: $this->clock,
            repository: $this->repository,
            scheduler: $this->scheduler,
            identity: $this->identity,
            modeStore: $modeStore ?? new InMemoryPublishModeStore(PublishMode::OnUpdate),
            workItems: $workItems ?? new InMemoryWorkItemRepository(),
            quietSeconds: $quietSeconds,
            followUpDelaySeconds: $followUpSeconds,
        );
    }

    private function makeRun(string $id, int $at): ExportRun
    {
        return ExportRun::create($id, new RunSettings(hostname: 'blog.example.test'), at: $at);
    }

    public function testExposesTheExpectedDefaultsAndHooks(): void
    {
        self::assertSame(600, ContentPublishScheduler::DEFAULT_QUIET_SECONDS);
        self::assertSame('cast/export/auto-tick', ContentPublishScheduler::AUTO_HOOK);
        self::assertSame('cast/export/follow-up', ContentPublishScheduler::FOLLOW_UP_HOOK);
    }

    public function testFirstPublishCreatesAPendingDirtyRunAndSchedulesExactlyOneEvent(): void
    {
        $outcome = $this->makeService()->onContentPublished(at: 1000);

        self::assertSame(PublishScheduleState::Scheduled, $outcome);
        $latest = $this->repository->latest();
        self::assertNotNull($latest);
        self::assertSame(RunStatus::NotStarted, $latest->status);
        self::assertTrue($latest->dirty);
        self::assertSame(1, $this->scheduler->count());
        self::assertTrue($this->scheduler->isScheduled(ContentPublishScheduler::AUTO_HOOK));
        self::assertSame(1600, $this->scheduler->nextAt(ContentPublishScheduler::AUTO_HOOK));
    }

    public function testQuietPeriodIsConfigurable(): void
    {
        $this->makeService(quietSeconds: 120)->onContentPublished(at: 1000);

        self::assertSame(1120, $this->scheduler->nextAt(ContentPublishScheduler::AUTO_HOOK));
    }

    public function testRepeatedPublishesDoNotScheduleDuplicates(): void
    {
        $service = $this->makeService();
        $service->onContentPublished(at: 1000);

        $again = $service->onContentPublished(at: 1005);

        self::assertSame(PublishScheduleState::Folded, $again);
        self::assertSame(1, $this->scheduler->count());
        self::assertSame(1600, $this->scheduler->nextAt(ContentPublishScheduler::AUTO_HOOK));
        self::assertTrue($this->repository->latest()?->dirty);
    }

    public function testRearmsTheDebounceAfterTheEventFires(): void
    {
        $service = $this->makeService();
        $service->onContentPublished(at: 1000);
        $this->scheduler->cancelSingle(ContentPublishScheduler::AUTO_HOOK);

        $outcome = $service->onContentPublished(at: 1100);

        self::assertSame(PublishScheduleState::Scheduled, $outcome);
        self::assertSame(1, $this->scheduler->count());
        self::assertSame(1700, $this->scheduler->nextAt(ContentPublishScheduler::AUTO_HOOK));
    }

    public function testSupersedesAnActiveRunAndSchedulesOneFollowUp(): void
    {
        $run = $this->makeRun('run-1', 900);
        $run->start(at: 900);
        $this->repository->save($run);

        $outcome = $this->makeService()->onContentPublished(at: 1000);

        self::assertSame(PublishScheduleState::Superseded, $outcome);
        $latest = $this->repository->latest();
        self::assertSame(RunStatus::Running, $latest?->status);
        self::assertTrue($latest->superseded);
        self::assertTrue($latest->dirty);
        self::assertSame(1, $this->scheduler->count());
        self::assertTrue($this->scheduler->isScheduled(ContentPublishScheduler::FOLLOW_UP_HOOK));
        self::assertSame(1005, $this->scheduler->nextAt(ContentPublishScheduler::FOLLOW_UP_HOOK));
    }

    public function testRepeatedContentWhileActiveDoesNotScheduleDuplicateFollowUps(): void
    {
        $run = $this->makeRun('run-1', 900);
        $run->start(at: 900);
        $this->repository->save($run);
        $service = $this->makeService();
        $service->onContentPublished(at: 1000);

        $again = $service->onContentPublished(at: 1010);

        self::assertSame(PublishScheduleState::Waiting, $again);
        self::assertSame(1, $this->scheduler->count());
    }

    public function testNeverAutoSchedulesBeforeIdentityExists(): void
    {
        // Auto mode + no identity yet. The run is Deferred (the worker parks
        // it until identity), but the invariant holds: a pending dirty queued
        // run NEVER exists without a debounce tick armed — it is never
        // orphaned with no scheduled event and no way to recover.
        $this->identity->setIdentity(false);

        $outcome = $this->makeService()->onContentPublished(at: 1000);

        self::assertSame(PublishScheduleState::Deferred, $outcome);
        $latest = $this->repository->latest();
        self::assertNotNull($latest);
        self::assertSame(RunStatus::NotStarted, $latest->status);
        self::assertTrue($latest->dirty);
        self::assertSame(1, $this->scheduler->count());
        self::assertTrue($this->scheduler->isScheduled(ContentPublishScheduler::AUTO_HOOK));
        self::assertSame(1600, $this->scheduler->nextAt(ContentPublishScheduler::AUTO_HOOK));
    }

    public function testPendingRunCreatedBeforeIdentityIsReusedOnceIdentityExists(): void
    {
        $service = $this->makeService();
        $this->identity->setIdentity(false);
        $service->onContentPublished(at: 1000);
        $pendingId = $this->repository->latest()?->runId;

        // Identity arrives. The pending run is reused and the debounce armed
        // while no identity exists (the invariant — a pending dirty queued
        // run never exists without a tick) is still the one that drives it, so
        // the event folds into it rather than scheduling a second 1700 tick.
        $this->identity->setIdentity(true);
        $outcome = $service->onContentPublished(at: 1100);

        self::assertSame(PublishScheduleState::Folded, $outcome);
        self::assertSame($pendingId, $this->repository->latest()?->runId);
        self::assertTrue($this->scheduler->isScheduled(ContentPublishScheduler::AUTO_HOOK));
        self::assertSame(1600, $this->scheduler->nextAt(ContentPublishScheduler::AUTO_HOOK));
    }

    public function testFollowUpWaitsWhileTheRunIsStillActive(): void
    {
        $run = $this->makeRun('run-1', 900);
        $run->start(at: 900);
        $this->repository->save($run);
        $service = $this->makeService();
        $service->onContentPublished(at: 1000);

        // The single follow-up event "fires".
        $this->scheduler->cancelSingle(ContentPublishScheduler::FOLLOW_UP_HOOK);
        $outcome = $service->onFollowUp(at: 1100);

        self::assertSame(PublishScheduleState::Waiting, $outcome);
        self::assertSame(1, $this->scheduler->count());
        self::assertTrue($this->scheduler->isScheduled(ContentPublishScheduler::FOLLOW_UP_HOOK));
    }

    public function testFollowUpFoldsPendingContentIntoAFreshRunOnceTheRunIsTerminal(): void
    {
        $run = $this->makeRun('run-1', 900);
        $run->start(at: 900);
        $this->repository->save($run);
        $service = $this->makeService();
        $service->onContentPublished(at: 1000);
        self::assertTrue($this->repository->latest()?->superseded);

        // The runner cancels the superseded run, making it terminal.
        $superseded = $this->repository->latest();
        $superseded->cancel(at: 1050);
        $this->repository->save($superseded);

        $this->scheduler->cancelSingle(ContentPublishScheduler::FOLLOW_UP_HOOK);
        $outcome = $service->onFollowUp(at: 1100);

        self::assertSame(PublishScheduleState::Scheduled, $outcome);
        $latest = $this->repository->latest();
        self::assertNotNull($latest);
        self::assertNotSame('run-1', $latest->runId);
        self::assertSame(RunStatus::NotStarted, $latest->status);
        self::assertTrue($latest->dirty);
        self::assertSame(1700, $this->scheduler->nextAt(ContentPublishScheduler::AUTO_HOOK));
    }

    public function testContentAfterATerminalRunStartsAFreshRun(): void
    {
        $run = $this->makeRun('run-old', 800);
        $run->start(at: 800);
        $run->complete(at: 900);
        $this->repository->save($run);

        $outcome = $this->makeService()->onContentPublished(at: 1000);

        self::assertSame(PublishScheduleState::Scheduled, $outcome);
        $latest = $this->repository->latest();
        self::assertNotNull($latest);
        self::assertNotSame('run-old', $latest->runId);
        self::assertSame(RunStatus::NotStarted, $latest->status);
        self::assertSame(1, $this->scheduler->count());
    }

    public function testFollowUpDefersWholeContentWithoutIdentity(): void
    {
        $run = $this->makeRun('run-old', 800);
        $run->start(at: 800);
        $run->complete(at: 900);
        $this->repository->save($run);
        $this->identity->setIdentity(false);

        $outcome = $this->makeService()->onFollowUp(at: 1000);

        self::assertSame(PublishScheduleState::Deferred, $outcome);
        $latest = $this->repository->latest();
        self::assertNotNull($latest);
        self::assertSame(RunStatus::NotStarted, $latest->status);
        self::assertTrue($latest->dirty);
        // The pending queued run still keeps its debounce tick armed.
        self::assertSame(1, $this->scheduler->count());
        self::assertTrue($this->scheduler->isScheduled(ContentPublishScheduler::AUTO_HOOK));
    }

    public function testStartNowMarksTheAbsorbedQueuedRunExplicit(): void
    {
        // The user clicks Publish while auto work is queued behind a debounce:
        // the same run stays the single active run AND becomes explicit, so a
        // tick may begin it even without identity (the stuck-queued escape).
        $this->makeService()->onContentPublished(at: 1000);
        $pendingId = $this->repository->latest()?->runId;

        $result = $this->makeService()->startNow(at: 1200);

        self::assertTrue($result->started);
        self::assertSame($pendingId, $result->runId);
        self::assertTrue($this->repository->latest()?->explicitStart);
    }

    public function testStartNowMarksAFreshRunExplicitWithoutIdentity(): void
    {
        $this->identity->setIdentity(false);

        $result = $this->makeService()->startNow(at: 1000);

        self::assertTrue($result->started);
        $started = $this->repository->latest();
        self::assertNotNull($started);
        self::assertTrue($started->explicitStart);
        self::assertTrue($started->dirty);
    }

    public function testNewRunCreationClearsTheWorkQueueExactlyOnce(): void
    {
        $workItems = new InMemoryWorkItemRepository();
        $service = $this->makeService(workItems: $workItems);
        $workItems->insertCanonical((new WorkItemFactory())->fromString('https://example.com/stale/'));

        // Creating the first run must empty the queue: the fresh per-run work
        // directory must never inherit the prior run's terminal rows.
        $service->onContentPublished(at: 1000);
        self::assertSame(0, $workItems->pendingCount(), 'a new run starts from a clean queue');

        // Subsequent content events only absorb the still queued run — they
        // must NOT clear again, or work already queued for the current run
        // would be dropped (clear once per new run id, never per event/tick).
        $workItems->insertCanonical((new WorkItemFactory())->fromString('https://example.com/current/'));
        $service->onContentPublished(at: 1010);
        self::assertSame(1, $workItems->pendingCount(), 'an absorbed run never clears the queue');

        // A plain status/repository read is never a run creation and never
        // clears either.
        $seen = $this->repository->latest();
        self::assertNotNull($seen);
        self::assertSame(1, $workItems->pendingCount());
    }

    public function testContentAfterTerminalRunClearsTheQueueSoRunTwoRedisCoversRoot(): void
    {
        // Run 1 completes, leaving the root page rewritten in the shared queue
        // (the live starvation shape: first-seen-wins would never re-queue a
        // finished row, so the fresh per-run workdir is starved of the root
        // index.html and pack fails).
        $workItems = new InMemoryWorkItemRepository();
        $service = $this->makeService(workItems: $workItems);
        $root = (new WorkItemFactory())->fromString('https://example.com/');
        self::assertTrue($workItems->insertCanonical($root)->inserted());
        $workItems->transition($root->urlHash(), WorkItemStatus::Done);
        $workItems->transition($root->urlHash(), WorkItemStatus::Rewritten);

        $run = $this->makeRun('run-1', 800);
        $run->start(at: 800);
        $run->complete(at: 900);
        $this->repository->save($run);

        // Run 2 is created: the scheduler must empty the queue up front so the
        // next discovery re-enqueues `/` instead of deduping it into oblivion.
        $service->onContentPublished(at: 1000);

        $outcome = $workItems->insertCanonical($root, 1);
        self::assertTrue($outcome->inserted(), 'run 2 must re-discover and re-queue the root page');
        self::assertSame(0, $workItems->countByStatus(WorkItemStatus::Rewritten));
        self::assertSame(1, $workItems->countByStatus(WorkItemStatus::Queued));
    }

    public function testManualDriftRunIsNotExplicitSoItWaitsForTheUser(): void
    {
        // Manual mode records drift (a pending dirty run) but never marks it
        // explicit: nothing auto-starts it and the UI shows it waiting for the
        // user's explicit Publish click, which then marks it explicit.
        $manual = new ContentPublishScheduler(
            clock: $this->clock,
            repository: $this->repository,
            scheduler: $this->scheduler,
            identity: $this->identity,
            modeStore: new InMemoryPublishModeStore(PublishMode::Manual),
            workItems: new InMemoryWorkItemRepository(),
        );

        $manual->onContentPublished(at: 1000);

        $latest = $this->repository->latest();
        self::assertNotNull($latest);
        self::assertTrue($latest->dirty);
        self::assertFalse($latest->explicitStart);
        self::assertSame(0, $this->scheduler->count());
    }
}
