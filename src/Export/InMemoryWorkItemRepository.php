<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * In-memory WorkItemRepository used by unit tests and single-process runs.
 *
 * Implements the canonical-insert contract exactly so the SQL implementation
 * can be verified against it later:
 *  - rows are deduplicated by url_hash;
 *  - a completed (done) row is never replaced or re-prioritized — the first
 *    successful item wins forever;
 *  - a lower numeric priority may replace the stored priority of any other
 *    row, but the status is left untouched (processed state is not reset).
 */
final class InMemoryWorkItemRepository implements WorkItemRepository
{
    /**
     * @var \Closure(): int Returns the current Unix time (seconds).
     */
    private readonly \Closure $clock;

    /**
     * @var array<string, array{item: WorkItem, priority: int, status: WorkItemStatus, attempts: int, retryAt: int}>
     */
    private array $rows = [];

    public function __construct(?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function insertCanonical(WorkItem $workItem, int $priority = 10): InsertOutcome
    {
        $hash = $workItem->urlHash();

        if (!isset($this->rows[$hash])) {
            $this->rows[$hash] = [
                'item' => $workItem,
                'priority' => $priority,
                'status' => WorkItemStatus::Queued,
                'attempts' => 0,
                'retryAt' => 0,
            ];

            return InsertOutcome::created();
        }

        if ($this->rows[$hash]['status'] === WorkItemStatus::Done) {
            return InsertOutcome::duplicate();
        }

        if ($priority < $this->rows[$hash]['priority']) {
            $this->rows[$hash]['priority'] = $priority;

            return InsertOutcome::priorityReplaced();
        }

        return InsertOutcome::duplicate();
    }

    public function priorityOf(string $urlHash): ?int
    {
        return $this->rows[$urlHash]['priority'] ?? null;
    }

    public function countByStatus(WorkItemStatus $status): int
    {
        $count = 0;
        foreach ($this->rows as $row) {
            if ($row['status'] === $status) {
                ++$count;
            }
        }

        return $count;
    }

    public function pendingCount(): int
    {
        return $this->countByStatus(WorkItemStatus::Queued) + $this->countByStatus(WorkItemStatus::Processing);
    }

    public function hasPending(): bool
    {
        return $this->pendingCount() > 0;
    }

    public function transition(string $urlHash, WorkItemStatus $status): bool
    {
        if (!isset($this->rows[$urlHash])) {
            return false;
        }

        $this->rows[$urlHash]['status'] = $status;

        return true;
    }

    public function claimNext(): ?WorkItem
    {
        $now = ($this->clock)();

        $bestHash = null;
        $bestPriority = PHP_INT_MAX;
        foreach ($this->rows as $hash => $row) {
            if ($row['status'] !== WorkItemStatus::Queued || $row['retryAt'] > $now) {
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

        $claimed = $this->rows[$bestHash];
        $this->rows[$bestHash] = [
            'item' => $claimed['item'],
            'priority' => $claimed['priority'],
            'status' => WorkItemStatus::Processing,
            'attempts' => $claimed['attempts'] + 1,
            'retryAt' => $claimed['retryAt'],
        ];

        return $this->rows[$bestHash]['item'];
    }

    public function attemptCountOf(string $urlHash): int
    {
        return $this->rows[$urlHash]['attempts'] ?? 0;
    }

    public function scheduleRetry(string $urlHash, int $delaySeconds): bool
    {
        if (!isset($this->rows[$urlHash])) {
            return false;
        }

        $this->rows[$urlHash]['status'] = WorkItemStatus::Queued;
        $this->rows[$urlHash]['retryAt'] = ($this->clock)() + max(0, $delaySeconds);

        return true;
    }

    public function claimNextRewritable(): ?WorkItem
    {
        $bestHash = null;
        $bestPriority = PHP_INT_MAX;
        foreach ($this->rows as $hash => $row) {
            if ($row['status'] !== WorkItemStatus::Done || !$this->isRewritable($row['item'])) {
                continue;
            }
            if ($row['priority'] < $bestPriority) {
                $bestPriority = $row['priority'];
                $bestHash = $hash;
            }
        }

        return $bestHash === null ? null : $this->rows[$bestHash]['item'];
    }

    public function clear(): void
    {
        // A new run's discovery refills the queue; the prior run's terminal
        // rows must not survive (first-seen-wins would otherwise starve the
        // fresh per-run work directory with stale done/rewritten rows).
        $this->rows = [];
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
