<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Jobs;

use LumeWeb\Cast\Export\ArtifactRetentionService;
use LumeWeb\Cast\Export\InMemoryRunRepository;
use LumeWeb\Cast\Export\RetentionPolicy;
use LumeWeb\Cast\Jobs\FixedClock;
use LumeWeb\Cast\Jobs\InMemoryScheduler;
use LumeWeb\Cast\Jobs\RetentionRunner;
use LumeWeb\Cast\Jobs\RetentionScheduler;
use LumeWeb\Cast\Tests\Unit\Export\FakeArtifactStore;
use PHPUnit\Framework\TestCase;

/**
 * The WP-Cron retention handler: runs the pure artifact GC against the policy
 * the composition's option reader produced, then re-arms the next periodic
 * sweep (deduplicated) so a quiet site still ages old artifacts out at least
 * once a day — and a single terminal-run arming can never switch GC off.
 */
final class RetentionRunnerTest extends TestCase
{
    private const NOW = 2_000_000_000;

    private FakeArtifactStore $store;

    private InMemoryScheduler $scheduler;

    private FixedClock $clock;

    private RetentionRunner $runner;

    private int $optionDays = 7;

    protected function setUp(): void
    {
        $this->store = new FakeArtifactStore();
        $this->scheduler = new InMemoryScheduler();
        $this->clock = new FixedClock(self::NOW);

        $this->runner = new RetentionRunner(
            new ArtifactRetentionService($this->store, new InMemoryRunRepository()),
            new RetentionScheduler($this->scheduler, $this->clock),
            $this->clock,
            fn (): RetentionPolicy => RetentionPolicy::fromOption($this->optionDays),
        );
    }

    public function testRunCollectsThroughTheConfiguredPolicyAndReturnsTheSummary(): void
    {
        $this->store->add('run-old.zip', self::NOW - 9_000_000);
        $this->store->add('run-fresh.zip', self::NOW - 1000);

        $summary = $this->runner->run();

        // Expired artifact collected through the option-driven 7-day policy.
        self::assertSame(1, $summary->deleted);
        self::assertSame(['run-old.zip'], $summary->deletedNames);
        self::assertSame(7, $summary->retentionDays);
        self::assertSame(['run-fresh.zip'], $this->store->names());
    }

    public function testRunRearmsThePeriodicSweepEveryTime(): void
    {
        $this->runner->run();

        self::assertSame(1, $this->scheduler->count());
        self::assertTrue($this->scheduler->isScheduled(RetentionScheduler::RETENTION_HOOK));
        self::assertSame(
            self::NOW + RetentionScheduler::DEFAULT_PERIODIC_SECONDS,
            $this->scheduler->nextAt(RetentionScheduler::RETENTION_HOOK),
        );
    }

    public function testRunHonorsTheOptionDrivenPolicyIncludingDisabled(): void
    {
        $this->store->add('run-old.zip', self::NOW - 9_000_000);

        $this->optionDays = 0;
        $summary = $this->runner->run();

        self::assertTrue($summary->disabled);
        self::assertSame(0, $summary->deleted);
        self::assertSame(['run-old.zip'], $this->store->names());

        // Even a disabled sweep still re-arms the daily re-check so the next
        // sweep re-reads the option (an admin re-enabling retention after a
        // deploy is picked up without a new terminal run).
        self::assertSame(
            self::NOW + RetentionScheduler::DEFAULT_PERIODIC_SECONDS,
            $this->scheduler->nextAt(RetentionScheduler::RETENTION_HOOK),
        );
    }

    public function testRunUsesTheClockInstant(): void
    {
        $this->store->add('run-old.zip', self::NOW - 9_000_000);

        $this->clock->advance(100);
        $summary = $this->runner->run();

        self::assertSame(self::NOW + 100, $summary->now);
    }
}
