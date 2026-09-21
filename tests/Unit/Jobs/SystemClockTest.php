<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Jobs;

use LumeWeb\Cast\Jobs\SystemClock;
use PHPUnit\Framework\TestCase;

final class SystemClockTest extends TestCase
{
    public function testNowReturnsTheCurrentUnixEpochInSeconds(): void
    {
        $clock = new SystemClock();

        self::assertEqualsWithDelta(time(), $clock->now(), 2);
    }

    public function testNowReturnsAPositiveTimestamp(): void
    {
        self::assertGreaterThan(0, (new SystemClock())->now());
    }
}
