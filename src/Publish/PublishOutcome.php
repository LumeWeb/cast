<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * The user-facing verdict of a publish run. Completed means live and recorded;
 * failed means nothing usable was produced (upload never succeeded); resumable
 * means a CID exists but a later step stalled — the caller may resume from the
 * carried state instead of starting over.
 */
enum PublishOutcome: string
{
    case Completed = 'completed';
    case Failed = 'failed';
    case Resumable = 'resumable';
}
