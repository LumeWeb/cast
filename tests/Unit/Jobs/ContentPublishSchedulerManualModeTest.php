<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Jobs;

use LumeWeb\Cast\Export\ExportRun;
use LumeWeb\Cast\Export\InMemoryRunRepository;
use LumeWeb\Cast\Export\InMemoryWorkItemRepository;
use LumeWeb\Cast\Export\RunSettings;
use LumeWeb\Cast\Export\RunStatus;
use LumeWeb\Cast\Jobs\ContentPublishScheduler;
use LumeWeb\Cast\Jobs\FixedClock;
use LumeWeb\Cast\Jobs\InMemoryIdentityGateway;
use LumeWeb\Cast\Jobs\InMemoryPublishModeStore;
use LumeWeb\Cast\Jobs\InMemoryScheduler;
use LumeWeb\Cast\Jobs\PublishMode;
use LumeWeb\Cast\Jobs\PublishScheduleState;
use PHPUnit\Framework\TestCase;

/**
 * Manual publish mode (the default) blocks automatic publishing entirely:
 * publish-relevant content events record drift on the run record but never
 * schedule a debounce, supersede an active run, or arm a follow-up. Only an
 * explicit action (startNow / first publish) ever schedules a tick.
 */
final class ContentPublishSchedulerManualModeTest extends TestCase
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

    private function makeService(): ContentPublishScheduler
    {
        return new ContentPublishScheduler(
            clock: $this->clock,
            repository: $this->repository,
            scheduler: $this->scheduler,
            identity: $this->identity,
            modeStore: new InMemoryPublishModeStore(PublishMode::Manual),
            workItems: new InMemoryWorkItemRepository(),
        );
    }

    private function makeRun(string $id, int $at): ExportRun
    {
        return ExportRun::create($id, new RunSettings(hostname: 'blog.example.test'), at: $at);
    }

    public function testManualModeRecordsDriftWithoutSchedulingAnything(): void
    {
        $outcome = $this->makeService()->onContentPublished(at: 1000);

        self::assertSame(PublishScheduleState::Held, $outcome);
        $latest = $this->repository->latest();
        self::assertNotNull($latest);
        self::assertSame(RunStatus::NotStarted, $latest->status);
        self::assertTrue($latest->dirty);
        self::assertSame(0, $this->scheduler->count());
    }

    public function testManualModeReusesOnePendingDriftRunAcrossPublishes(): void
    {
        $service = $this->makeService();
        $service->onContentPublished(at: 1000);
        $runId = $this->repository->latest()?->runId;

        $again = $service->onContentPublished(at: 1005);

        self::assertSame(PublishScheduleState::Held, $again);
        self::assertSame($runId, $this->repository->latest()?->runId);
        self::assertTrue($this->repository->latest()?->dirty);
        self::assertSame(0, $this->scheduler->count());
    }

    public function testManualModeDoesNotSupersedeAnActiveRun(): void
    {
        $run = $this->makeRun('run-1', 900);
        $run->start(at: 900);
        $this->repository->save($run);

        $outcome = $this->makeService()->onContentPublished(at: 1000);

        self::assertSame(PublishScheduleState::Held, $outcome);
        $latest = $this->repository->latest();
        self::assertSame(RunStatus::Running, $latest?->status);
        self::assertFalse($latest->superseded);
        self::assertTrue($latest->dirty);
        // Drift-only: no follow-up is armed for the live run.
        self::assertSame(0, $this->scheduler->count());
    }

    public function testManualModeRecordsDriftEvenWithoutAnIdentity(): void
    {
        $this->identity->setIdentity(false);

        $outcome = $this->makeService()->onContentPublished(at: 1000);

        self::assertSame(PublishScheduleState::Held, $outcome);
        $latest = $this->repository->latest();
        self::assertNotNull($latest);
        self::assertTrue($latest->dirty);
        self::assertSame(0, $this->scheduler->count());
    }

    public function testManualModeAfterATerminalRunStartsAFreshDriftRun(): void
    {
        $old = $this->makeRun('run-old', 800);
        $old->start(at: 800);
        $old->complete(at: 900);
        $this->repository->save($old);

        $outcome = $this->makeService()->onContentPublished(at: 1000);

        self::assertSame(PublishScheduleState::Held, $outcome);
        $latest = $this->repository->latest();
        self::assertNotNull($latest);
        self::assertNotSame('run-old', $latest->runId);
        self::assertTrue($latest->dirty);
        self::assertSame(0, $this->scheduler->count());
    }

    public function testManualModeNeverSchedulesFromAFollowUpEvent(): void
    {
        // Even when a follow-up somehow fires (e.g. the mode changed after an
        // event was armed), Manual mode never auto-schedules a fresh debounce.
        $outcome = $this->makeService()->onFollowUp(at: 1000);

        self::assertSame(PublishScheduleState::Held, $outcome);
        self::assertSame(0, $this->scheduler->count());
        self::assertNull($this->repository->latest());
    }
}
