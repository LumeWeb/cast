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
    private WorkItemRepository $repository;
    private WorkItemFactory $factory;

    protected function setUp(): void
    {
        $this->repository = new InMemoryWorkItemRepository();
        $this->factory = new WorkItemFactory();
    }

    public function testInsertCreatesAQueuedItem(): void
    {
        $outcome = $this->repository->insertCanonical($this->item('https://example.com/'));

        self::assertTrue($outcome->inserted());
        self::assertSame(1, $this->repository->countByStatus(WorkItemStatus::Queued));
        self::assertSame(1, $this->repository->pendingCount());
        self::assertTrue($this->repository->hasPending());
    }

    public function testDuplicateByUrlHashIsIgnored(): void
    {
        $this->repository->insertCanonical($this->item('https://example.com/'));
        $second = $this->repository->insertCanonical($this->item('https://example.com/'));

        self::assertTrue($second->duplicateIgnored());
        self::assertFalse($second->inserted());
        self::assertSame(1, $this->repository->countByStatus(WorkItemStatus::Queued));
    }

    public function testDistinctQueriesProduceDistinctItems(): void
    {
        $this->repository->insertCanonical($this->item('https://example.com/?a=1'));
        $this->repository->insertCanonical($this->item('https://example.com/?b=2'));

        self::assertSame(2, $this->repository->countByStatus(WorkItemStatus::Queued));
    }

    public function testLowerNumericPriorityReplacesQueuedPriority(): void
    {
        $hash = md5('https://example.com/');
        $this->repository->insertCanonical($this->item('https://example.com/'), 10);

        $outcome = $this->repository->insertCanonical($this->item('https://example.com/'), 1);

        self::assertTrue($outcome->priorityUpdated());
        self::assertSame(1, $this->repository->priorityOf($hash));
        self::assertTrue($this->repository->insertCanonical($this->item('https://example.com/'), 0)->priorityUpdated());
        self::assertSame(0, $this->repository->priorityOf($hash));
    }

    public function testHigherNumericPriorityNeverLowersQueuedPriority(): void
    {
        $hash = md5('https://example.com/');
        $this->repository->insertCanonical($this->item('https://example.com/'), 1);

        $outcome = $this->repository->insertCanonical($this->item('https://example.com/'), 10);

        self::assertTrue($outcome->duplicateIgnored());
        self::assertSame(1, $this->repository->priorityOf($hash));
    }

    public function testPriorityUpdateDoesNotResetProcessedState(): void
    {
        $hash = md5('https://example.com/');
        $this->repository->insertCanonical($this->item('https://example.com/'), 10);
        self::assertTrue($this->repository->transition($hash, WorkItemStatus::Processing));

        $outcome = $this->repository->insertCanonical($this->item('https://example.com/'), 1);

        self::assertTrue($outcome->priorityUpdated());
        self::assertSame(0, $this->repository->countByStatus(WorkItemStatus::Queued), 'must not reset to queued');
        self::assertSame(1, $this->repository->countByStatus(WorkItemStatus::Processing));
    }

    public function testFirstSuccessfulItemIsPreserved(): void
    {
        $hash = md5('https://example.com/');
        $this->repository->insertCanonical($this->item('https://example.com/'), 10);
        $this->repository->transition($hash, WorkItemStatus::Done);

        $outcome = $this->repository->insertCanonical($this->item('https://example.com/'), 1);

        self::assertTrue($outcome->duplicateIgnored());
        self::assertFalse($outcome->priorityUpdated());
        self::assertSame(10, $this->repository->priorityOf($hash));
        self::assertSame(1, $this->repository->countByStatus(WorkItemStatus::Done));
    }

    public function testTransitionRequiresAnExistingHash(): void
    {
        self::assertFalse($this->repository->transition(md5('missing'), WorkItemStatus::Done));
    }

    public function testPendingCountReachesZeroAtFixedPoint(): void
    {
        $a = $this->item('https://example.com/a/');
        $b = $this->item('https://example.com/b/');
        $this->repository->insertCanonical($a);
        $this->repository->insertCanonical($b);

        self::assertSame(2, $this->repository->pendingCount());

        // A row in flight stays pending until it finishes.
        self::assertTrue($this->repository->transition($a->urlHash(), WorkItemStatus::Processing));
        self::assertSame(2, $this->repository->pendingCount());

        self::assertTrue($this->repository->transition($a->urlHash(), WorkItemStatus::Done));
        self::assertTrue($this->repository->transition($b->urlHash(), WorkItemStatus::Done));

        self::assertSame(0, $this->repository->pendingCount());
        self::assertFalse($this->repository->hasPending());
    }

    public function testFailedItemsAreNotPending(): void
    {
        $item = $this->item('https://example.com/');
        $this->repository->insertCanonical($item);
        $this->repository->transition($item->urlHash(), WorkItemStatus::Failed);

        self::assertSame(0, $this->repository->pendingCount());
    }

    public function testRepositoryImplementsPersistenceAgnosticInterface(): void
    {
        self::assertInstanceOf(WorkItemRepository::class, $this->repository);
    }

    public function testClaimNextReturnsNullWhenNothingIsQueued(): void
    {
        self::assertNull($this->repository->claimNext());
    }

    public function testClaimNextTakesTheLowestNumericPriorityFirst(): void
    {
        $low = $this->item('https://example.com/low/');
        $high = $this->item('https://example.com/high/');
        $this->repository->insertCanonical($high, 10);
        $this->repository->insertCanonical($low, 1);

        $claim = $this->repository->claimNext();

        self::assertNotNull($claim);
        self::assertSame($low->urlHash(), $claim->urlHash());
        self::assertSame(1, $this->repository->countByStatus(WorkItemStatus::Processing));
        self::assertSame(1, $this->repository->countByStatus(WorkItemStatus::Queued), 'exactly one row is claimed per call');
    }

    public function testClaimNextDoesNotReclaimAProcessingRow(): void
    {
        $item = $this->item('https://example.com/');
        $this->repository->insertCanonical($item);
        $this->repository->transition($item->urlHash(), WorkItemStatus::Processing);

        self::assertNull($this->repository->claimNext());
    }

    public function testAttemptCountGrowsAcrossRetries(): void
    {
        $item = $this->item('https://example.com/');
        $this->repository->insertCanonical($item);

        self::assertSame(0, $this->repository->attemptCountOf($item->urlHash()));

        self::assertNotNull($this->repository->claimNext());
        self::assertSame(1, $this->repository->attemptCountOf($item->urlHash()));

        // A retry re-queues the row; only then may it be claimed again.
        self::assertTrue($this->repository->scheduleRetry($item->urlHash(), 0));
        self::assertNotNull($this->repository->claimNext());
        self::assertSame(2, $this->repository->attemptCountOf($item->urlHash()));
    }

    public function testAttemptCountOfUnknownHashIsZero(): void
    {
        self::assertSame(0, $this->repository->attemptCountOf(md5('missing')));
    }

    public function testScheduleRetryRequeuesARowWithADelay(): void
    {
        $item = $this->item('https://example.com/');
        $this->repository->insertCanonical($item);
        $this->repository->transition($item->urlHash(), WorkItemStatus::Failed);

        self::assertTrue($this->repository->scheduleRetry($item->urlHash(), 5));

        self::assertSame(1, $this->repository->countByStatus(WorkItemStatus::Queued));
        self::assertSame(0, $this->repository->countByStatus(WorkItemStatus::Failed));
    }

    public function testScheduleRetryReturnsFalseForAnUnknownHash(): void
    {
        self::assertFalse($this->repository->scheduleRetry(md5('missing'), 5));
    }

    public function testScheduledRetryIsNotClaimableUntilItsDelayPasses(): void
    {
        $now = 1_000;
        $repository = new InMemoryWorkItemRepository(clock: static function () use (&$now): int {
            return $now;
        });
        $item = $this->item('https://example.com/');
        $repository->insertCanonical($item);

        $repository->claimNext();
        self::assertSame(1, $repository->attemptCountOf($item->urlHash()));
        self::assertTrue($repository->scheduleRetry($item->urlHash(), 5));

        // Before the delay passes the row is never claimed again: the attempt
        // budget does not advance and the row stays queued-pending.
        $repository->claimNext();
        self::assertSame(1, $repository->attemptCountOf($item->urlHash()), 'a not-yet-due retry must not be re-claimed');
        self::assertSame(1, $repository->pendingCount(), 'a scheduled retry still counts as pending');
        self::assertSame(0, $repository->countByStatus(WorkItemStatus::Processing), 'a not-yet-due retry is not claimed');

        // Once the delay passes the same row is claimed again and attempts advance.
        $now = 1_005;
        $repository->claimNext();
        self::assertSame(2, $repository->attemptCountOf($item->urlHash()), 'a due retry is claimed again');
        self::assertSame(1, $repository->countByStatus(WorkItemStatus::Processing));
        self::assertSame(0, $repository->countByStatus(WorkItemStatus::Queued));
    }

    public function testClaimNextRewritableReturnsLowestPriorityDoneItem(): void
    {
        $low = $this->item('https://example.com/low/');
        $high = $this->item('https://example.com/high/');
        $this->repository->insertCanonical($high, 10);
        $this->repository->insertCanonical($low, 1);
        $this->repository->transition($high->urlHash(), WorkItemStatus::Done);
        $this->repository->transition($low->urlHash(), WorkItemStatus::Done);

        $claimed = $this->repository->claimNextRewritable();

        self::assertNotNull($claimed);
        self::assertSame($low->urlHash(), $claimed->urlHash());
    }

    public function testClaimNextRewritableSkipsBinaryFixedAssets(): void
    {
        $binary = $this->item('https://example.com/wp-content/uploads/2024/photo.png');
        $this->repository->insertCanonical($binary);
        $this->repository->transition($binary->urlHash(), WorkItemStatus::Done);

        self::assertNull($this->repository->claimNextRewritable());
    }

    public function testClaimNextRewritableClaimsTextLikeAssets(): void
    {
        $css = $this->item('https://example.com/wp-content/themes/x/style.css');
        $json = $this->item('https://example.com/wp-content/themes/x/config.json');
        $this->repository->insertCanonical($css);
        $this->repository->insertCanonical($json);
        $this->repository->transition($css->urlHash(), WorkItemStatus::Done);
        $this->repository->transition($json->urlHash(), WorkItemStatus::Done);

        $claimed = $this->repository->claimNextRewritable();

        self::assertNotNull($claimed);
        self::assertSame($css->urlHash(), $claimed->urlHash());
        self::assertSame(
            2,
            $this->repository->countByStatus(WorkItemStatus::Done),
            'the claim is a peek, so both text-like rows stay Done until the stage rewrites them',
        );
    }

    public function testClaimNextRewritableIgnoresNonDoneRows(): void
    {
        $item = $this->item('https://example.com/only-queued/');
        $this->repository->insertCanonical($item);

        self::assertNull($this->repository->claimNextRewritable());
    }

    public function testClaimNextRewritableDoesNotChangeStatus(): void
    {
        $item = $this->item('https://example.com/about/');
        $this->repository->insertCanonical($item);
        $this->repository->transition($item->urlHash(), WorkItemStatus::Done);

        $claimed = $this->repository->claimNextRewritable();

        self::assertNotNull($claimed);
        self::assertSame(1, $this->repository->countByStatus(WorkItemStatus::Done), 'the claim is a peek, not a state transition');
    }

    public function testRewrittenStatusIsNotRewritableAgain(): void
    {
        $item = $this->item('https://example.com/about/');
        $this->repository->insertCanonical($item);
        $this->repository->transition($item->urlHash(), WorkItemStatus::Done);
        $this->repository->transition($item->urlHash(), WorkItemStatus::Rewritten);

        self::assertNull($this->repository->claimNextRewritable(), 'a rewritten row must leave the rewritable pool');
        self::assertSame(0, $this->repository->countByStatus(WorkItemStatus::Done));
        self::assertSame(1, $this->repository->countByStatus(WorkItemStatus::Rewritten));
    }

    public function testClearEmptiesTheQueueSoANewRunCanRequeneEverything(): void
    {
        // A completed run leaves terminal rows; clear() must remove them or
        // first-seen-wins would never re-queue those URLs for the next run.
        $item = $this->item('https://example.com/');
        $this->repository->insertCanonical($item);
        $this->repository->transition($item->urlHash(), WorkItemStatus::Done);
        $this->repository->transition($item->urlHash(), WorkItemStatus::Rewritten);

        $this->repository->clear();

        self::assertSame(0, $this->repository->countByStatus(WorkItemStatus::Rewritten));
        self::assertSame(0, $this->repository->pendingCount());
        self::assertTrue($this->repository->insertCanonical($item)->inserted(), 'after clear the same URL is queued again');
    }

    private function item(string $url): WorkItem
    {
        return $this->factory->fromString($url);
    }
}
