<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

/**
 * What a content-publish or follow-up scheduling call decided, so the WP-Cron
 * hook handler can log without re-reading state.
 */
enum PublishScheduleState: string
{
    /** Manual mode: content drift was recorded but nothing was scheduled, superseded or followed up. */
    case Held = 'held';

    /** Content was folded into a pending run but nothing was scheduled (no identity yet). */
    case Deferred = 'deferred';

    /** A pending run exists and one debounce event is (now) scheduled. */
    case Scheduled = 'scheduled';

    /** The pending run already has a debounce event; nothing new was scheduled. */
    case Folded = 'folded';

    /** An active run was superseded and one follow-up after terminal is armed. */
    case Superseded = 'superseded';

    /** The run is still live; the follow-up re-armed itself for later. */
    case Waiting = 'waiting';
}
