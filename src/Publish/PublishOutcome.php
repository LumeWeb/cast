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
    /** A custom-domain first publish that created its website and now waits
     * for the domain's DNS to connect: an explicit, deliberate waiting state
     * (CID/IPNS/website preserved) — never a failure. */
    case AwaitingDns = 'awaiting_dns';
}
