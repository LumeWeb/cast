<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\InMemoryWorkItemRepository;
use LumeWeb\Cast\Export\WorkItem;
use LumeWeb\Cast\Export\WorkItemFactory;
use LumeWeb\Cast\Export\WorkItemRepository;
use LumeWeb\Cast\Export\WorkItemStatus;
use PHPUnit\Framework\TestCase;

final class WorkItemRepositoryTest extends TestCase
{
    /**
     * The run id most of the contract tests operate on; every call is scoped
     * to this run so the shared-table contract is exercised explicitly.
     */
    private const RUN = 'run-1';

    private WorkItemRepository $repository;
    private WorkItemFactory $factory;

    protected function setUp(): void
    {
        $this->repository = new InMemoryWorkItemRepository();
        $this->factory = new WorkItemFactory();
    }

    public function testInsertCreatesAQueuedItem(): void
    {
        $outcome = $this->repository->insertCanonical(self::RUN, $this->item('https://example.com/'));

        self::assertTrue($outcome->inserted());
        self::assertSame(1, $this->repository->countByStatus(self::RUN, WorkItemStatus::Queued));
        self::assertSame(1, $this->repository->pendingCount(self::RUN,));
        self::assertTrue($this->repository->hasPending(self::RUN,));
    }

    public function testDuplicateByUrlHashIsIgnored(): void
    {
        $this->repository->insertCanonical(self::RUN, $this->item('https://example.com/'));
        $second = $this->repository->insertCanonical(self::RUN, $this->item('https://example.com/'));

        self::assertTrue($second->duplicateIgnored());
        self::assertFalse($second->inserted());
        self::assertSame(1, $this->repository->countByStatus(self::RUN, WorkItemStatus::Queued));
    }

    public function testDistinctQueriesProduceDistinctItems(): void
    {
        $this->repository->insertCanonical(self::RUN, $this->item('https://example.com/?a=1'));
        $this->repository->insertCanonical(self::RUN, $this->item('https://example.com/?b=2'));

        self::assertSame(2, $this->repository->countByStatus(self::RUN, WorkItemStatus::Queued));
    }

    public function testLowerNumericPriorityReplacesQueuedPriority(): void
    {
        $hash = md5('https://example.com/');
        $this->repository->insertCanonical(self::RUN, $this->item('https://example.com/'), 10);

        $outcome = $this->repository->insertCanonical(self::RUN, $this->item('https://example.com/'), 1);

        self::assertTrue($outcome->priorityUpdated());
        self::assertSame(1, $this->repository->priorityOf(self::RUN, $hash));
        self::assertTrue($this->repository->insertCanonical(self::RUN, $this->item('https://example.com/'), 0)->priorityUpdated());
        self::assertSame(0, $this->repository->priorityOf(self::RUN, $hash));
    }

    public function testHigherNumericPriorityNeverLowersQueuedPriority(): void
    {
        $hash = md5('https://example.com/');
        $this->repository->insertCanonical(self::RUN, $this->item('https://example.com/'), 1);

        $outcome = $this->repository->insertCanonical(self::RUN, $this->item('https://example.com/'), 10);

        self::assertTrue($outcome->duplicateIgnored());
        self::assertSame(1, $this->repository->priorityOf(self::RUN, $hash));
    }

    public function testPriorityUpdateDoesNotResetProcessedState(): void
    {
        $hash = md5('https://example.com/');
        $this->repository->insertCanonical(self::RUN, $this->item('https://example.com/'), 10);
        self::assertTrue($this->repository->transition(self::RUN, $hash, WorkItemStatus::Processing));

        $outcome = $this->repository->insertCanonical(self::RUN, $this->item('https://example.com/'), 1);

        self::assertTrue($outcome->priorityUpdated());
        self::assertSame(0, $this->repository->countByStatus(self::RUN, WorkItemStatus::Queued), 'must not reset to queued');
        self::assertSame(1, $this->repository->countByStatus(self::RUN, WorkItemStatus::Processing));
    }

