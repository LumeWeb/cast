<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Jobs;

use LumeWeb\Cast\Export\ExportRun;
use LumeWeb\Cast\Export\InMemoryRunRepository;
use LumeWeb\Cast\Export\InMemoryWorkItemRepository;
use LumeWeb\Cast\Export\RunSettings;
use LumeWeb\Cast\Export\RunStatus;
use LumeWeb\Cast\Export\WorkItemFactory;
use LumeWeb\Cast\Jobs\ContentPublishScheduler;
use LumeWeb\Cast\Jobs\FixedClock;
use LumeWeb\Cast\Jobs\InMemoryIdentityGateway;
use LumeWeb\Cast\Jobs\InMemoryPublishModeStore;
use LumeWeb\Cast\Jobs\InMemoryScheduler;
use LumeWeb\Cast\Jobs\PublishMode;
use LumeWeb\Cast\Jobs\PublishNowResult;
use PHPUnit\Framework\TestCase;

/**
 * The manual-run-wins `startNow()` primitive: an explicit "publish now"
 * absorbs queued NotStarted auto work (cancelling the quiet-period debounce
 * and scheduling the same run immediately), starts a fresh run when nothing is
 * live, and refuses only a run that is actively executing or superseded.
 * The invariant is exactly one active run plus at most one coalesced follow-up
 * — never a second stacked run.
 */
