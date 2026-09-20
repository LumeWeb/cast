<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Fixed-point completion source for the pre-pack validation: whether any
 * work item is still queued/processing, and the terminal-stability counts by
 * status. Supplied as an interface so the validation stays free of persistence, SQL
 * and WordPress.
 */
interface WorkItemStateProvider
{
    /**
     * True while any item is queued or processing, i.e. the crawler has not
     * reached its fixed point.
     */
    public function hasPending(): bool;

    public function countByStatus(WorkItemStatus $status): int;
}
