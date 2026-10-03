<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * The canonical work-item queue, persistence-agnostic. Every operation is
 * explicitly scoped to the export run that owns the work: rows of one run are
 * invisible to every other run (inserts, reads, claims and clears never cross
 * run boundaries).
 *
 * Every discovery route funnels through insertCanonical() so one deduplicated
 * per-run queue feeds capture. The state/count surface here is deliberately
 * the minimum the fixed-point crawler needs to terminate: priority updates
 * without disturbing processed rows, a completed-first-successful guarantee,
 * and pending counts that reach zero only when nothing is queued or in flight.
 *
 * Claims are lease-based: a claim names the claiming worker and a lease
 * expiry, so a row whose worker died (crashed tick, killed request) becomes
 * reclaimable once its lease lapses; a live lease is never stolen.
 */
interface WorkItemRepository
{
    /** Default worker token for the single-run tick pipeline. */
    public const DEFAULT_WORKER = 'cast-tick';

    /**
     * The default claim lease in seconds. Sized to cover the slowest single
     * capture unit, so a crashed worker's in-flight row is reclaimed by a
     * later tick.
     */
    public const DEFAULT_LEASE_SECONDS = 300;

    public function insertCanonical(string $runId, WorkItem $workItem, int $priority = 10): InsertOutcome;

    /**
     * Null when no row with this url hash exists in the given run.
     */
    public function priorityOf(string $runId, string $urlHash): ?int;

    public function countByStatus(string $runId, WorkItemStatus $status): int;

    /**
     * Rows that keep the crawler loop alive: queued + processing.
     */
    public function pendingCount(string $runId): int;

    public function hasPending(string $runId): bool;

    /**
     * Move a row between statuses within the given run. Returns false when
     * the hash is unknown to that run. Any transition clears the row's lease
     * ownership (worker token and expiry), so a finished or re-queued row can
     * never be mistaken for in-flight work of a dead worker.
     */
    public function transition(string $runId, string $urlHash, WorkItemStatus $status): bool;

    /**
     * Atomically claim exactly one queued row of the given run for processing,
     * lowest numeric priority first. The claim is a lease: the row records
     * the claiming worker token and a lease expiry (now + lease seconds), so
     * a crashed worker's in-flight row is reclaimable once the lease lapses
     * and a live-leased row is never stolen. The claim is exclusive and
     * increments the row's attempt count (fetch_attempts). Returns null when
     * no row of the run is claimable yet, including scheduled retries whose
     * delay has not passed.
     */
    public function claimNext(string $runId, string $worker = self::DEFAULT_WORKER, int $leaseSeconds = self::DEFAULT_LEASE_SECONDS): ?WorkItem;

    /**
     * How many times a row of the given run has been claimed so far; zero for
     * an unknown hash or a row that was never claimed. Drives the bounded
     * retry budget.
     */
    public function attemptCountOf(string $runId, string $urlHash): int;

    /**
     * Re-queue a row of the given run for a later attempt after the given
     * delay in seconds; the row becomes claimable again once that delay
     * passes and its lease ownership is cleared. Returns false when the hash
     * is unknown to the run.
     */
    public function scheduleRetry(string $runId, string $urlHash, int $delaySeconds): bool;

    /**
     * Claim the next completed, still-to-be-rewritten item of the given run
     * for the rewrite stage, lowest numeric priority first. Only rewritable
     * text-like rows count: pages, redirects, text files and text-capable
     * assets (css/js/mjs/json/xml/rss/atom); binary/fixed assets are passed
     * through and never claimed. The claim is a non-destructive peek, and the
     * row keeps its Done status until the stage transitions it to Rewritten
     * on success, so a restart naturally resumes from the unrewritten rows.
     * Returns null when no such row remains in the run.
     */
    public function claimNextRewritable(string $runId): ?WorkItem;

    /**
     * Remove every row of the given run from the queue. Called exactly when a
     * brand-new run is created so the fresh per-run work directory starts
     * from a clean queue: the next discovery refills it, and the prior run's
     * terminal rows (`done`/`rewritten`) must not survive because
     * insertCanonical is first-seen-wins and never re-queues a finished row.
     * Other runs' rows are never touched. Never called on a routine tick or
     * status read.
     */
    public function clear(string $runId): void;
}