final class ContentPublishSchedulerStartNowTest extends TestCase
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

    private function makeService(PublishMode $mode = PublishMode::Manual): ContentPublishScheduler
    {
        return new ContentPublishScheduler(
            clock: $this->clock,
            repository: $this->repository,
            scheduler: $this->scheduler,
            identity: $this->identity,
            modeStore: new InMemoryPublishModeStore($mode),
            workItems: new InMemoryWorkItemRepository(),
        );
    }

    private function makeRun(string $id, int $at): ExportRun
    {
        return ExportRun::create($id, new RunSettings(hostname: 'blog.example.test'), at: $at);
    }

    public function testStartNowSchedulesAPendingNotStartedRunImmediately(): void
    {
        // Auto mode queued the work behind a quiet-period debounce.
        $service = $this->makeService(PublishMode::OnUpdate);
        $service->onContentPublished(at: 1000);
        self::assertSame(1600, $this->scheduler->nextAt(ContentPublishScheduler::AUTO_HOOK));
        $runId = $this->repository->latest()?->runId;
        self::assertNotNull($runId);

        $result = $this->makeService(PublishMode::OnUpdate)->startNow(at: 1300);

        self::assertInstanceOf(PublishNowResult::class, $result);
        self::assertTrue($result->started);
        self::assertSame($runId, $result->runId);
        // The same queued run is the work; it is now scheduled immediately.
        self::assertSame($runId, $this->repository->latest()?->runId);
        self::assertSame(1, $this->scheduler->count());
        self::assertTrue($this->scheduler->isScheduled(ContentPublishScheduler::AUTO_HOOK));
        self::assertSame(1300, $this->scheduler->nextAt(ContentPublishScheduler::AUTO_HOOK));
    }

    public function testStartNowCancelsTheQuietPeriodDebounce(): void
    {
        $service = $this->makeService(PublishMode::OnUpdate);
        $service->onContentPublished(at: 1000);
        self::assertSame(1600, $this->scheduler->nextAt(ContentPublishScheduler::AUTO_HOOK));

        $this->makeService(PublishMode::OnUpdate)->startNow(at: 1300);

        // The only AUTO_HOOK event is the immediate one, not the debounce.
        self::assertSame(1, $this->scheduler->count());
        self::assertSame(1300, $this->scheduler->nextAt(ContentPublishScheduler::AUTO_HOOK));
        self::assertFalse($this->scheduler->isScheduled(ContentPublishScheduler::FOLLOW_UP_HOOK));
    }

    public function testStartNowWithNoRunCreatesADirtyRunScheduledImmediately(): void
    {
        $result = $this->makeService()->startNow(at: 1000);

        self::assertTrue($result->started);
        self::assertNotNull($result->runId);
        $run = $this->repository->latest();
        self::assertNotNull($run);
        self::assertSame($result->runId, $run->runId);
        self::assertSame(RunStatus::NotStarted, $run->status);
        self::assertTrue($run->dirty);
        self::assertSame(1, $this->scheduler->count());
        self::assertSame(1000, $this->scheduler->nextAt(ContentPublishScheduler::AUTO_HOOK));
    }

    public function testStartNowAfterATerminalRunCreatesAFreshRunScheduledNow(): void
    {
        $old = $this->makeRun('run-old', 800);
        $old->start(at: 800);
        $old->complete(at: 900);
        $this->repository->save($old);

        $result = $this->makeService()->startNow(at: 1000);

        self::assertTrue($result->started);
        self::assertNotSame('run-old', $result->runId);
        self::assertTrue($this->repository->latest()?->dirty);
        self::assertSame(1000, $this->scheduler->nextAt(ContentPublishScheduler::AUTO_HOOK));
    }

    public function testStartNowRefusesAnActivelyExecutingRun(): void
    {
        $run = $this->makeRun('run-1', 900);
        $run->start(at: 900);
        $this->repository->save($run);

        $result = $this->makeService()->startNow(at: 1000);

        self::assertFalse($result->started);
        self::assertNull($result->runId);
        // Nothing new was scheduled and the run is untouched.
        self::assertSame(0, $this->scheduler->count());
        self::assertSame(RunStatus::Running, $this->repository->latest()?->status);
    }

    public function testStartNowRefusesASupersededRun(): void
    {
        $run = $this->makeRun('run-1', 900);
        $run->start(at: 900);
        $run->notifyContentChanged(at: 950);
        $this->repository->save($run);
        self::assertTrue($this->repository->latest()?->superseded);

        $result = $this->makeService()->startNow(at: 1000);

        self::assertFalse($result->started);
        self::assertNull($result->runId);
        self::assertSame(0, $this->scheduler->count());
    }

    public function testStartNowAfterAFailedRunClearsTheQueueSoTheRerunRequenes(): void
    {
        // A failed prior run left rows in the shared queue. The explicit rerun
        // becomes a brand-new run id, so the scheduler must empty the queue:
        // the rerun's discovery would otherwise dedupe every stale row
        // (first-seen-wins) and starve the fresh per-run work directory.
        $workItems = new InMemoryWorkItemRepository();
        $failed = $this->makeRun('run-failed', 800);
        $failed->start(at: 800);
        $failed->fail('connection lost', at: 900);
        $this->repository->save($failed);
        $workItems->insertCanonical((new WorkItemFactory())->fromString('https://example.com/stale/'));

        $service = new ContentPublishScheduler(
            clock: $this->clock,
            repository: $this->repository,
            scheduler: $this->scheduler,
            identity: $this->identity,
            modeStore: new InMemoryPublishModeStore(PublishMode::Manual),
            workItems: $workItems,
        );
        $result = $service->startNow(at: 1000);

        self::assertTrue($result->started);
        self::assertNotSame('run-failed', $result->runId);
        self::assertSame(0, $workItems->pendingCount(), 'a rerun starts from a clean queue');
        self::assertTrue(
            $workItems->insertCanonical((new WorkItemFactory())->fromString('https://example.com/stale/'))->inserted(),
            'the rerun can re-queue every URL it must re-capture',
        );
    }

    public function testStartNowKeepsExactlyOneActiveRunAndOneCoalescedEvent(): void
    {
        // A live NotStarted run with a pending debounce: absorbing it must not
        // create a second run or a second event.
        $service = $this->makeService(PublishMode::OnUpdate);
        $service->onContentPublished(at: 1000);
        $runId = $this->repository->latest()?->runId;

        $result = $this->makeService(PublishMode::OnUpdate)->startNow(at: 1300);

        self::assertTrue($result->started);
        self::assertSame($runId, $result->runId);
        self::assertCount(1, $this->repository->list());
        self::assertSame(1, $this->scheduler->count());
        self::assertFalse($this->scheduler->isScheduled(ContentPublishScheduler::FOLLOW_UP_HOOK));
    }
}
