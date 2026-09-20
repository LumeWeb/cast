<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

/**
 * Tuning knobs for one export tick: which lease key to hold, for how long, and
 * whether a stale (expired) lease may be reclaimed. Reclaiming is the default
 * because a crashed worker must not strand a run behind an expired lease — the
 * next tick simply reclaims it and continues. That reclamation is safe because
 * lease acquisition verifies ownership (see {@see WordPressLock}) before a
 * stale lease is replaced. Holding this in a single value keeps the runner's
 * constructor and tests readable.
 */
final class TickConfig
{
    /**
     * The host Cron delivery cadence for an armed tick: how often the site's
     * wp-cron runner can actually fire a scheduled AUTO_HOOK event. A queued
     * run whose tick is already armed begins within one such interval, so the
     * publish dashboard's queued ETA ("Starting within N seconds") derives
     * from this exact value — and stays truthful automatically if the
     * deployment cadence ever changes.
     */
    public const DEFAULT_TICK_INTERVAL_SECONDS = 30;

    public function __construct(
        public readonly string $lockKey = 'cast:export-tick',
        public readonly int $lockTtlSeconds = 60,
        public readonly bool $reclaimStaleLocks = true,
    ) {
    }
}
