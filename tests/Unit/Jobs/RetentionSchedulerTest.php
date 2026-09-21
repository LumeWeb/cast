<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Jobs;

use LumeWeb\Cast\Jobs\FixedClock;
use LumeWeb\Cast\Jobs\InMemoryScheduler;
use LumeWeb\Cast\Jobs\RetentionScheduler;
use PHPUnit\Framework\TestCase;

/**
 * Deduplicated retention scheduling: arm-after-terminal and the periodic
 * re-arm both ride the {@see Scheduler} single-event (hook, args) identity, so
 * a sweep already pending can never stack a duplicate event — the same
 * contract that prevents tick storms in the export loop.
 */
final class RetentionSchedulerTest extends TestCase
{
    private FixedClock $clock;

    private InMemoryScheduler $scheduler;

    private RetentionScheduler $retention;

    protected function setUp(): void
    {
        $this->clock = new FixedClock(1_000_000);
        $this->scheduler = new InMemoryScheduler();
        $this->retention = new RetentionScheduler($this->scheduler, $this->clock);
    }

    public function testHookNameIsTheCastExportRetentionHook(): void
    {
        self::assertSame('cast/export/retention', RetentionScheduler::RETENTION_HOOK);
    }

    public function testArmAfterTerminalSchedulesOneDelayedRetentionEvent(): void
    {
        self::assertTrue($this->retention->armAfterTerminal());

        self::assertTrue($this->retention->isArmed());
        self::assertSame(1, $this->scheduler->count());
        self::assertSame(
            1_000_000 + RetentionScheduler::DEFAULT_ARM_DELAY_SECONDS,
            $this->scheduler->nextAt(RetentionScheduler::RETENTION_HOOK),
        );
    }

    public function testArmAfterTerminalDoesNotStackWhenOneIsAlreadyArmed(): void
    {
        $this->retention->armAfterTerminal();

        // A terminal run arriving while a sweep is already queued must not
        // schedule a duplicate: the scheduler dedupes on (hook, args).
        self::assertFalse($this->retention->armAfterTerminal());
        self::assertSame(1, $this->scheduler->count());
    }

    public function testArmPeriodicSchedulesTheNextDailySweep(): void
    {
        self::assertTrue($this->retention->armPeriodic());

        self::assertSame(1, $this->scheduler->count());
        self::assertSame(
            1_000_000 + RetentionScheduler::DEFAULT_PERIODIC_SECONDS,
            $this->scheduler->nextAt(RetentionScheduler::RETENTION_HOOK),
        );
    }

    public function testArmAfterTerminalAndArmPeriodicShareTheDeduplicatedSlot(): void
    {
        // Both arming routes target the SAME hook, so whichever wins leaves
        // exactly one pending event; the other reports false (already armed).
        $this->retention->armPeriodic();
        self::assertFalse($this->retention->armAfterTerminal());
        self::assertSame(1, $this->scheduler->count());

        // Once the sweep fires and is cancelled, arming works again.
        $this->scheduler->cancelSingle(RetentionScheduler::RETENTION_HOOK);
        self::assertTrue($this->retention->armAfterTerminal());
        self::assertSame(1, $this->scheduler->count());
    }

    public function testIsArmedReflectsTheSchedulerState(): void
    {
        self::assertFalse($this->retention->isArmed());

        $this->retention->armAfterTerminal();
        self::assertTrue($this->retention->isArmed());

        $this->scheduler->cancelSingle(RetentionScheduler::RETENTION_HOOK);
        self::assertFalse($this->retention->isArmed());
    }

    public function testAcceptsAnExplicitInstant(): void
    {
        self::assertTrue($this->retention->armAfterTerminal(5_000));

        self::assertSame(
            5_000 + RetentionScheduler::DEFAULT_ARM_DELAY_SECONDS,
            $this->scheduler->nextAt(RetentionScheduler::RETENTION_HOOK),
        );
    }
}
