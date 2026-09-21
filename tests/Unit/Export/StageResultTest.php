<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\StageResult;
use PHPUnit\Framework\TestCase;

/**
 * The pure outcome of one bounded pipeline stage unit: more/done/failure plus
 * an optional cancellation request, next resume cursor, progress delta and
 * emitted warnings.
 */
final class StageResultTest extends TestCase
{
    public function testMoreKeepsTheStageAliveWithCursorProgressAndWarnings(): void
    {
        $result = StageResult::more('post:1:50', progress: 3, warnings: ['slow page']);

        self::assertFalse($result->done);
        self::assertNull($result->failure);
        self::assertFalse($result->cancelled);
        self::assertSame('post:1:50', $result->cursor);
        self::assertSame(3, $result->progress);
        self::assertSame(['slow page'], $result->warnings);
    }

    public function testDoneFinishesTheStage(): void
    {
        $result = StageResult::done('', progress: 1);

        self::assertTrue($result->done);
        self::assertNull($result->failure);
    }

    public function testFailReportsASafeReason(): void
    {
        $result = StageResult::fail('capture timed out', warnings: ['retrying']);

        self::assertFalse($result->done);
        self::assertSame('capture timed out', $result->failure);
        self::assertFalse($result->cancelled);
    }

    public function testCancelRequestsAStopWithoutClaimingFailure(): void
    {
        $result = StageResult::cancel();

        self::assertFalse($result->done);
        self::assertTrue($result->cancelled);
        self::assertNull($result->failure);
    }

    public function testCancelCarriesTheResumeCursor(): void
    {
        $result = StageResult::cancel('post:9:50');

        self::assertTrue($result->cancelled);
        self::assertSame('post:9:50', $result->cursor);
    }
}
