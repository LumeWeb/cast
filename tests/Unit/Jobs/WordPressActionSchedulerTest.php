<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Jobs;

use LumeWeb\Cast\Jobs\SchedulingFailedException;
use LumeWeb\Cast\Jobs\WordPressActionScheduler;
use PHPUnit\Framework\TestCase;

final class WordPressActionSchedulerTest extends TestCase
{
    private const HOOK = 'cast/export/auto-tick';

    private FakeActionSchedulerGateway $gateway;

    private WordPressActionScheduler $scheduler;

    protected function setUp(): void
    {
        $this->gateway = new FakeActionSchedulerGateway();
        $this->scheduler = new WordPressActionScheduler($this->gateway);
    }

    public function testIsScheduledIsFalseWhenNothingIsQueued(): void
    {
        self::assertFalse($this->scheduler->isScheduled(self::HOOK));
    }

    public function testScheduleSingleSchedulesThroughTheGatewayAtTheExactInstant(): void
    {
        self::assertTrue($this->scheduler->scheduleSingle(self::HOOK, 1700));

        self::assertTrue($this->scheduler->isScheduled(self::HOOK));
        self::assertSame(1700, $this->gateway->nextAt(self::HOOK, group: WordPressActionScheduler::DEFAULT_GROUP));
        self::assertSame(1, $this->gateway->scheduleCalls);
    }

    public function testScheduleSingleRefusesADuplicateWithoutOverwritingTheTimestamp(): void
    {
        $this->scheduler->scheduleSingle(self::HOOK, 1700);

        self::assertFalse($this->scheduler->scheduleSingle(self::HOOK, 1800));

        self::assertSame(1, $this->gateway->count());
        self::assertSame(1700, $this->gateway->nextAt(self::HOOK, group: WordPressActionScheduler::DEFAULT_GROUP));
        self::assertSame(1, $this->gateway->scheduleCalls);
    }

    public function testCancelSingleRemovesTheMatchingActionAndIsSafeWhenNoneIsQueued(): void
    {
        $this->scheduler->scheduleSingle(self::HOOK, 1700);

        $this->scheduler->cancelSingle(self::HOOK);

        self::assertFalse($this->scheduler->isScheduled(self::HOOK));
        self::assertSame(0, $this->gateway->count());

        // Cancelling again must remain a no-op, never a failure.
        $this->scheduler->cancelSingle(self::HOOK);
        self::assertFalse($this->scheduler->isScheduled(self::HOOK));
    }

    public function testEventsAreKeyedByHookPlusSerializedArgs(): void
    {
        $this->scheduler->scheduleSingle(self::HOOK, 1700, [3]);
        $this->scheduler->scheduleSingle(self::HOOK, 1800, [4]);

        self::assertTrue($this->scheduler->isScheduled(self::HOOK, [3]));
        self::assertTrue($this->scheduler->isScheduled(self::HOOK, [4]));
        self::assertSame(2, $this->gateway->count());

        self::assertFalse($this->scheduler->scheduleSingle(self::HOOK, 1900, [3]));
        self::assertSame(2, $this->gateway->count());
    }

    public function testDifferentHooksNeverCollide(): void
    {
        $this->scheduler->scheduleSingle(self::HOOK, 1700);
        $this->scheduler->scheduleSingle('cast/export/follow-up', 1005);

        self::assertTrue($this->scheduler->isScheduled(self::HOOK));
        self::assertTrue($this->scheduler->isScheduled('cast/export/follow-up'));
        self::assertSame(2, $this->gateway->count());
    }

    public function testCancelSingleOnlyRemovesTheMatchingHookAndArgs(): void
    {
        $this->scheduler->scheduleSingle(self::HOOK, 1700, [3]);
        $this->scheduler->scheduleSingle(self::HOOK, 1800, [4]);

        $this->scheduler->cancelSingle(self::HOOK, [3]);

        self::assertFalse($this->scheduler->isScheduled(self::HOOK, [3]));
        self::assertTrue($this->scheduler->isScheduled(self::HOOK, [4]));
        self::assertSame(1, $this->gateway->count());
    }

    public function testIsScheduledIsFalseWhileAnActionIsRunning(): void
    {
        $this->scheduler->scheduleSingle(self::HOOK, 1);
        $this->gateway->startRunning(self::HOOK, group: WordPressActionScheduler::DEFAULT_GROUP);

        // This is the production regression shape: as_next_scheduled_action()
        // returns boolean `true` (not a timestamp) while the tick is running,
        // and a rearm from inside that in-flight action MUST be admitted as a
        // fresh pending action — otherwise the loop dies silently. A running
        // action is therefore never "scheduled".
        self::assertFalse($this->scheduler->isScheduled(self::HOOK));
        self::assertTrue($this->scheduler->scheduleSingle(self::HOOK, 2));
        self::assertSame(2, $this->gateway->scheduleCalls);
        self::assertSame(2, $this->gateway->nextAt(self::HOOK, group: WordPressActionScheduler::DEFAULT_GROUP));
    }

