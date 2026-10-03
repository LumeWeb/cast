<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

/**
 * Tuning knobs for one export tick: which lease key to hold, for how long,
 * whether a stale (expired) lease may be reclaimed, and how much bounded
 * pipeline work one tick may execute. A tick batches consecutive units
 * under two independent caps: a wall-clock TIME BUDGET (the primary
 * governor, measured with the injected {@see Clock}) and an item HARD CAP
 * on the number of units. Reclaiming is the default because a crashed
 * worker must not strand a run behind an expired lease — the next tick
 * simply reclaims it and continues. That reclamation is safe because lease
 * acquisition verifies ownership (see {@see WordPressLock}) before a stale
 * lease is replaced. Holding this in a single value keeps the runner's
 * constructor and tests readable.
 */
final class TickConfig
{
    /**
     * Wall-clock time budget for one tick, in seconds: after a unit finishes,
     * the runner starts no further unit once the elapsed time has reached
     * this. 30s is exactly half the {@see self::$lockTtlSeconds} default of
     * 60s, so a single tick can never hold the lease for longer than half
     * its TTL — even in the worst case where every unit is a slow 30s HTTP
     * capture: one such unit fills the budget and the batch stops, keeping
     * the tick ~30s inside the 60s lease. Fast units, by contrast, batch
     * many per tick until either cap is hit.
     */
    public const DEFAULT_TIME_BUDGET_SECONDS = 30;

    /**
     * Hard cap on how many pipeline units one tick may execute, independent
     * of how fast they are. Backstop for the time budget: it bounds the
     * number of persisted mutations and the number of pipeline stage invocations
     * inside one WP-Cron HTTP request, so a burst of pathologically cheap
     * units cannot stretch a request unboundedly. Together with the 30s
     * time budget it keeps one tick comfortably inside the 60s lock TTL.
     */
    public const DEFAULT_UNITS_PER_TICK = 20;

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
        public readonly int $unitsPerTick = self::DEFAULT_UNITS_PER_TICK,
        public readonly int $timeBudgetSeconds = self::DEFAULT_TIME_BUDGET_SECONDS,
    ) {
    }
}
