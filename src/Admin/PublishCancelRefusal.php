<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

/**
 * Why an explicit publish cancel was refused, as a typed, JSON-safe value.
 *
 * Each case names a single precondition that failed. The setup service
 * refuses before it ever mutates a run or clears a scheduled event, so a
 * refusal is always side-effect free — the admin UI can render the refusal
 * code directly.
 */
enum PublishCancelRefusal: string
{
    /** No run record exists to cancel. */
    case NoRun = 'no_run';

    /** The latest run already finished; nothing live remains to stop. */
    case RunTerminal = 'run_terminal';
}
