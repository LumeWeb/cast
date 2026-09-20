<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export\Rewrite;

use LumeWeb\Cast\Export\WorkItem;
use LumeWeb\Cast\Export\WorkItemKind;
use LumeWeb\Cast\Export\WorkItemRepository;

/**
 * Binds the rewrite QueueCollector to the canonical WorkItemRepository. Every
 * in-origin, non-page URL a rewriter resolves is inserted through
 * insertCanonical() so the collected asset/media is included in the export;
 * duplicates collapse to one row. The stage constructs the collector with the
 * urgent rewrite-derived priority (1) so the reconciliation pass claims them
 * first; the default remains 10 for plain discovery.
 *
 * A validity check and two deliberate collection checks live here:
 *  - Wildcard/glob pseudo-URLs (a literal `*` in path or query) are dropped:
 *    a pattern never names one real resource, so it must not be enqueued.
 *  - Page-kind URLs are dropped outright: the discover/DB queue is the ONLY
 *  - Page-kind URLs are dropped outright: the discover/DB queue is the ONLY
 *    authority for page URLs, so a link surfaced inside content is never
 *    enqueued, fetched or crawled.
 *  - The optional cap bounds the cumulative number of distinct assets/media
 *    collected (a durable count the stage carries in its cursor). Once the cap
 *    is exhausted a genuinely new row is refused and capHit() reports it, so
 *    the stage can surface a warning instead of silently losing references.
 */
final class WorkItemQueueCollector implements QueueCollector
{
    /**
     * Sentinel: no cap on how many asset/media candidates may be collected.
     */
    public const UNLIMITED = PHP_INT_MAX;

    public function __construct(
        private readonly WorkItemRepository $repository,
        private readonly int $priority = 10,
        private readonly int $maxAssets = self::UNLIMITED,
        private readonly int $alreadyCollected = 0,
    ) {
    }

    /**
     * How many brand-new asset/media rows this collector created; the stage
     * adds it to the cursor's carried count to advance the cumulative cap.
     */
    private int $collected = 0;

    /**
     * Whether the cumulative cap forced this collector to refuse an insert.
     */
    private bool $capHit = false;

    public function queue(WorkItem $workItem): void
    {
        // Wildcard/glob pseudo-URLs (`/wp-*.php`, `/wp-admin/*`) never name one
        // real resource: a rewriter can surface a glob inside a stylesheet or
        // script where the page-kind check below does not apply, so it is
        // dropped here too. Every other entry point refuses a literal `*`
        // already (UrlCandidateNormalizer / ExclusionPolicy); this is this
        // collector's own guard, and the percent-encoded `%2A` stays allowed.
        if ($this->isWildcardReference($workItem)) {
            return;
        }

        // Page URLs surfaced inside content are never re-crawled: the discover/
        // DB queue is the only authority for pages, so only asset/media
        // candidates (everything non-page) are collected into the export.
        if ($workItem->kind() === WorkItemKind::Page) {
            return;
        }

        if ($this->alreadyCollected + $this->collected >= $this->maxAssets) {
            // Cap exhausted: refuse only a genuinely new distinct asset. A
            // duplicate reference to an already-known row is not a new
            // collection and must neither consume the cap nor raise the
            // warning; first-seen-wins already guarantees it is queued.
            if ($this->repository->priorityOf($workItem->urlHash()) === null) {
                $this->capHit = true;
            }

            return;
        }

        if ($this->repository->insertCanonical($workItem, $this->priority)->inserted()) {
            ++$this->collected;
        }
    }

    public function collectedCount(): int
    {
        return $this->collected;
    }

    public function capHit(): bool
    {
        return $this->capHit;
    }

    /**
     * Whether the collected reference is a glob/pattern pseudo-URL. Only a
     * literal `*` in the canonical path or query counts; the percent-encoded
     * `%2A` is a legitimate escape and stays collectable.
     */
    private function isWildcardReference(WorkItem $workItem): bool
    {
        return str_contains($workItem->url()->path(), '*')
            || str_contains($workItem->url()->query(), '*');
    }
}
