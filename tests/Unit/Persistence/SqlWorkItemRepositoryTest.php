<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Persistence;

use LumeWeb\Cast\Export\WorkItem;
use LumeWeb\Cast\Export\WorkItemFactory;
use LumeWeb\Cast\Export\WorkItemRepository;
use LumeWeb\Cast\Export\WorkItemStatus;
use LumeWeb\Cast\Persistence\SqlWorkItemRepository;
use PHPUnit\Framework\TestCase;

/**
 * Contract suite for the durable SQL-backed queue, mirroring the in-memory
 * {@see \LumeWeb\Cast\Tests\Unit\Export\WorkItemRepositoryTest} one-to-one so
 * the SQL implementation honours the exact same fixed-point semantics: URL-hash
 * uniqueness, first-successful-item-wins, lower-priority-replaces without
 * touching status, atomic conditional claims and the non-destructive rewritable
 * peek. Unit tests run against {@see FakeWpDbGateway}; the real SQL is proven
 * against MariaDB in tests/Integration/SqlWorkItemRepositoryIntegrationTest.php.
 */
final class SqlWorkItemRepositoryTest extends TestCase
{
    private const TABLE = 'cast_export_items';

    /**
     * The run id most of the contract tests operate on; every call is scoped
     * to this run so the shared-table contract is exercised explicitly.
     */
    private const RUN = 'run-1';

    private FakeWpDbGateway $gateway;

    private SqlWorkItemRepository $repository;

    private WorkItemFactory $factory;

    protected function setUp(): void
    {
        $this->gateway = new FakeWpDbGateway();
        $this->repository = new SqlWorkItemRepository($this->gateway, self::TABLE);
        $this->factory = new WorkItemFactory();
    }

    public function testInsertCreatesAQueuedItem(): void
    {
        $outcome = $this->repository->insertCanonical(self::RUN, $this->item('https://example.com/'));

        self::assertTrue($outcome->inserted());
        self::assertSame(1, $this->gateway->rowCount());
        self::assertSame(1, $this->repository->countByStatus(self::RUN, WorkItemStatus::Queued));
        self::assertSame(1, $this->repository->pendingCount(self::RUN));
        self::assertTrue($this->repository->hasPending(self::RUN));
    }

    /**
     * Regression test for a CI failure: wpdb::prepare() renders %i
     * as a backticked *identifier*, so a numeric value bound with %i (e.g.
     * priority 10) reached MariaDB as `10` and failed with Unknown column.
     * Every integer on the insert and claim paths must therefore be bound
     * with %d, which wpdb renders as a bare numeric literal. The recorded
     * statements are run through the wpdb-faithful prepare of
     * {@see FakeWpDb} so this proves the exact rendering, not just the
     * placeholder choice in isolation.
     */
    public function testPreparedStatementsRenderIntegersAsBareLiteralsNotBacktickedIdentifiers(): void
    {
        $now = 1_000;
        $repository = new SqlWorkItemRepository($this->gateway, self::TABLE, clock: static function () use (&$now): int {
            return $now;
        });
        $item = $this->item('https://example.com/');
        $repository->insertCanonical(self::RUN, $item, 10);
        $repository->claimNext(self::RUN, 'worker-a', 60);

        // Existence getVar, INSERT, claim peek getVar, claim UPDATE,
        // claimed-row hydration getRow.
        self::assertCount(5, $this->gateway->prepared);

        foreach ($this->gateway->prepared as [$sql, $params]) {
            $prepared = (new FakeWpDb())->prepare($sql, ...$params);

            self::assertDoesNotMatchRegularExpression(
                '/`\d+`/',
                $prepared,
                'a numeric value was rendered as a backticked identifier (wpdb %i); use %d: ' . $prepared,
            );
        }

        // Positive proof the bare literals are exactly what the insert and
        // claim carry: priority 10 on the insert, the 1060 lease expiry and
        // the 1000 "now" gate on the claim.
        $insert = (new FakeWpDb())->prepare($this->gateway->prepared[1][0], ...$this->gateway->prepared[1][1]);
        self::assertStringContainsString('VALUES (', $insert);
        self::assertStringContainsString('10', $insert, 'the insert carries priority 10 as a bare numeric literal');

        $claim = (new FakeWpDb())->prepare($this->gateway->prepared[3][0], ...$this->gateway->prepared[3][1]);
        self::assertStringContainsString('fetch_attempts = fetch_attempts + 1', $claim);
        self::assertStringContainsString('1060', $claim, 'the lease expiry is a bare numeric literal');
        self::assertStringContainsString('1000', $claim, 'the retry/lease gate is a bare numeric literal');
    }