    public function testFirstSuccessfulItemIsPreserved(): void
    {
        $hash = md5('https://example.com/');
        $this->repository->insertCanonical(self::RUN, $this->item('https://example.com/'), 10);
        $this->repository->transition(self::RUN, $hash, WorkItemStatus::Done);

        $outcome = $this->repository->insertCanonical(self::RUN, $this->item('https://example.com/'), 1);

        self::assertTrue($outcome->duplicateIgnored());
        self::assertFalse($outcome->priorityUpdated());
        self::assertSame(10, $this->repository->priorityOf(self::RUN, $hash));
        self::assertSame(1, $this->repository->countByStatus(self::RUN, WorkItemStatus::Done));
    }

    public function testTransitionRequiresAnExistingHash(): void
    {
        self::assertFalse($this->repository->transition(self::RUN, md5('missing'), WorkItemStatus::Done));
    }

    public function testPendingCountReachesZeroAtFixedPoint(): void
    {
        $a = $this->item('https://example.com/a/');
        $b = $this->item('https://example.com/b/');
        $this->repository->insertCanonical(self::RUN, $a);
        $this->repository->insertCanonical(self::RUN, $b);

        self::assertSame(2, $this->repository->pendingCount(self::RUN,));

        // A row in flight stays pending until it finishes.
        self::assertTrue($this->repository->transition(self::RUN, $a->urlHash(), WorkItemStatus::Processing));
        self::assertSame(2, $this->repository->pendingCount(self::RUN,));

        self::assertTrue($this->repository->transition(self::RUN, $a->urlHash(), WorkItemStatus::Done));
        self::assertTrue($this->repository->transition(self::RUN, $b->urlHash(), WorkItemStatus::Done));

        self::assertSame(0, $this->repository->pendingCount(self::RUN,));
        self::assertFalse($this->repository->hasPending(self::RUN,));
    }

    public function testFailedItemsAreNotPending(): void
    {
        $item = $this->item('https://example.com/');
        $this->repository->insertCanonical(self::RUN, $item);
        $this->repository->transition(self::RUN, $item->urlHash(), WorkItemStatus::Failed);

        self::assertSame(0, $this->repository->pendingCount(self::RUN,));
    }

    public function testRepositoryImplementsPersistenceAgnosticInterface(): void
    {
        self::assertInstanceOf(WorkItemRepository::class, $this->repository);
    }

    public function testClaimNextReturnsNullWhenNothingIsQueued(): void
    {
        self::assertNull($this->repository->claimNext(self::RUN,));
    }

    public function testClaimNextTakesTheLowestNumericPriorityFirst(): void
    {
        $low = $this->item('https://example.com/low/');
        $high = $this->item('https://example.com/high/');
        $this->repository->insertCanonical(self::RUN, $high, 10);
        $this->repository->insertCanonical(self::RUN, $low, 1);

        $claim = $this->repository->claimNext(self::RUN,);

        self::assertNotNull($claim);
        self::assertSame($low->urlHash(), $claim->urlHash());
        self::assertSame(1, $this->repository->countByStatus(self::RUN, WorkItemStatus::Processing));
        self::assertSame(1, $this->repository->countByStatus(self::RUN, WorkItemStatus::Queued), 'exactly one row is claimed per call');
    }

    public function testClaimNextDoesNotReclaimAProcessingRow(): void
    {
        $item = $this->item('https://example.com/');
        $this->repository->insertCanonical(self::RUN, $item);
        $this->repository->transition(self::RUN, $item->urlHash(), WorkItemStatus::Processing);

        self::assertNull($this->repository->claimNext(self::RUN,));
    }

    public function testAttemptCountGrowsAcrossRetries(): void
    {
        $item = $this->item('https://example.com/');
        $this->repository->insertCanonical(self::RUN, $item);

        self::assertSame(0, $this->repository->attemptCountOf(self::RUN, $item->urlHash()));

        self::assertNotNull($this->repository->claimNext(self::RUN,));
        self::assertSame(1, $this->repository->attemptCountOf(self::RUN, $item->urlHash()));

        // A retry re-queues the row; only then may it be claimed again.
        self::assertTrue($this->repository->scheduleRetry(self::RUN, $item->urlHash(), 0));
        self::assertNotNull($this->repository->claimNext(self::RUN,));
        self::assertSame(2, $this->repository->attemptCountOf(self::RUN, $item->urlHash()));
    }

