<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Lifecycle of a canonical work item row, mirroring the status column.
 * Pending work (queued + processing) drives the fixed-point termination check;
 * failed rows are retried in a later capture pass. Done rows that the rewrite
 * stage has rewritten move to Rewritten (binary/fixed rows keep Done).
 */
enum WorkItemStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Done = 'done';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Rewritten = 'rewritten';
}
