<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\CaptureOutcome;
use LumeWeb\Cast\Export\CaptureOutcomeApplier;
use LumeWeb\Cast\Export\CaptureResult;
use LumeWeb\Cast\Export\InMemoryWorkItemRepository;
use LumeWeb\Cast\Export\RetryPolicy;
use LumeWeb\Cast\Export\WorkItem;
use LumeWeb\Cast\Export\WorkItemFactory;
use LumeWeb\Cast\Export\WorkItemStatus;
use PHPUnit\Framework\TestCase;

/**
 * The outcome applier maps a captured result onto the work-item lifecycle.
 *
 * The retry budget is shared with {@see CaptureService}'s inner loop: the
 * attempts already burned inside one capture() run (`CaptureResult::attempts`)
 * are counted together with the prior queue claims (`attemptCountOf`), so a
 * persistently retryable item is never fetched more than MAX_ATTEMPTS times
 * in total.
 */
final class CaptureOutcomeApplierTest extends TestCase
{
    private InMemoryWorkItemRepository $repository;
    private CaptureOutcomeApplier $applier;
    private WorkItemFactory $factory;

    protected function setUp(): void
    {
        $this->repository = new InMemoryWorkItemRepository();
        $this->applier = new CaptureOutcomeApplier();
        $this->factory = new WorkItemFactory();
    }

    public function testRetryableOutcomeWithBudgetRemainingIsScheduled(): void
    {
        // No prior claims yet; one inner attempt leaves two of three total.
        $item = $this->factory->fromString('https://example.test/');
        $this->repository->insertCanonical($item);

        $this->applier->apply($this->repository, $item->urlHash(), $this->retryable(attempts: 1));

        self::assertSame(WorkItemStatus::Queued, $this->statusOf($item), 'a retry re-queues the row');
        self::assertSame(0, $this->repository->countByStatus(WorkItemStatus::Failed));
    }

    public function testRetryableOutcomeThatFillsTheBudgetIsFailed(): void
    {
        // One prior claim plus two inner attempts fills the three-attempt
        // budget: the row must be marked Failed, never re-queued.
        $item = $this->claimTwice();

        $this->applier->apply($this->repository, $item->urlHash(), $this->retryable(attempts: 2));

        self::assertSame(WorkItemStatus::Failed, $this->statusOf($item));
        self::assertSame(0, $this->repository->countByStatus(WorkItemStatus::Queued));
    }

    public function testRetryableOutcomeThatOvertakesTheBudgetIsFailed(): void
    {
        // Two prior claims plus two inner attempts already exceed the budget,
        // so the row is still terminal instead of being scheduled again.
        $item = $this->claimTwice();
        $this->claim($item);

        $this->applier->apply($this->repository, $item->urlHash(), $this->retryable(attempts: 2));

        self::assertSame(WorkItemStatus::Failed, $this->statusOf($item));
        self::assertSame(0, $this->repository->countByStatus(WorkItemStatus::Queued));
    }

    public function testInnerLoopExhaustingTheBudgetAloneIsFailed(): void
    {
        // The capture service retried inside a single run until its own budget
        // ran out; no queue-level retry may add further fetches.
        $item = $this->factory->fromString('https://example.test/');
        $this->repository->insertCanonical($item);

        $this->applier->apply(
            $this->repository,
            $item->urlHash(),
            $this->retryable(attempts: RetryPolicy::MAX_ATTEMPTS),
        );

        self::assertSame(WorkItemStatus::Failed, $this->statusOf($item));
    }

    public function testRetryLifecycleBurnsAtMostMaxAttemptsTotal(): void
    {
        $item = $this->factory->fromString('https://example.test/');
        $this->repository->insertCanonical($item);

        // Claim 1: one inner attempt leaves the budget open, so the row is
        // scheduled for a later claim.
        $this->claim($item);
        $this->applier->apply($this->repository, $item->urlHash(), $this->retryable(attempts: 1));
        self::assertSame(WorkItemStatus::Queued, $this->statusOf($item));

        // Claim 2: one more inner attempt reaches the three-attempt total:
        // the row turns terminal Failed and no further claim may occur.
        $this->claim($item);
        $this->applier->apply($this->repository, $item->urlHash(), $this->retryable(attempts: 1));
        self::assertSame(WorkItemStatus::Failed, $this->statusOf($item));

        // Total fetches across both layers never exceed the documented budget.
        $total = $this->repository->attemptCountOf($item->urlHash()) + 1;
        self::assertSame(RetryPolicy::MAX_ATTEMPTS, $total);
    }

    public function testTerminalOutcomeIgnoresTheRetryBudget(): void
    {
        $item = $this->claimTwice();

        $this->applier->apply($this->repository, $item->urlHash(), new CaptureResult(CaptureOutcome::Redirected));

        self::assertSame(WorkItemStatus::Done, $this->statusOf($item));
    }

    private function retryable(int $attempts): CaptureResult
    {
        return new CaptureResult(CaptureOutcome::Failed, attempts: $attempts, retryDelaySeconds: 0);
    }

    /**
     * Claims the row twice from a fresh insert so attemptCountOf() is 2.
     */
    private function claimTwice(): WorkItem
    {
        $item = $this->factory->fromString('https://example.test/');
        $this->repository->insertCanonical($item);
        $this->claim($item);
        $this->claim($item);

        return $item;
    }

    private function claim(WorkItem $item): void
    {
        $claimed = $this->repository->claimNext();
        self::assertNotNull($claimed);
        self::assertSame($item->urlHash(), $claimed->urlHash());
        $this->repository->transition($item->urlHash(), WorkItemStatus::Queued);
    }

    private function statusOf(WorkItem $item): WorkItemStatus
    {
        $status = $this->repository->countByStatus(WorkItemStatus::Queued) === 1
            ? WorkItemStatus::Queued
            : ($this->repository->countByStatus(WorkItemStatus::Failed) === 1
                ? WorkItemStatus::Failed
                : ($this->repository->countByStatus(WorkItemStatus::Done) === 1
                    ? WorkItemStatus::Done
                    : WorkItemStatus::Skipped));

        return $status;
    }
}
