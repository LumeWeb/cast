<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Jobs;

use LumeWeb\Cast\Jobs\InMemoryScheduler;
use PHPUnit\Framework\TestCase;

final class InMemorySchedulerTest extends TestCase
{
    private InMemoryScheduler $scheduler;

    protected function setUp(): void
    {
        $this->scheduler = new InMemoryScheduler();
    }

    public function testScheduleSingleStoresASingleEvent(): void
    {
        self::assertTrue($this->scheduler->scheduleSingle('cast/export/tick', 1100));

        self::assertTrue($this->scheduler->isScheduled('cast/export/tick'));
        self::assertSame(1100, $this->scheduler->nextAt('cast/export/tick'));
        self::assertSame(1, $this->scheduler->count());
    }

    public function testSchedulingTheSameEventTwiceDoesNotDuplicate(): void
    {
        $this->scheduler->scheduleSingle('cast/export/tick', 1100);

        self::assertFalse($this->scheduler->scheduleSingle('cast/export/tick', 1300));

        self::assertSame(1, $this->scheduler->count());
        self::assertSame(1100, $this->scheduler->nextAt('cast/export/tick'));
    }

    public function testDifferentHooksAreIndependent(): void
    {
        $this->scheduler->scheduleSingle('cast/export/tick', 1100);
        self::assertTrue($this->scheduler->scheduleSingle('cast/export/follow-up', 1150));

        self::assertSame(2, $this->scheduler->count());
    }

    public function testEventsAreScopedByArguments(): void
    {
        self::assertTrue($this->scheduler->scheduleSingle('cast/export/tick', 1100, [7]));
        self::assertTrue($this->scheduler->scheduleSingle('cast/export/tick', 1200, [9]));

        self::assertSame(2, $this->scheduler->count());
        self::assertTrue($this->scheduler->isScheduled('cast/export/tick', [7]));
    }

    public function testCancelSingleRemovesOnlyTheMatchingEvent(): void
    {
        $this->scheduler->scheduleSingle('cast/export/tick', 1100, [7]);
        $this->scheduler->scheduleSingle('cast/export/tick', 1200, [9]);

        $this->scheduler->cancelSingle('cast/export/tick', [7]);

        self::assertFalse($this->scheduler->isScheduled('cast/export/tick', [7]));
        self::assertTrue($this->scheduler->isScheduled('cast/export/tick', [9]));
    }

    public function testIsScheduledIsFalseForUnknownEvents(): void
    {
        self::assertFalse($this->scheduler->isScheduled('cast/export/tick'));
    }

    public function testIsScheduledIsFalseWhileAnEventIsRunning(): void
    {
        $this->scheduler->scheduleSingle('cast/export/tick', 1100);
        $this->scheduler->startRunning('cast/export/tick');

        // A running event is not a pending slot: no dedupe, no next-at.
        self::assertFalse($this->scheduler->isScheduled('cast/export/tick'));
        self::assertNull($this->scheduler->nextAt('cast/export/tick'));
        self::assertSame(0, $this->scheduler->count());
    }

    public function testScheduleSingleAdmitsARearmFromInsideARunningEvent(): void
    {
        $this->scheduler->scheduleSingle('cast/export/tick', 1100);
        $this->scheduler->startRunning('cast/export/tick');

        // The exact production shape: rearming from inside the in-flight tick
        // queues a fresh pending event instead of refusing it.
        self::assertTrue($this->scheduler->scheduleSingle('cast/export/tick', 1200));
        self::assertSame(1200, $this->scheduler->nextAt('cast/export/tick'));
        self::assertSame(1, $this->scheduler->count());
    }

    public function testStopRunningConcludesAnInFlightEvent(): void
    {
        $this->scheduler->scheduleSingle('cast/export/tick', 1100);
        $this->scheduler->startRunning('cast/export/tick');

        $this->scheduler->stopRunning('cast/export/tick');

        self::assertFalse($this->scheduler->isScheduled('cast/export/tick'));
        self::assertSame(0, $this->scheduler->count());
    }
}
