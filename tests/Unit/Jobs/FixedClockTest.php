<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Jobs;

use LumeWeb\Cast\Jobs\FixedClock;
use PHPUnit\Framework\TestCase;

final class FixedClockTest extends TestCase
{
    public function testNowReportsTheConfiguredInstant(): void
    {
        $clock = new FixedClock(1700000000);

        self::assertSame(1700000000, $clock->now());
    }

    public function testSetMovesTheClockForwardAndBackward(): void
    {
        $clock = new FixedClock(100);
        $clock->set(500);

        self::assertSame(500, $clock->now());
    }

    public function testAdvanceAddsSeconds(): void
    {
        $clock = new FixedClock(100);
        $clock->advance(60);

        self::assertSame(160, $clock->now());
    }

    public function testDefaultsToZeroWhenNoInstantProvided(): void
    {
        $clock = new FixedClock();

        self::assertSame(0, $clock->now());
    }
}