    public function testPendingTickDedupesAndDoesNotCreateASecondPendingAction(): void
    {
        $this->scheduler->scheduleSingle(self::HOOK, 1700);

        // A pending tick already occupies the slot: a second rearm is refused
        // (false) before any insert, leaving exactly one pending action.
        self::assertFalse($this->scheduler->scheduleSingle(self::HOOK, 1800));
        self::assertSame(1, $this->gateway->scheduleCalls);
        self::assertSame(1, $this->gateway->count());
        self::assertSame(1700, $this->gateway->nextAt(self::HOOK, group: WordPressActionScheduler::DEFAULT_GROUP));
    }

    public function testCancelSingleOnlyCancelsPendingAndLeavesARunningActionToComplete(): void
    {
        $this->scheduler->scheduleSingle(self::HOOK, 1);
        $this->gateway->startRunning(self::HOOK, group: WordPressActionScheduler::DEFAULT_GROUP);

        // as_unschedule_all_actions() cancels pending matches only: the
        // in-flight tick (already moved out of pending) is left to run on.
        $this->scheduler->cancelSingle(self::HOOK);

        // With nothing pending and the action still running, the slot is free:
        // a retry is armed immediately rather than waiting for completion.
        self::assertFalse($this->scheduler->isScheduled(self::HOOK));
        self::assertTrue($this->scheduler->scheduleSingle(self::HOOK, 2));
        self::assertSame(2, $this->gateway->scheduleCalls);
        self::assertSame(2, $this->gateway->nextAt(self::HOOK, group: WordPressActionScheduler::DEFAULT_GROUP));

        // The bounded tick concludes: the pending retry alone remains.
        $this->gateway->stopRunning(self::HOOK, group: WordPressActionScheduler::DEFAULT_GROUP);
        self::assertSame(2, $this->gateway->nextAt(self::HOOK, group: WordPressActionScheduler::DEFAULT_GROUP));
        self::assertCount(0, $this->gateway->running);
    }

    public function testOneScheduledActionPerTickAcrossGroupsWhenOnlyOneGroupIsUsed(): void
    {
        // Same hook + args twice must never stack two pending ticks.
        $this->scheduler->scheduleSingle(self::HOOK, 1700);
        $this->scheduler->scheduleSingle(self::HOOK, 1800);
        $this->scheduler->scheduleSingle(self::HOOK, 1900);

        self::assertSame(1, $this->gateway->count());
        self::assertSame(1700, $this->gateway->nextAt(self::HOOK, group: WordPressActionScheduler::DEFAULT_GROUP));
    }

    public function testSeparateGroupsKeepIndependentTickSlots(): void
    {
        $first = $this->scheduler;
        $second = new WordPressActionScheduler($this->gateway, 'cast/export/retry');

        self::assertTrue($first->scheduleSingle(self::HOOK, 1700));
        self::assertTrue($second->scheduleSingle(self::HOOK, 1800));

        self::assertTrue($first->isScheduled(self::HOOK));
        self::assertTrue($second->isScheduled(self::HOOK));
        self::assertSame(2, $this->gateway->count());

        // Cancelling in one group never touches the other.
        $first->cancelSingle(self::HOOK);
        self::assertFalse($first->isScheduled(self::HOOK));
        self::assertTrue($second->isScheduled(self::HOOK));
    }

    public function testScheduleSingleAcceptsARetryOnceThePreviousActionHasConcluded(): void
    {
        $this->scheduler->scheduleSingle(self::HOOK, 1700);

        // Bounded tick concludes (or fails and the slot is released); the next
        // retry is a fresh single action, not a duplicate of a live one.
        $this->scheduler->cancelSingle(self::HOOK);

        self::assertTrue($this->scheduler->scheduleSingle(self::HOOK, 1900));
        self::assertSame(1900, $this->gateway->nextAt(self::HOOK, group: WordPressActionScheduler::DEFAULT_GROUP));
        self::assertSame(1, $this->gateway->count());
    }

    public function testScheduleSingleFailsLoudlyWhenTheGatewayRefuses(): void
    {
        $this->gateway->reject = true;

        // A declined Action Scheduler schedule must not be silently reported as
        // scheduled: the adapter fails loudly with an actionable exception.
        $this->expectException(SchedulingFailedException::class);
        $this->expectExceptionMessage('Action Scheduler');
        $this->scheduler->scheduleSingle(self::HOOK, 1700);
    }

    public function testScheduleSingleFailsLoudlyWhenTheGatewayIsUnavailable(): void
    {
        $this->gateway->available = false;

        // An unavailable Action Scheduler must not silently report success: the
        // adapter fails loudly and actionably, and nothing is stored.
        self::assertFalse($this->scheduler->isScheduled(self::HOOK));
        $this->expectException(SchedulingFailedException::class);
        $this->expectExceptionMessage('Action Scheduler');
        $this->scheduler->scheduleSingle(self::HOOK, 1700);
    }

    public function testADedupeDeclineStaysFalseAndNeverThrows(): void
    {
        // Already-scheduled dedupe returns false (never a loud failure), even
        // while the gateway would refuse new work: the isScheduled-first
        // guard short-circuits before any schedule attempt.
        $this->scheduler->scheduleSingle(self::HOOK, 1700);
        $this->gateway->reject = true;

        self::assertFalse($this->scheduler->scheduleSingle(self::HOOK, 1800));
        self::assertSame(1, $this->gateway->scheduleCalls);
        self::assertSame(1700, $this->gateway->nextAt(self::HOOK, group: WordPressActionScheduler::DEFAULT_GROUP));
    }
}