    public function testDuplicateByUrlHashIsIgnored(): void
    {
        $this->repository->insertCanonical(self::RUN, $this->item('https://example.com/'));
        $second = $this->repository->insertCanonical(self::RUN, $this->item('https://example.com/'));

        self::assertTrue($second->duplicateIgnored());
        self::assertFalse($second->inserted());
        self::assertSame(1, $this->gateway->rowCount());
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

    public function testSameStatusTransitionIsStillTrue(): void
    {
        $item = $this->item('https://example.com/');
        $this->repository->insertCanonical(self::RUN, $item);

        // The first transition changes the stored status (one affected row).
        self::assertTrue($this->repository->transition(self::RUN, $item->urlHash(), WorkItemStatus::Processing));
        self::assertSame(1, $this->repository->countByStatus(self::RUN, WorkItemStatus::Processing));

        // Re-transitioning to the same status changes nothing in MariaDB (0
        // affected rows), but the item exists so the transition must still win.
        self::assertTrue($this->repository->transition(self::RUN, $item->urlHash(), WorkItemStatus::Processing));
        self::assertSame(1, $this->repository->countByStatus(self::RUN, WorkItemStatus::Processing));
    }

    public function testTransitionToDistinctStatusReturnsTrue(): void
    {
        $item = $this->item('https://example.com/about/');
        $this->repository->insertCanonical(self::RUN, $item);

        self::assertTrue($this->repository->transition(self::RUN, $item->urlHash(), WorkItemStatus::Done));

        self::assertSame(0, $this->repository->countByStatus(self::RUN, WorkItemStatus::Queued));
        self::assertSame(1, $this->repository->countByStatus(self::RUN, WorkItemStatus::Done));
    }

    public function testPendingCountReachesZeroAtFixedPoint(): void
    {
        $a = $this->item('https://example.com/a/');
        $b = $this->item('https://example.com/b/');
        $this->repository->insertCanonical(self::RUN, $a);
        $this->repository->insertCanonical(self::RUN, $b);

        self::assertSame(2, $this->repository->pendingCount(self::RUN));

        // A row in flight stays pending until it finishes.
        self::assertTrue($this->repository->transition(self::RUN, $a->urlHash(), WorkItemStatus::Processing));
        self::assertSame(2, $this->repository->pendingCount(self::RUN));

        self::assertTrue($this->repository->transition(self::RUN, $a->urlHash(), WorkItemStatus::Done));
        self::assertTrue($this->repository->transition(self::RUN, $b->urlHash(), WorkItemStatus::Done));

        self::assertSame(0, $this->repository->pendingCount(self::RUN));
        self::assertFalse($this->repository->hasPending(self::RUN));
    }

    public function testFailedItemsAreNotPending(): void
    {
        $item = $this->item('https://example.com/');
        $this->repository->insertCanonical(self::RUN, $item);
        $this->repository->transition(self::RUN, $item->urlHash(), WorkItemStatus::Failed);

        self::assertSame(0, $this->repository->pendingCount(self::RUN));
    }

    public function testRepositoryImplementsPersistenceAgnosticInterface(): void
    {
        self::assertInstanceOf(WorkItemRepository::class, $this->repository);
    }

    public function testClearDeletesEveryRowIncludingTerminalOnes(): void
    {
        // A completed prior run leaves done/rewritten rows behind; clear() must
        // remove them all so the next run's discovery can re-queue every URL
        // (insertCanonical is first-seen-wins and never re-queues finished rows).
        $this->repository->insertCanonical(self::RUN, $this->item('https://example.com/'));
        $this->repository->insertCanonical(self::RUN, $this->item('https://example.com/about/'));
        $this->repository->transition(self::RUN, md5('https://example.com/'), WorkItemStatus::Rewritten);
        $this->repository->transition(self::RUN, md5('https://example.com/about/'), WorkItemStatus::Done);
        self::assertSame(2, $this->gateway->rowCount());

        $this->repository->clear(self::RUN);

        self::assertSame(0, $this->gateway->rowCount());
        self::assertSame(0, $this->repository->countByStatus(self::RUN, WorkItemStatus::Queued));
        self::assertSame(0, $this->repository->countByStatus(self::RUN, WorkItemStatus::Rewritten));
        self::assertSame(0, $this->repository->countByStatus(self::RUN, WorkItemStatus::Done));
        self::assertSame(0, $this->repository->pendingCount(self::RUN));
    }

    public function testClaimNextReturnsNullWhenNothingIsQueued(): void
    {
        self::assertNull($this->repository->claimNext(self::RUN));
    }

    public function testClaimNextTakesTheLowestNumericPriorityFirst(): void
    {
        $low = $this->item('https://example.com/low/');
        $high = $this->item('https://example.com/high/');
        $this->repository->insertCanonical(self::RUN, $high, 10);
        $this->repository->insertCanonical(self::RUN, $low, 1);

        $claim = $this->repository->claimNext(self::RUN);

        self::assertNotNull($claim);
        self::assertSame($low->urlHash(), $claim->urlHash());
        self::assertSame($low->url()->__toString(), $claim->url()->__toString());
        self::assertSame($low->identity(), $claim->identity());
        self::assertSame($low->outputPath(), $claim->outputPath());
        self::assertSame($low->kind(), $claim->kind());
        self::assertSame(1, $this->repository->countByStatus(self::RUN, WorkItemStatus::Processing));
        self::assertSame(1, $this->repository->countByStatus(self::RUN, WorkItemStatus::Queued), 'exactly one row is claimed per call');
    }

    public function testClaimNextDoesNotReclaimAProcessingRow(): void
    {
        $item = $this->item('https://example.com/');
        $this->repository->insertCanonical(self::RUN, $item);
        $this->repository->transition(self::RUN, $item->urlHash(), WorkItemStatus::Processing);

        self::assertNull($this->repository->claimNext(self::RUN));
    }

    public function testAttemptCountGrowsAcrossRetries(): void
    {
        $item = $this->item('https://example.com/');
        $this->repository->insertCanonical(self::RUN, $item);

        self::assertSame(0, $this->repository->attemptCountOf(self::RUN, $item->urlHash()));

        self::assertNotNull($this->repository->claimNext(self::RUN));
        self::assertSame(1, $this->repository->attemptCountOf(self::RUN, $item->urlHash()));

        // A retry re-queues the row; only then may it be claimed again.
        self::assertTrue($this->repository->scheduleRetry(self::RUN, $item->urlHash(), 0));
        self::assertNotNull($this->repository->claimNext(self::RUN));
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

    public function testScheduleRetryIsIdempotentWithinTheSameSecond(): void
    {
        $now = 1_000;
        $repository = new SqlWorkItemRepository($this->gateway, self::TABLE, clock: static function () use (&$now): int {
            return $now;
        });
        $item = $this->item('https://example.com/');
        $repository->insertCanonical(self::RUN, $item);

        // Re-queuing the same row with the same delay twice within the same
        // second leaves status and retry_at unchanged (0 affected rows in
        // MariaDB), but the item exists so the retry must still report success.
        self::assertTrue($repository->scheduleRetry(self::RUN, $item->urlHash(), 5));
        self::assertTrue($repository->scheduleRetry(self::RUN, $item->urlHash(), 5));
        self::assertSame(1, $repository->countByStatus(self::RUN, WorkItemStatus::Queued));
    }

    public function testScheduledRetryIsNotClaimableUntilItsDelayPasses(): void
    {
        $now = 1_000;
        $repository = new SqlWorkItemRepository($this->gateway, self::TABLE, clock: static function () use (&$now): int {
            return $now;
        });
        $item = $this->item('https://example.com/');
        $repository->insertCanonical(self::RUN, $item);

        $repository->claimNext(self::RUN);
        self::assertSame(1, $repository->attemptCountOf(self::RUN, $item->urlHash()));
        self::assertTrue($repository->scheduleRetry(self::RUN, $item->urlHash(), 5));

        // Before the delay passes the row is never claimed again: the attempt
        // budget does not advance and the row stays queued-pending.
        $repository->claimNext(self::RUN);
        self::assertSame(1, $repository->attemptCountOf(self::RUN, $item->urlHash()), 'a not-yet-due retry must not be re-claimed');
        self::assertSame(1, $repository->pendingCount(self::RUN), 'a scheduled retry still counts as pending');
        self::assertSame(0, $repository->countByStatus(self::RUN, WorkItemStatus::Processing), 'a not-yet-due retry is not claimed');

        // Once the delay passes the same row is claimed again and attempts advance.
        $now = 1_005;
        $repository->claimNext(self::RUN);
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

    public function testSameUrlIsADistinctRowInAnotherRun(): void
    {
        $item = $this->item('https://example.com/');
        self::assertTrue($this->repository->insertCanonical(self::RUN, $item)->inserted());
        self::assertTrue($this->repository->insertCanonical('run-2', $item)->inserted(), 'the unique key is (run_id, url_hash), so the same URL in another run is a distinct row');
        self::assertSame(2, $this->gateway->rowCount());
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
        // Two workers over the SAME table: exactly one conditional claim can win.
        $otherWorker = new SqlWorkItemRepository($this->gateway, self::TABLE);

        self::assertNotNull($this->repository->claimNext(self::RUN, 'worker-a'));
        self::assertNull($otherWorker->claimNext(self::RUN, 'worker-b'), 'a live-leased row is never re-claimed by a second worker');
        self::assertSame(1, $this->repository->attemptCountOf(self::RUN, $item->urlHash()), 'the lost race never spends an attempt');
    }

    public function testLiveLeaseCannotBeStolen(): void
    {
        $now = 1_000;
        $repository = new SqlWorkItemRepository($this->gateway, self::TABLE, clock: static function () use (&$now): int {
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
        $row = $this->gateway->rowFor(self::RUN, $item->urlHash());
        self::assertNotNull($row);
        self::assertSame('worker-a', $row['worker_token'], 'the original lease owner is untouched');
    }

    public function testExpiredLeaseCanBeReclaimed(): void
    {
        $now = 1_000;
        $repository = new SqlWorkItemRepository($this->gateway, self::TABLE, clock: static function () use (&$now): int {
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
        $row = $this->gateway->rowFor(self::RUN, $item->urlHash());
        self::assertNotNull($row);
        self::assertSame('worker-b', $row['worker_token'], 'the reclaim hands the lease to the new worker');
    }

    public function testTerminalTransitionClearsLeaseOwnership(): void
    {
        $now = 1_000;
        $repository = new SqlWorkItemRepository($this->gateway, self::TABLE, clock: static function () use (&$now): int {
            return $now;
        });
        $item = $this->item('https://example.com/');
        $repository->insertCanonical(self::RUN, $item);
        $repository->claimNext(self::RUN, 'worker-a', 60);

        $repository->transition(self::RUN, $item->urlHash(), WorkItemStatus::Done);

        $row = $this->gateway->rowFor(self::RUN, $item->urlHash());
        self::assertNotNull($row);
        self::assertSame('', $row['worker_token'], 'the row no longer names a lease owner');
        self::assertSame(0, $row['lease_expires_at'], 'the lease window is cleared');

        // Long after the (cleared) lease window: the terminal row is not in
        // the reclaim pool.
        $now = 2_000;
        self::assertNull($repository->claimNext(self::RUN, 'worker-b', 60));
        self::assertSame(0, $repository->countByStatus(self::RUN, WorkItemStatus::Processing));
    }

    public function testScheduleRetryClearsLeaseOwnership(): void
    {
        $now = 1_000;
        $repository = new SqlWorkItemRepository($this->gateway, self::TABLE, clock: static function () use (&$now): int {
            return $now;
        });
        $item = $this->item('https://example.com/');
        $repository->insertCanonical(self::RUN, $item);
        $repository->claimNext(self::RUN, 'worker-a', 60);

        self::assertTrue($repository->scheduleRetry(self::RUN, $item->urlHash(), 0));

        $row = $this->gateway->rowFor(self::RUN, $item->urlHash());
        self::assertNotNull($row);
        self::assertSame('', $row['worker_token'], 'the row no longer names a lease owner');
        self::assertSame(0, $row['lease_expires_at'], 'the lease window is cleared');
        self::assertSame(1, $repository->countByStatus(self::RUN, WorkItemStatus::Queued));
    }

    private function item(string $url): WorkItem
    {
        return $this->factory->fromString($url);
    }
}
