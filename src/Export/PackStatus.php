<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Outcome of a pack run or of the pre-pack validation, using the design's
 * vocabulary: a clean artifact is 'completed', warning findings (leftover
 * origins, broken references) yield 'completed_with_warnings', and a hard
 * integrity violation (pending items, traversal, duplicate paths, missing
 * ghost-free root index) is 'failed'.
 */
enum PackStatus: string
{
    case Completed = 'completed';
    case CompletedWithWarnings = 'completed_with_warnings';
    case Failed = 'failed';
}