    public function testAttemptCountOfUnknownHashIsZero(): void
    {
        self::assertSame(0, $this->repository->attemptCountOf(self::RUN, md5('missing')));
    }

    public function testScheduleRetryRequeuesARowWithADelay(): void
    {
        $item = $this->item('https://example.com/');
        $this->repository->insertCanonical(self::RUN, $item);
        $this->repository->transition(self::RUN, $item->urlHash(), WorkItemStatus::Failed);

        self::assertTrue($this->repository->scheduleRetry(self::RUN, $item->urlHash(), 5));

        self::assertSame(1, $this->repository->countByStatus(self::RUN, WorkItemStatus::Queued));
        self::assertSame(0, $this->repository->countByStatus(self::RUN, WorkItemStatus::Failed));
    }

    public function testScheduleRetryReturnsFalseForAnUnknownHash(): void
    {
        self::assertFalse($this->repository->scheduleRetry(self::RUN, md5('missing'), 5));
    }

    public function testScheduledRetryIsNotClaimableUntilItsDelayPasses(): void
    {
        $now = 1_000;
        $repository = new InMemoryWorkItemRepository(clock: static function () use (&$now): int {
            return $now;
        });
        $item = $this->item('https://example.com/');
        $repository->insertCanonical(self::RUN, $item);

        $repository->claimNext(self::RUN,);
        self::assertSame(1, $repository->attemptCountOf(self::RUN, $item->urlHash()));
        self::assertTrue($repository->scheduleRetry(self::RUN, $item->urlHash(), 5));

        // Before the delay passes the row is never claimed again: the attempt
        // budget does not advance and the row stays queued-pending.
        $repository->claimNext(self::RUN,);
        self::assertSame(1, $repository->attemptCountOf(self::RUN, $item->urlHash()), 'a not-yet-due retry must not be re-claimed');
        self::assertSame(1, $repository->pendingCount(self::RUN,), 'a scheduled retry still counts as pending');
        self::assertSame(0, $repository->countByStatus(self::RUN, WorkItemStatus::Processing), 'a not-yet-due retry is not claimed');

        // Once the delay passes the same row is claimed again and attempts advance.
        $now = 1_005;
        $repository->claimNext(self::RUN,);
        self::assertSame(2, $repository->attemptCountOf(self::RUN, $item->urlHash()), 'a due retry is claimed again');
        self::assertSame(1, $repository->countByStatus(self::RUN, WorkItemStatus::Processing));
        self::assertSame(0, $repository->countByStatus(self::RUN, WorkItemStatus::Queued));
    }

    public function testClaimNextRewritableReturnsLowestPriorityDoneItem(): void
    {
        $low = $this->item('https://example.com/low/');
        $high = $this->item('https://example.com/high/');
        $this->repository->insertCanonical(self::RUN, $high, 10);
        $this->repository->insertCanonical(self::RUN, $low, 1);
        $this->repository->transition(self::RUN, $high->urlHash(), WorkItemStatus::Done);
        $this->repository->transition(self::RUN, $low->urlHash(), WorkItemStatus::Done);

        $claimed = $this->repository->claimNextRewritable(self::RUN);

        self::assertNotNull($claimed);
        self::assertSame($low->urlHash(), $claimed->urlHash());
    }

    public function testClaimNextRewritableSkipsBinaryFixedAssets(): void
    {
        $binary = $this->item('https://example.com/wp-content/uploads/2024/photo.png');
        $this->repository->insertCanonical(self::RUN, $binary);
        $this->repository->transition(self::RUN, $binary->urlHash(), WorkItemStatus::Done);

        self::assertNull($this->repository->claimNextRewritable(self::RUN));
    }

    public function testClaimNextRewritableClaimsTextLikeAssets(): void
    {
        $css = $this->item('https://example.com/wp-content/themes/x/style.css');
        $json = $this->item('https://example.com/wp-content/themes/x/config.json');
        $this->repository->insertCanonical(self::RUN, $css);
        $this->repository->insertCanonical(self::RUN, $json);
        $this->repository->transition(self::RUN, $css->urlHash(), WorkItemStatus::Done);
        $this->repository->transition(self::RUN, $json->urlHash(), WorkItemStatus::Done);

        $claimed = $this->repository->claimNextRewritable(self::RUN);

        self::assertNotNull($claimed);
        self::assertSame($css->urlHash(), $claimed->urlHash());
        self::assertSame(
            2,
            $this->repository->countByStatus(self::RUN, WorkItemStatus::Done),
            'the claim is a peek, so both text-like rows stay Done until the stage rewrites them',
        );
    }

