<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Jobs;

use LumeWeb\Cast\Jobs\TickResult;
use PHPUnit\Framework\TestCase;

final class TickResultTest extends TestCase
{
    public function testMoreMeansContinueWithoutFailures(): void
    {
        $result = TickResult::more();

        self::assertFalse($result->finished);
        self::assertNull($result->failure);
        self::assertFalse($result->stale);
    }

    public function testDoneMeansTheRunFinishedCleanly(): void
    {
        $result = TickResult::done();

        self::assertTrue($result->finished);
        self::assertNull($result->failure);
        self::assertFalse($result->stale);
    }

    public function testFailCarriesASafeReason(): void
    {
        $result = TickResult::fail('upload timed out');

        self::assertFalse($result->finished);
        self::assertSame('upload timed out', $result->failure);
        self::assertFalse($result->stale);
    }

    public function testStaleSignalsTheWatchdogReclaimBoundary(): void
    {
        $result = TickResult::stale();

        self::assertFalse($result->finished);
        self::assertNull($result->failure);
        self::assertTrue($result->stale);
    }
}
