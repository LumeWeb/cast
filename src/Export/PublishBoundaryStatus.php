<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * The persisted verdict of the publish pipeline boundary.
 *
 * Mirrors the {@see \LumeWeb\Cast\Publish\PublishOutcome} the
 * {@see \LumeWeb\Cast\Publish\PublishService} reports: Completed means the
 * archive is live and the website/IPNS identity recorded; Failed means nothing
 * usable was produced (the upload never succeeded); Resumable means a CID
 * exists but a later website/IPNS step stalled — the durable PublishRegistry
 * lets a retried publish resume exactly where it stopped.
 */
enum PublishBoundaryStatus: string
{
    case Completed = 'completed';
    case Failed = 'failed';
    case Resumable = 'resumable';
    /** A custom-domain first publish that created its website and now waits
     * for the domain's DNS to connect: an explicit, deliberate waiting state
     * (CID/website/IPNS preserved) — never a failure, and resumed through the
     * DNS-verification path without re-uploading. */
    case AwaitingDns = 'awaiting_dns';
}
