<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * In-memory WorkItemRepository used by unit tests and single-process runs.
 *
 * Implements the canonical-insert contract exactly so the SQL implementation
 * can be verified against it later:
 *  - rows are scoped to their export run and deduplicated by (run, url_hash);
 *  - a completed (done) row is never replaced or re-prioritized — the first
 *    successful item wins forever;
 *  - a lower numeric priority may replace the stored priority of any other
 *    row, but the status is left untouched (processed state is not reset);
 *  - claims are lease-based: the claiming worker token and a lease expiry are
 *    recorded, a live lease is never stolen, and an expired lease makes the
 *    in-flight row reclaimable; terminal/retry transitions clear ownership.
 */
final class InMemoryWorkItemRepository implements WorkItemRepository
{
    /**
     * @var \Closure(): int Returns the current Unix time (seconds).
     */
    private readonly \Closure $clock;

    /**
     * Rows keyed by run id, then url hash.
     *
     * @var array<string, array<string, array{item: WorkItem, priority: int, status: WorkItemStatus, attempts: int, retryAt: int, worker: string, leaseExpiresAt: int}>>
     */
    private array $rows = [];

    public function __construct(?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function insertCanonical(string $runId, WorkItem $workItem, int $priority = 10): InsertOutcome
    {
        $hash = $workItem->urlHash();

        if (!isset($this->rows[$runId][$hash])) {
            $this->rows[$runId][$hash] = [
                'item' => $workItem,
                'priority' => $priority,
                'status' => WorkItemStatus::Queued,
                'attempts' => 0,
                'retryAt' => 0,
                'worker' => '',
                'leaseExpiresAt' => 0,
            ];

            return InsertOutcome::created();
        }

        if ($this->rows[$runId][$hash]['status'] === WorkItemStatus::Done) {
            return InsertOutcome::duplicate();
        }

        if ($priority < $this->rows[$runId][$hash]['priority']) {
            $this->rows[$runId][$hash]['priority'] = $priority;

            return InsertOutcome::priorityReplaced();
        }

        return InsertOutcome::duplicate();
    }

    public function priorityOf(string $runId, string $urlHash): ?int
    {
        return $this->rows[$runId][$urlHash]['priority'] ?? null;
    }

    public function countByStatus(string $runId, WorkItemStatus $status): int
    {
        $count = 0;
        foreach ($this->rows[$runId] ?? [] as $row) {
            if ($row['status'] === $status) {
                ++$count;
            }
        }

        return $count;
    }

    public function pendingCount(string $runId): int
    {
        return $this->countByStatus($runId, WorkItemStatus::Queued) + $this->countByStatus($runId, WorkItemStatus::Processing);
    }

    public function hasPending(string $runId): bool
    {
        return $this->pendingCount($runId) > 0;
    }

    public function transition(string $runId, string $urlHash, WorkItemStatus $status): bool
    {
        if (!isset($this->rows[$runId][$urlHash])) {
            return false;
        }

        // A transition ends whatever lease the row carried: a finished or
        // re-queued row must never read as in-flight work of a dead worker.
        $this->rows[$runId][$urlHash]['status'] = $status;
        $this->rows[$runId][$urlHash]['worker'] = '';
        $this->rows[$runId][$urlHash]['leaseExpiresAt'] = 0;

        return true;
    }

    public function claimNext(string $runId, string $worker = WorkItemRepository::DEFAULT_WORKER, int $leaseSeconds = WorkItemRepository::DEFAULT_LEASE_SECONDS): ?WorkItem
    {
        $now = ($this->clock)();

        $bestHash = null;
        $bestPriority = PHP_INT_MAX;
        foreach ($this->rows[$runId] ?? [] as $hash => $row) {
            if (!$this->isClaimable($row, $now)) {
                continue;
            }
            if ($row['priority'] < $bestPriority) {
                $bestPriority = $row['priority'];
                $bestHash = $hash;
            }
        }

        if ($bestHash === null) {
            return null;
        }

        $this->rows[$runId][$bestHash] = [
            'item' => $this->rows[$runId][$bestHash]['item'],
            'priority' => $this->rows[$runId][$bestHash]['priority'],
            'status' => WorkItemStatus::Processing,
            'attempts' => $this->rows[$runId][$bestHash]['attempts'] + 1,
            'retryAt' => $this->rows[$runId][$bestHash]['retryAt'],
            'worker' => $worker,
            'leaseExpiresAt' => $now + max(0, $leaseSeconds),
        ];

        return $this->rows[$runId][$bestHash]['item'];
    }

    public function attemptCountOf(string $runId, string $urlHash): int
    {
        return $this->rows[$runId][$urlHash]['attempts'] ?? 0;
    }

    public function scheduleRetry(string $runId, string $urlHash, int $delaySeconds): bool
    {
        if (!isset($this->rows[$runId][$urlHash])) {
            return false;
        }

        $this->rows[$runId][$urlHash]['status'] = WorkItemStatus::Queued;
        $this->rows[$runId][$urlHash]['retryAt'] = ($this->clock)() + max(0, $delaySeconds);
        $this->rows[$runId][$urlHash]['worker'] = '';
        $this->rows[$runId][$urlHash]['leaseExpiresAt'] = 0;

        return true;
    }

    public function claimNextRewritable(string $runId): ?WorkItem
    {
        $bestHash = null;
        $bestPriority = PHP_INT_MAX;
        foreach ($this->rows[$runId] ?? [] as $hash => $row) {
            if ($row['status'] !== WorkItemStatus::Done || !$this->isRewritable($row['item'])) {
                continue;
            }
            if ($row['priority'] < $bestPriority) {
                $bestPriority = $row['priority'];
                $bestHash = $hash;
            }
        }

        return $bestHash === null ? null : $this->rows[$runId][$bestHash]['item'];
    }

    public function clear(string $runId): void
    {
        // The cleared run's terminal rows must not survive: first-seen-wins
        // would starve the fresh per-run work directory with stale rows.
        $this->rows[$runId] = [];
    }

    public function purgeTerminalRuns(array $runIds): void
    {
        // Only the listed runs' rows are removed; every other run's slice is
        // left exactly as it is, and an empty list changes nothing.
        foreach ($runIds as $runId) {
            $this->rows[$runId] = [];
        }
    }

    /**
     * In-flight rows are claimable only on an expired lease; a never-leased
     * row (lease expiry 0) was transitioned without a claim and stays
     * unclaimable.
     *
     * @param array{status: WorkItemStatus, retryAt: int, worker: string, leaseExpiresAt: int} $row
     */
    private function isClaimable(array $row, int $now): bool
    {
        if ($row['status'] === WorkItemStatus::Queued) {
            return $row['retryAt'] <= $now;
        }

        return $row['status'] === WorkItemStatus::Processing
            && $row['leaseExpiresAt'] > 0
            && $row['leaseExpiresAt'] <= $now;
    }

    /**
     * A captured row is ready for the rewrite pass when its body is text-like:
     * pages, redirects and plain text are always rewriters, and an asset only
     * when its extension is one of the text-capable kinds. Binary/fixed assets
     * (png, woff2, ...) pass through untouched and are never claimed. Kept in
     * step with {@see \LumeWeb\Cast\Export\Rewrite\RewriteService}.
     */
    private function isRewritable(WorkItem $item): bool
    {
        return match ($item->kind()) {
            WorkItemKind::Page, WorkItemKind::Redirect, WorkItemKind::Text => true,
            WorkItemKind::Asset => $this->isTextAsset($item),
        };
    }

    private function isTextAsset(WorkItem $item): bool
    {
        $extension = strtolower(pathinfo($item->outputPath(), PATHINFO_EXTENSION));

        return in_array($extension, ['css', 'js', 'mjs', 'json', 'xml', 'rss', 'atom'], true);
    }
}
