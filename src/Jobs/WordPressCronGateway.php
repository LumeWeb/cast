<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

/**
 * DECOMMISSIONED — Cast no longer schedules through vanilla WP-Cron.
 * CastPlugin composes {@see WordPressActionScheduler} (Action Scheduler)
 * instead, so this adapter is unused by the Cast composition and retained only
 * as historically useful generic code under test; it may be removed once the
 * deletion is performed.
 *
 * WordPress WP-Cron gateway: delegates straight to the core single-event
 * functions. The (hook, args) identity and timestamp semantics match core
 * exactly, so the pure {@see Scheduler} contract carries over unchanged.
 */
final class WordPressCronGateway implements CronGateway
{
    public function nextScheduled(string $hook, array $args = []): int|false
    {
        return wp_next_scheduled($hook, $args);
    }

    public function scheduleSingleEvent(int $timestamp, string $hook, array $args = []): bool
    {
        return (bool) wp_schedule_single_event($timestamp, $hook, $args);
    }

    public function clearScheduledHook(string $hook, array $args = []): int
    {
        return (int) wp_clear_scheduled_hook($hook, $args);
    }
}
