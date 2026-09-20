<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

/**
 * Deduplicated scheduling of the artifact retention sweep.
 *
 * Both arming routes — arm-after-a-terminal-run and the periodic re-arm —
 * schedule the SAME single event on the SAME {@see Scheduler} (keyed by hook +
 * args exactly like WP-Cron), so a sweep already pending is never duplicated
 * into a storm. A sweep running on a quiet site re-arms its own next periodic
 * event through {@see RetentionRunner}, which is what ages artifacts out even
 * when no new export finishes.
 */
final class RetentionScheduler
{
    public const RETENTION_HOOK = 'cast/export/retention';

    /**
     * How soon after a terminal run the GC sweep is armed — long enough for
     * the run's bookkeeping to settle, short enough that retention follows a
     * finished export promptly.
     */
    public const DEFAULT_ARM_DELAY_SECONDS = 60;

    /**
     * The periodic cadence: once a day the sweep re-runs so old artifacts are
     * collected even on a site that stops exporting. Re-arming through the
     * deduplicated scheduler means a daily event never stacks.
     */
    public const DEFAULT_PERIODIC_SECONDS = 86400;

    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly Clock $clock,
        private readonly int $armDelaySeconds = self::DEFAULT_ARM_DELAY_SECONDS,
        private readonly int $periodicSeconds = self::DEFAULT_PERIODIC_SECONDS,
    ) {
    }

    /**
     * Arm one retention sweep shortly after a run reached a terminal status.
     * Returns false when a sweep is already queued (the scheduler dedupes).
     */
    public function armAfterTerminal(?int $at = null): bool
    {
        return $this->arm($this->armDelaySeconds, $at);
    }

    /**
     * Arm the next periodic retention sweep. Returns false when one is already
     * queued.
     */
    public function armPeriodic(?int $at = null): bool
    {
        return $this->arm($this->periodicSeconds, $at);
    }

    public function isArmed(): bool
    {
        return $this->scheduler->isScheduled(self::RETENTION_HOOK);
    }

    private function arm(int $delaySeconds, ?int $at): bool
    {
        $now = $at ?? $this->clock->now();

        return $this->scheduler->scheduleSingle(self::RETENTION_HOOK, $now + $delaySeconds);
    }
}
