<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export\Rewrite;

use LumeWeb\Cast\Export\Rewrite\QueueCollector;
use LumeWeb\Cast\Export\WorkItem;

/**
 * QueueCollector test double that records every queued WorkItem so rewriter
 * tests can assert what was discovered (output path, kind, dedupe).
 */
final class CapturingQueueCollector implements QueueCollector, \Countable
{
    /** @var list<WorkItem> */
    private array $items = [];

    public function queue(WorkItem $workItem): void
    {
        $this->items[] = $workItem;
    }

    /**
     * @return list<WorkItem>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * @return list<string>
     */
    public function outputPaths(): array
    {
        return array_map(static fn (WorkItem $item): string => $item->outputPath(), $this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }
}
