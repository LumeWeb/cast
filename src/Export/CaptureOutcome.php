<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * What capture decided for one work item, before any persistence is touched.
 *
 * Retryable outcomes (200/empty-body and transport/5xx failures) are revisited
 * by the bounded retry loop; everything else is a terminal decision for this
 * item and is handed back to the crawler exactly once.
 */
enum CaptureOutcome: string
{
    case Copied = 'copied';
    case Fetched = 'fetched';
    case Redirected = 'redirected';
    case CanonicalTwin = 'canonical_twin';
    case OffOrigin = 'off_origin';
    case NotFound = 'not_found';
    case Forbidden = 'forbidden';
    case Empty = 'empty';
    case Ghost = 'ghost';
    case Failed = 'failed';

    public function isRetryable(): bool
    {
        return $this === self::Empty || $this === self::Failed;
    }
}
