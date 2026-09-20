<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * The canonical work-item queue, persistence-agnostic.
 *
 * Every discovery route funnels through insertCanonical() so one deduplicated
 * queue feeds capture. The state/count surface here is deliberately the
 * minimum the fixed-point crawler needs to terminate: priority updates without
 * disturbing processed rows, a completed-first-successful guarantee, and
 * pending counts that reach zero only when nothing is queued or in flight. A
 * SQL-backed implementation is added later without changing any caller.
 */
interface WorkItemRepository
{
    public function insertCanonical(WorkItem $workItem, int $priority = 10): InsertOutcome;

    /**
     * Null when no row with this url hash exists.
     */
    public function priorityOf(string $urlHash): ?int;

    public function countByStatus(WorkItemStatus $status): int;

    /**
     * Rows that keep the crawler loop alive: queued + processing.
     */
    public function pendingCount(): int;

    public function hasPending(): bool;

    /**
     * Move a row between statuses. Returns false when the hash is unknown.
     */
    public function transition(string $urlHash, WorkItemStatus $status): bool;

    /**
     * Atomically claim exactly one queued row for processing, lowest numeric
     * priority first. The claim is exclusive — a processing row is never
     * re-claimed — and increments the row's attempt count (fetch_attempts).
     * Returns null when no queued row is claimable yet, including scheduled
     * retries whose delay has not passed.
     */
    public function claimNext(): ?WorkItem;

    /**
     * How many times a row has been claimed so far; zero for an unknown hash
     * or a row that was never claimed. Drives the bounded retry budget.
     */
    public function attemptCountOf(string $urlHash): int;

    /**
     * Re-queue a row for a later attempt after the given delay in seconds;
     * the row becomes claimable again once that delay passes. Returns false
     * when the hash is unknown.
     */
    public function scheduleRetry(string $urlHash, int $delaySeconds): bool;

    /**
     * Claim the next completed, still-to-be-rewritten item for the rewrite
     * stage, lowest numeric priority first. Only rewritable text-like rows
     * count: pages, redirects, text files and text-capable assets
     * (css/js/mjs/json/xml/rss/atom); binary/fixed assets are passed through
     * and never claimed. The claim is a non-destructive peek — the row keeps
     * its Done status until the stage transitions it to Rewritten on success —
     * so a restart naturally resumes from the unrewritten rows. Returns null
     * when no such row remains.
     */
    public function claimNextRewritable(): ?WorkItem;

    /**
     * Remove every row from the queue. Called exactly when a brand-new run is
     * created so the fresh per-run work directory starts from a clean queue:
     * the next discovery refills it, and the prior run's terminal rows
     * (`done`/`rewritten`) must not survive because insertCanonical is
     * first-seen-wins and never re-queues a finished row. Never called on a
     * routine tick or status read.
     */
    public function clear(): void;
}
