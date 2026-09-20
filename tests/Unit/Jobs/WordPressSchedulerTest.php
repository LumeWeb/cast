<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Jobs;

use LumeWeb\Cast\Jobs\WordPressScheduler;
use PHPUnit\Framework\TestCase;

final class WordPressSchedulerTest extends TestCase
{
    private FakeCronGateway $cron;

    private WordPressScheduler $scheduler;

    protected function setUp(): void
    {
        $this->cron = new FakeCronGateway();
        $this->scheduler = new WordPressScheduler($this->cron);
    }

    public function testIsScheduledIsFalseWhenNothingIsQueued(): void
    {
        self::assertFalse($this->scheduler->isScheduled('cast/export/auto-tick'));
    }

    public function testScheduleSinglePassesTheExactTimestampAndKey(): void
    {
        self::assertTrue($this->scheduler->scheduleSingle('cast/export/auto-tick', 1700));

        self::assertTrue($this->scheduler->isScheduled('cast/export/auto-tick'));
        self::assertSame(1700, $this->cron->nextAt('cast/export/auto-tick'));
        self::assertSame(1, $this->cron->scheduleCalls);
    }

    public function testScheduleSingleRefusesADuplicateEventWithoutOverwritingIt(): void
    {
        $this->scheduler->scheduleSingle('cast/export/auto-tick', 1700);

        self::assertFalse($this->scheduler->scheduleSingle('cast/export/auto-tick', 1800));

        self::assertSame(1, $this->cron->count());
        self::assertSame(1700, $this->cron->nextAt('cast/export/auto-tick'));
        self::assertSame(1, $this->cron->scheduleCalls);
    }

    public function testEventsAreKeyedByHookPlusSerializedArgs(): void
    {
        $this->scheduler->scheduleSingle('cast/export/auto-tick', 1700, [3]);
        $this->scheduler->scheduleSingle('cast/export/auto-tick', 1800, [4]);

        self::assertTrue($this->scheduler->isScheduled('cast/export/auto-tick', [3]));
        self::assertTrue($this->scheduler->isScheduled('cast/export/auto-tick', [4]));
        self::assertSame(2, $this->cron->count());

        self::assertFalse($this->scheduler->scheduleSingle('cast/export/auto-tick', 1900, [3]));
        self::assertSame(2, $this->cron->count());
    }

    public function testDifferentHooksNeverCollide(): void
    {
        $this->scheduler->scheduleSingle('cast/export/auto-tick', 1700);
        $this->scheduler->scheduleSingle('cast/export/follow-up', 1005);

        self::assertTrue($this->scheduler->isScheduled('cast/export/auto-tick'));
        self::assertTrue($this->scheduler->isScheduled('cast/export/follow-up'));
        self::assertSame(2, $this->cron->count());
    }

    public function testCancelSingleRemovesTheMatchingEvent(): void
    {
        $this->scheduler->scheduleSingle('cast/export/auto-tick', 1700);

        $this->scheduler->cancelSingle('cast/export/auto-tick');

        self::assertFalse($this->scheduler->isScheduled('cast/export/auto-tick'));
        self::assertSame(0, $this->cron->count());
    }

    public function testCancelSingleIsSafeWhenNothingIsScheduled(): void
    {
        $this->scheduler->cancelSingle('cast/export/auto-tick');

        self::assertFalse($this->scheduler->isScheduled('cast/export/auto-tick'));
        self::assertSame(0, $this->cron->count());
    }

    public function testCancelSingleOnlyRemovesTheMatchingHookAndArgs(): void
    {
        $this->scheduler->scheduleSingle('cast/export/auto-tick', 1700, [3]);
        $this->scheduler->scheduleSingle('cast/export/auto-tick', 1800, [4]);

        $this->scheduler->cancelSingle('cast/export/auto-tick', [3]);

        self::assertFalse($this->scheduler->isScheduled('cast/export/auto-tick', [3]));
        self::assertTrue($this->scheduler->isScheduled('cast/export/auto-tick', [4]));
    }

    public function testScheduleSingleReportsFalseWhenTheGatewayRefuses(): void
    {
        $this->cron->scheduleResult = false;

        self::assertFalse($this->scheduler->scheduleSingle('cast/export/auto-tick', 1700));
        self::assertFalse($this->scheduler->isScheduled('cast/export/auto-tick'));
    }
}
