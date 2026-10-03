<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Fixed-point completion source for the pre-pack validation: whether any
 * work item is still queued/processing, and the terminal-stability counts by
 * status. Supplied as an interface so the validation stays free of persistence, SQL
 * and WordPress.
 *
 * Reads are scoped to a run: callers name it explicitly, and an empty scope
 * falls back to the provider's own run resolution.
 */
interface WorkItemStateProvider
{
    /**
     * True while any item of the given run is queued or processing, i.e. the
     * crawler has not reached its fixed point.
     */
    public function hasPending(string $runId = ''): bool;

    /** The terminal-stability count of the given run's rows in the given status. */
    public function countByStatus(string $runId, WorkItemStatus $status): int;
}
