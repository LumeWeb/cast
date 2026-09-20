<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Jobs;

use LumeWeb\Cast\Export\ExportRun;
use LumeWeb\Cast\Export\InMemoryRunRepository;
use LumeWeb\Cast\Export\RunSettings;
use LumeWeb\Cast\Export\RunStatus;
use LumeWeb\Cast\Jobs\ExportTickRunner;
use LumeWeb\Cast\Jobs\FixedClock;
use LumeWeb\Cast\Jobs\InMemoryIdentityGateway;
use LumeWeb\Cast\Jobs\InMemoryLock;
use LumeWeb\Cast\Jobs\TickConfig;
use LumeWeb\Cast\Jobs\TickOutcomeKind;
use LumeWeb\Cast\Jobs\TickResult;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExportTickRunnerTest extends TestCase
{
    private const LOCK_KEY = 'cast:export-tick';

    private FixedClock $clock;

    private InMemoryLock $lock;

    private InMemoryRunRepository $repository;

    private InMemoryIdentityGateway $identity;

    private FakeTick $tick;

    private ExportTickRunner $runner;

    private TickConfig $config;

    protected function setUp(): void
    {
        $this->clock = new FixedClock(1000);
        $this->lock = new InMemoryLock($this->clock);
        $this->repository = new InMemoryRunRepository();
        $this->identity = new InMemoryIdentityGateway(true);
        $this->tick = new FakeTick();
        // No reclaimStaleLocks argument: the suite exercises the same default
        // (reclaim stale leases) that production relies on.
        $this->config = new TickConfig(lockKey: self::LOCK_KEY, lockTtlSeconds: 60);
        $this->runner = $this->makeRunner();
    }

    private function makeRunner(?TickConfig $config = null): ExportTickRunner
    {
        return new ExportTickRunner(
            clock: $this->clock,
            lock: $this->lock,
            repository: $this->repository,
            tick: $this->tick,
            identity: $this->identity,
            config: $config ?? $this->config,
        );
    }

    private function makeRun(string $id, int $at): ExportRun
    {
        return ExportRun::create($id, new RunSettings(hostname: 'blog.example.test'), at: $at);
    }

    private function storePendingDirtyRun(string $id, int $at): ExportRun
    {
        $run = $this->makeRun($id, $at);
        $run->markDirty(at: $at);
        $this->repository->save($run);

        return $run;
    }

    public function testExecutesOneBoundedTickUnderLockAndReleasesIt(): void
    {
        $this->storePendingDirtyRun('run-1', 1000);

        $outcome = $this->runner->tick(at: 1001);

        self::assertSame(TickOutcomeKind::Ran, $outcome->kind);
        self::assertSame(1, $this->tick->calls);
        self::assertSame('run-1', $this->tick->lastRunId);
        self::assertSame(1001, $this->tick->lastNow);
        self::assertFalse($this->lock->isHeld(self::LOCK_KEY));
    }

    public function testUsesTheMostRecentRunFromTheRepository(): void
    {
        $this->storePendingDirtyRun('old', 1000);
        $this->storePendingDirtyRun('new', 2000);

        $this->runner->tick(at: 2001);

        self::assertSame('new', $this->tick->lastRunId);
    }

    public function testSkipsWhenAnotherWorkerHoldsTheLock(): void
    {
        $this->storePendingDirtyRun('run-1', 1000);
        $this->lock->acquire(self::LOCK_KEY, 60);

        $outcome = $this->runner->tick(at: 1001);

        self::assertSame(TickOutcomeKind::SkippedLocked, $outcome->kind);
        self::assertSame(0, $this->tick->calls);
        self::assertSame(RunStatus::NotStarted, $this->repository->latest()?->status);
    }

    public function testSkipsOnStaleLockWhenThePolicySaysDoNotReclaim(): void
    {
        // Explicit opt-out: a configuration that disables reclamation keeps the
        // stale-lock skip; the default (no argument) now reclaims instead.
        $this->config = new TickConfig(lockKey: self::LOCK_KEY, lockTtlSeconds: 60, reclaimStaleLocks: false);
        $this->runner = $this->makeRunner($this->config);
        $this->storePendingDirtyRun('run-1', 1000);
        $this->lock->acquire(self::LOCK_KEY, 60);
        $this->clock->advance(61);

        $outcome = $this->runner->tick(at: 1061);

        self::assertSame(TickOutcomeKind::SkippedStaleLock, $outcome->kind);
        self::assertSame(0, $this->tick->calls);
        self::assertSame(RunStatus::NotStarted, $this->repository->latest()?->status);
    }

    public function testReclaimsAStaleLockWhenThePolicyAllows(): void
    {
        $this->config = new TickConfig(lockKey: self::LOCK_KEY, lockTtlSeconds: 60, reclaimStaleLocks: true);
        $this->runner = $this->makeRunner($this->config);
        $this->storePendingDirtyRun('run-1', 1000);
        $this->lock->acquire(self::LOCK_KEY, 60);
        $this->clock->advance(61);

        $outcome = $this->runner->tick(at: 1061);

        self::assertSame(TickOutcomeKind::Ran, $outcome->kind);
        self::assertSame(1, $this->tick->calls);
        self::assertFalse($this->lock->isHeld(self::LOCK_KEY));
    }

    public function testReclaimsAStaleLockByDefault(): void
    {
        // Regression (real Kody finding on ExportTickRunner::tick): with the
        // DEFAULT TickConfig — no explicit reclaimStaleLocks — a stale lease
        // left by a crashed worker must be reclaimed on the next tick so the
        // loop runs instead of spinning forever on skipped_stale_lock.
        $this->config = new TickConfig(lockKey: self::LOCK_KEY, lockTtlSeconds: 60);
        $this->runner = $this->makeRunner($this->config);
        $this->storePendingDirtyRun('run-1', 1000);
        $this->lock->acquire(self::LOCK_KEY, 60);
        $this->clock->advance(61);

        $outcome = $this->runner->tick(at: 1061);

        self::assertSame(TickOutcomeKind::Ran, $outcome->kind);
        self::assertSame(1, $this->tick->calls);
        self::assertFalse($this->lock->isHeld(self::LOCK_KEY));
    }

    public function testSkipsWhenNoRunIsStored(): void
    {
        $outcome = $this->runner->tick(at: 1001);

        self::assertSame(TickOutcomeKind::SkippedNoRun, $outcome->kind);
        self::assertSame(0, $this->tick->calls);
        self::assertFalse($this->lock->isHeld(self::LOCK_KEY));
    }

    public function testSkipsWhenThePendingRunIsNotDirty(): void
    {
        $this->repository->save($this->makeRun('run-1', 1000));

        $outcome = $this->runner->tick(at: 1001);

        self::assertSame(TickOutcomeKind::SkippedNotReady, $outcome->kind);
        self::assertSame(0, $this->tick->calls);
        self::assertSame(RunStatus::NotStarted, $this->repository->latest()?->status);
    }

    public function testDefersAPendingDirtyRunBeforeIdentityExists(): void
    {
        // Regression: a pending dirty queued run with no identity must NOT be
        // reported as a plain skip that the subscriber stops re-arming on —
        // that is the "queued forever, no scheduled tick, no cause" dead-end.
        // It is Deferred (work exists, waiting on identity), so the loop keeps
        // a tick armed.
        $this->identity->setIdentity(false);
        $this->storePendingDirtyRun('run-1', 1000);

        $outcome = $this->runner->tick(at: 1001);

        self::assertSame(TickOutcomeKind::Deferred, $outcome->kind);
        self::assertSame(0, $this->tick->calls);
        self::assertSame(RunStatus::NotStarted, $this->repository->latest()?->status);
    }

    public function testStartsAPendingDirtyRunWithoutIdentityWhenExplicitlyMarked(): void
    {
        // Manual first publish / recover-a-stuck-queued-run: the user clicked
        // Publish, so the run is explicitly started and may begin even though
        // no identity exists yet.
        $this->identity->setIdentity(false);
        $run = $this->storePendingDirtyRun('run-1', 1000);
        $run->markExplicitStart(at: 1000);
        $this->repository->save($run);

        $outcome = $this->runner->tick(at: 1001);

        self::assertSame(TickOutcomeKind::Ran, $outcome->kind);
        self::assertSame(1, $this->tick->calls);
        self::assertSame(RunStatus::Running, $this->repository->latest()?->status);
    }

    public function testAutoStartsAPendingDirtyRunOnceIdentityExists(): void
    {
        $this->storePendingDirtyRun('run-1', 1000);

        $outcome = $this->runner->tick(at: 1001);

        self::assertSame(TickOutcomeKind::Ran, $outcome->kind);
        self::assertSame(RunStatus::Running, $this->repository->latest()?->status);
        self::assertSame(1001, $this->repository->latest()->updatedAt);
    }

    public function testSkipsWhenTheRunIsAlreadyTerminal(): void
    {
        $run = $this->makeRun('run-1', 1000);
        $run->start(at: 1000);
        $run->complete(at: 1002);
        $this->repository->save($run);

        $outcome = $this->runner->tick(at: 1003);

        self::assertSame(TickOutcomeKind::SkippedNotReady, $outcome->kind);
        self::assertSame(0, $this->tick->calls);
    }

    public function testSkipsWhenTheRunIsPaused(): void
    {
        $run = $this->storePendingDirtyRun('run-1', 1000);
        $run->start(at: 1001);
        $run->pause(at: 1002);
        $this->repository->save($run);

        $outcome = $this->runner->tick(at: 1003);

        self::assertSame(TickOutcomeKind::SkippedNotReady, $outcome->kind);
        self::assertSame(0, $this->tick->calls);
    }

    public function testCompletesWhenTheTickFinishesTheRun(): void
    {
        $this->storePendingDirtyRun('run-1', 1000);
        $this->tick->result = TickResult::done();
        $this->tick->mutate = static function (ExportRun $run): void {
            $run->complete(at: 1001);
        };

        $outcome = $this->runner->tick(at: 1001);

        self::assertSame(TickOutcomeKind::Completed, $outcome->kind);
        self::assertSame(RunStatus::Completed, $this->repository->latest()?->status);
        self::assertFalse($this->lock->isHeld(self::LOCK_KEY));
    }

    public function testRecordsRetryMetadataOnTransientFailure(): void
    {
        $this->storePendingDirtyRun('run-1', 1000);
        $this->tick->result = TickResult::fail('upload timed out');

        $outcome = $this->runner->tick(at: 1001);

        self::assertSame(TickOutcomeKind::Retried, $outcome->kind);
        $latest = $this->repository->latest();
        self::assertSame(RunStatus::Running, $latest?->status);
        self::assertSame(1, $latest->retryCount);
        self::assertSame('upload timed out', $latest->lastError);
        self::assertFalse($this->lock->isHeld(self::LOCK_KEY));
    }

    public function testFailsTheRunWhenTheRetryBudgetIsExhausted(): void
    {
        $run = ExportRun::create('run-1', new RunSettings(hostname: 'blog.example.test', maxRetries: 0), at: 1000);
        $run->markDirty(at: 1000);
        $this->repository->save($run);
        $this->tick->result = TickResult::fail('disk full');

        $outcome = $this->runner->tick(at: 1001);

        self::assertSame(TickOutcomeKind::Failed, $outcome->kind);
        self::assertSame('disk full', $outcome->reason);
        $latest = $this->repository->latest();
        self::assertSame(RunStatus::Failed, $latest?->status);
        self::assertTrue($latest->isTerminal());
        self::assertSame('disk full', $latest->lastError);
        self::assertFalse($this->lock->isHeld(self::LOCK_KEY));
    }

    public function testHandsStaleRunsToTheWatchdogBoundary(): void
    {
        $this->storePendingDirtyRun('run-1', 1000);
        $this->tick->result = TickResult::stale();

        $outcome = $this->runner->tick(at: 1001);

        self::assertSame(TickOutcomeKind::Watchdog, $outcome->kind);
        self::assertSame(RunStatus::Running, $this->repository->latest()?->status);
        self::assertFalse($this->lock->isHeld(self::LOCK_KEY));
    }

    public function testPersistsMutationsMadeByTheTick(): void
    {
        $this->storePendingDirtyRun('run-1', 1000);
        $this->tick->mutate = static function (ExportRun $run): void {
            $run->recordProgress(42, at: 1001);
        };

        $this->runner->tick(at: 1001);

        self::assertSame(42, $this->repository->latest()?->progressCount);
    }

    public function testReleasesTheLockEvenWhenTheTickThrows(): void
    {
        $this->storePendingDirtyRun('run-1', 1000);
        $this->tick->throw = new RuntimeException('adaptor exploded');

        try {
            $this->runner->tick(at: 1001);
            self::fail('tick() should have let the exception propagate');
        } catch (RuntimeException $exception) {
            self::assertSame('adaptor exploded', $exception->getMessage());
        }

        self::assertFalse($this->lock->isHeld(self::LOCK_KEY));
    }

    public function testCancelsASupersededLiveRun(): void
    {
        $run = $this->makeRun('run-1', 1000);
        $run->start(at: 1001);
        $run->supersede(at: 1002);
        $this->repository->save($run);

        $outcome = $this->runner->tick(at: 1003);

        self::assertSame(TickOutcomeKind::Cancelled, $outcome->kind);
        self::assertSame(0, $this->tick->calls);
        self::assertSame(RunStatus::Cancelled, $this->repository->latest()?->status);
        self::assertFalse($this->lock->isHeld(self::LOCK_KEY));
    }
}
