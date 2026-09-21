<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Jobs;

use LumeWeb\Cast\Jobs\TickOutcome;
use LumeWeb\Cast\Jobs\TickOutcomeKind;
use PHPUnit\Framework\TestCase;

final class TickOutcomeTest extends TestCase
{
    public function testProvider(): void
    {
        foreach ($this->outcomes() as $name => $outcome) {
            self::assertSame($name, $outcome->kind->value, $name);
        }
    }

    public function testFailedCarriesTheReason(): void
    {
        $outcome = TickOutcome::failed('upload timed out');

        self::assertSame(TickOutcomeKind::Failed, $outcome->kind);
        self::assertSame('upload timed out', $outcome->reason);
    }

    public function testNonFailureOutcomesHaveNoReason(): void
    {
        self::assertNull(TickOutcome::ran()->reason);
        self::assertNull(TickOutcome::completed()->reason);
        self::assertNull(TickOutcome::retried()->reason);
    }

    public function testSkippedOutcomesAreReportedAsSkipped(): void
    {
        $skipped = [
            TickOutcome::skippedLocked(),
            TickOutcome::skippedStaleLock(),
            TickOutcome::skippedNoRun(),
            TickOutcome::skippedNotReady(),
        ];
        foreach ($skipped as $outcome) {
            self::assertTrue($outcome->kind->isSkipped(), $outcome->kind->value);
        }

        self::assertFalse(TickOutcome::ran()->kind->isSkipped());
        self::assertFalse(TickOutcome::completed()->kind->isSkipped());
    }

    /**
     * @return array<string, TickOutcome>
     */
    public function outcomes(): array
    {
        return [
            'ran' => TickOutcome::ran(),
            'completed' => TickOutcome::completed(),
            'failed' => TickOutcome::failed('boom'),
            'retried' => TickOutcome::retried(),
            'watchdog' => TickOutcome::watchdog(),
            'deferred' => TickOutcome::deferred(),
            'skipped_locked' => TickOutcome::skippedLocked(),
            'skipped_stale_lock' => TickOutcome::skippedStaleLock(),
            'skipped_no_run' => TickOutcome::skippedNoRun(),
            'skipped_not_ready' => TickOutcome::skippedNotReady(),
        ];
    }
}