    public function testClaimNextRewritableIgnoresNonDoneRows(): void
    {
        $item = $this->item('https://example.com/only-queued/');
        $this->repository->insertCanonical(self::RUN, $item);

        self::assertNull($this->repository->claimNextRewritable(self::RUN));
    }

    public function testClaimNextRewritableDoesNotChangeStatus(): void
    {
        $item = $this->item('https://example.com/about/');
        $this->repository->insertCanonical(self::RUN, $item);
        $this->repository->transition(self::RUN, $item->urlHash(), WorkItemStatus::Done);

        $claimed = $this->repository->claimNextRewritable(self::RUN);

        self::assertNotNull($claimed);
        self::assertSame(1, $this->repository->countByStatus(self::RUN, WorkItemStatus::Done), 'the claim is a peek, not a state transition');
    }

    public function testRewrittenStatusIsNotRewritableAgain(): void
    {
        $item = $this->item('https://example.com/about/');
        $this->repository->insertCanonical(self::RUN, $item);
        $this->repository->transition(self::RUN, $item->urlHash(), WorkItemStatus::Done);
        $this->repository->transition(self::RUN, $item->urlHash(), WorkItemStatus::Rewritten);

        self::assertNull($this->repository->claimNextRewritable(self::RUN), 'a rewritten row must leave the rewritable pool');
        self::assertSame(0, $this->repository->countByStatus(self::RUN, WorkItemStatus::Done));
        self::assertSame(1, $this->repository->countByStatus(self::RUN, WorkItemStatus::Rewritten));
    }

    public function testClearEmptiesTheQueueSoANewRunCanRequeneEverything(): void
    {
        // A completed run leaves terminal rows; clear() must remove them or
        // first-seen-wins would never re-queue those URLs for the next run.
        $item = $this->item('https://example.com/');
        $this->repository->insertCanonical(self::RUN, $item);
        $this->repository->transition(self::RUN, $item->urlHash(), WorkItemStatus::Done);
        $this->repository->transition(self::RUN, $item->urlHash(), WorkItemStatus::Rewritten);

        $this->repository->clear(self::RUN,);

        self::assertSame(0, $this->repository->countByStatus(self::RUN, WorkItemStatus::Rewritten));
        self::assertSame(0, $this->repository->pendingCount(self::RUN,));
        self::assertTrue($this->repository->insertCanonical(self::RUN, $item)->inserted(), 'after clear the same URL is queued again');
    }

    public function testDifferentRunsCannotSeeEachOthersWork(): void
    {
        $item = $this->item('https://example.com/');
        $this->repository->insertCanonical(self::RUN, $item);

        self::assertSame(1, $this->repository->countByStatus(self::RUN, WorkItemStatus::Queued));
        self::assertSame(0, $this->repository->countByStatus('run-2', WorkItemStatus::Queued), 'another run sees an empty queue');
        self::assertNull($this->repository->priorityOf('run-2', $item->urlHash()), 'another run cannot read this run\'s rows');
        self::assertNull($this->repository->claimNext('run-2'), 'another run cannot claim this run\'s rows');
        self::assertSame(1, $this->repository->pendingCount(self::RUN), 'the owning run still sees its pending work');
    }

    public function testClearOnlyRemovesTheGivenRunsRows(): void
    {
        $item = $this->item('https://example.com/');
        $this->repository->insertCanonical(self::RUN, $item);
        $this->repository->insertCanonical('run-2', $item);
        $this->repository->transition(self::RUN, $item->urlHash(), WorkItemStatus::Rewritten);

        $this->repository->clear(self::RUN);

        self::assertSame(0, $this->repository->countByStatus(self::RUN, WorkItemStatus::Rewritten));
        self::assertSame(1, $this->repository->countByStatus('run-2', WorkItemStatus::Queued), 'clearing one run leaves the other run\'s rows intact');
        self::assertTrue(
            $this->repository->insertCanonical(self::RUN, $item)->inserted(),
            'first-seen-wins is per run: the cleared run re-queues the same URL',
        );
    }

