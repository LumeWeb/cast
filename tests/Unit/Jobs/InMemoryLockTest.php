<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Jobs;

use LumeWeb\Cast\Jobs\FixedClock;
use LumeWeb\Cast\Jobs\InMemoryLock;
use PHPUnit\Framework\TestCase;

final class InMemoryLockTest extends TestCase
{
    private FixedClock $clock;

    private InMemoryLock $lock;

    protected function setUp(): void
    {
        $this->clock = new FixedClock(1000);
        $this->lock = new InMemoryLock($this->clock);
    }

    public function testAcquireTakesAFreshLease(): void
    {
        self::assertTrue($this->lock->acquire('run', 60));

        self::assertTrue($this->lock->isHeld('run'));
        self::assertSame(1060, $this->lock->leaseExpiresAt('run'));
    }

    public function testSecondAcquireFailsWhileHeld(): void
    {
        $this->lock->acquire('run', 60);

        self::assertFalse($this->lock->acquire('run', 60));
    }

    public function testLeaseExpiresAfterTheTtlElapses(): void
    {
        $this->lock->acquire('run', 60);

        $this->clock->advance(60);

        self::assertFalse($this->lock->isHeld('run'));
    }

    public function testExpiredLeaseCanBeAcquiredAgain(): void
    {
        $this->lock->acquire('run', 60);
        $this->clock->advance(61);

        self::assertTrue($this->lock->acquire('run', 60));
    }

    public function testReleaseFreesTheLeaseImmediately(): void
    {
        $this->lock->acquire('run', 60);

        $this->lock->release('run');

        self::assertFalse($this->lock->isHeld('run'));
        self::assertNull($this->lock->leaseExpiresAt('run'));
    }

    public function testLeaseExpiryIsScopedPerKey(): void
    {
        $this->lock->acquire('a', 60);
        self::assertTrue($this->lock->acquire('b', 60));

        $this->lock->release('a');

        self::assertFalse($this->lock->isHeld('a'));
        self::assertTrue($this->lock->isHeld('b'));
    }

    public function testStaleLeaseReportsItsPastExpiryForDetection(): void
    {
        $this->lock->acquire('run', 60);
        $this->clock->advance(61);

        self::assertSame(1060, $this->lock->leaseExpiresAt('run'));
    }

    public function testLeaseExpiresAtIsNullBeforeAnyAcquire(): void
    {
        self::assertNull($this->lock->leaseExpiresAt('run'));
    }

    public function testAcquireRestartsTheTtlFromNowWhenReacquiring(): void
    {
        $this->lock->acquire('run', 60);
        $this->clock->advance(61);
        $this->lock->acquire('run', 60);

        self::assertSame(1121, $this->lock->leaseExpiresAt('run'));
        $this->clock->advance(59);
        self::assertTrue($this->lock->isHeld('run'));
    }
}
