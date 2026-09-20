<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export\Rewrite;

use LumeWeb\Cast\Export\WorkItem;

/**
 * Receives URLs discovered while rewriting a document body. The rewrite pass
 * runs inline during capture; the collector feeds the same canonical
 * WorkItemRepository so the crawler reaches its fixed point.
 */
interface QueueCollector
{
    public function queue(WorkItem $workItem): void;
}
