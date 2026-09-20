<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

/**
 * DECOMMISSIONED — Cast no longer schedules through vanilla WP-Cron.
 * CastPlugin composes {@see WordPressActionScheduler} (Action Scheduler)
 * instead, so this adapter and its {@see CronGateway} port are unused by the
 * Cast composition; they are retained only as historically useful generic
 * code with their own unit tests and may be removed once the deletion is
 * performed. Nothing in Cast calls wp_schedule_single_event() /
 * wp_next_scheduled() / wp_clear_scheduled_hook() anymore.
 *
 * WordPress WP-Cron {@see Scheduler} adapter.
 *
 * Backs the pure scheduler adapter with {@see CronGateway} (wp_next_scheduled /
 * wp_schedule_single_event / wp_clear_scheduled_hook). Events are keyed by
 * (hook, args) exactly like core, so a debounce can ask "already queued?"
 * without stacking duplicates. The exact timestamp handed in is preserved —
 * dedupe happens upstream of the insert, never by silently moving the event.
 */
final class WordPressScheduler implements Scheduler
{
    public function __construct(private readonly CronGateway $cron)
    {
    }

    public function isScheduled(string $hook, array $args = []): bool
    {
        return $this->cron->nextScheduled($hook, $args) !== false;
    }

    public function scheduleSingle(string $hook, int $at, array $args = []): bool
    {
        if ($this->isScheduled($hook, $args)) {
            return false;
        }

        return $this->cron->scheduleSingleEvent($at, $hook, $args);
    }

    public function cancelSingle(string $hook, array $args = []): void
    {
        $this->cron->clearScheduledHook($hook, $args);
    }
}