    public function testOnlyOneWorkerGetsAClaim(): void
    {
        $item = $this->item('https://example.com/');
        $this->repository->insertCanonical(self::RUN, $item);

        self::assertNotNull($this->repository->claimNext(self::RUN, 'worker-a'));
        self::assertNull($this->repository->claimNext(self::RUN, 'worker-b'), 'a live-leased row is never re-claimed by a second worker');
        self::assertSame(1, $this->repository->attemptCountOf(self::RUN, $item->urlHash()), 'the lost race never spends an attempt');
    }

    public function testLiveLeaseCannotBeStolen(): void
    {
        $now = 1_000;
        $repository = new InMemoryWorkItemRepository(clock: static function () use (&$now): int {
            return $now;
        });
        $item = $this->item('https://example.com/');
        $repository->insertCanonical(self::RUN, $item);

        $repository->claimNext(self::RUN, 'worker-a', 300);

        // Well inside the 300s lease (expires at 1300): a second worker must
        // not steal the in-flight row.
        $now = 1_200;
        self::assertNull($repository->claimNext(self::RUN, 'worker-b', 300));
        self::assertSame(1, $repository->attemptCountOf(self::RUN, $item->urlHash()));
    }

    public function testExpiredLeaseCanBeReclaimed(): void
    {
        $now = 1_000;
        $repository = new InMemoryWorkItemRepository(clock: static function () use (&$now): int {
            return $now;
        });
        $item = $this->item('https://example.com/');
        $repository->insertCanonical(self::RUN, $item);

        // Worker-a claims with a 60s lease (expires at 1060) and then dies.
        $repository->claimNext(self::RUN, 'worker-a', 60);
        $now = 1_061;

        $claim = $repository->claimNext(self::RUN, 'worker-b', 60);

        self::assertNotNull($claim, 'the expired in-flight row is reclaimable');
        self::assertSame($item->urlHash(), $claim->urlHash());
        self::assertSame(2, $repository->attemptCountOf(self::RUN, $item->urlHash()), 'the reclaim counts as the next attempt');
    }

    public function testTerminalTransitionClearsLeaseOwnership(): void
    {
        $now = 1_000;
        $repository = new InMemoryWorkItemRepository(clock: static function () use (&$now): int {
            return $now;
        });
        $item = $this->item('https://example.com/');
        $repository->insertCanonical(self::RUN, $item);
        $repository->claimNext(self::RUN, 'worker-a', 60);

        $repository->transition(self::RUN, $item->urlHash(), WorkItemStatus::Done);

        $this->assertNoLeaseOwnership($repository, $item->urlHash());

        // Long after the (cleared) lease window: the terminal row is not in
        // the reclaim pool.
        $now = 2_000;
        self::assertNull($repository->claimNext(self::RUN, 'worker-b', 60));
        self::assertSame(0, $repository->countByStatus(self::RUN, WorkItemStatus::Processing));
    }

    public function testScheduleRetryClearsLeaseOwnership(): void
    {
        $now = 1_000;
        $repository = new InMemoryWorkItemRepository(clock: static function () use (&$now): int {
            return $now;
        });
        $item = $this->item('https://example.com/');
        $repository->insertCanonical(self::RUN, $item);
        $repository->claimNext(self::RUN, 'worker-a', 60);

        self::assertTrue($repository->scheduleRetry(self::RUN, $item->urlHash(), 0));

        $this->assertNoLeaseOwnership($repository, $item->urlHash());
        self::assertSame(1, $repository->countByStatus(self::RUN, WorkItemStatus::Queued));
    }

    private function item(string $url): WorkItem
    {
        return $this->factory->fromString($url);
    }

    private function assertNoLeaseOwnership(InMemoryWorkItemRepository $repository, string $hash): void
    {
        $rows = (new \ReflectionProperty(InMemoryWorkItemRepository::class, 'rows'))->getValue($repository);
        $row = $rows[self::RUN][$hash];

        self::assertSame('', $row['worker'], 'the row no longer names a lease owner');
        self::assertSame(0, $row['leaseExpiresAt'], 'the lease window is cleared');
    }
}
