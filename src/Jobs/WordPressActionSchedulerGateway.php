<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

/**
 * The guarded integration boundary to the runner-provided Action Scheduler
 * runtime.
 *
 * Action Scheduler ships as a plugin/library that is loaded by WordPress at
 * runtime, not as a packages Cast can always depend on at build time, so the
 * as_* functions may be entirely absent when this class runs (notably in the
 * unit bootstrap). Every method therefore guards on {@see isAvailable()} and,
 * when unavailable, degrades to the exact boundary Action Scheduler itself
 * documents for an uninitialized runtime: as_schedule_single_action() returns
 * 0, as_next_scheduled_action()/as_has_scheduled_action() return false,
 * as_unschedule_action() returns 0 (in tension with its documented int|null)
 * and as_unschedule_all_actions() is a no-op. This keeps the adapter honest (no
 * fake data store) while never failing loudly for callers that tolerate a
 * scheduler being unavailable.
 */
final class WordPressActionSchedulerGateway implements ActionSchedulerGateway
{
    public function isAvailable(): bool
    {
        return function_exists('as_schedule_single_action')
            && function_exists('as_next_scheduled_action')
            && function_exists('as_has_scheduled_action')
            && function_exists('as_unschedule_action')
            && function_exists('as_unschedule_all_actions');
    }

    public function hasScheduled(string $hook, ?array $args = null, string $group = ''): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        return as_has_scheduled_action($hook, $args, $group);
    }

    public function nextScheduled(string $hook, ?array $args = null, string $group = ''): int|bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        return as_next_scheduled_action($hook, $args, $group);
    }

    public function scheduleSingle(
        int $timestamp,
        string $hook,
        array $args = [],
        string $group = '',
        bool $unique = false,
        int $priority = 10
    ): int {
        if (!$this->isAvailable()) {
            return 0;
        }

        return as_schedule_single_action($timestamp, $hook, $args, $group, $unique, $priority);
    }

    public function unscheduleAction(string $hook, array $args = [], string $group = ''): int|null
    {
        if (!$this->isAvailable()) {
            // The real as_unschedule_action() returns 0 when uninitialized,
            // in tension with its documented int|null; mirror that exactly.
            return 0;
        }

        return as_unschedule_action($hook, $args, $group);
    }

    public function unscheduleAll(string $hook, array $args = [], string $group = ''): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        as_unschedule_all_actions($hook, $args, $group);
    }
}
