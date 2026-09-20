<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * A bounded, resumable cursor value: bounds each page by a fixed limit, tells
 * whether more work exists from the size of the last batch alone, and
 * serialises to a deterministic resume token so a paused worker can continue
 * from exactly where it stopped.
 */
interface ResumeCursor
{
    public function limit(): int;

    /**
     * Whether another page may exist, given how many rows the previous page
     * produced. A page as large as the limit may have more; a short page is
     * definitively drained.
     */
    public function hasMore(int $batchSize): bool;

    public function toToken(): string;
}
