<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

/**
 * DECOMMISSIONED — Cast no longer wires any {@see Scheduler} to vanilla
 * WP-Cron. CastPlugin composes {@see WordPressActionScheduler} (Action
 * Scheduler) instead, so nothing in Cast calls wp_schedule_single_event() /
 * wp_next_scheduled() / wp_clear_scheduled_hook(). This port is retained only
 * as historically useful generic code with its own unit tests; it is unused in
 * the Cast composition and may be removed once the deletion is performed.
 *
 * The WP-Cron adapter behind {@see WordPressScheduler}: one-shot single events
 * keyed by (hook, args), exactly mirroring wp_schedule_single_event(),
 * wp_next_scheduled() and wp_clear_scheduled_hook().
 *
 * The WordPress adapter delegates to real core functions; tests use an
 * in-memory fake that shares the same (hook, args) identity so dedupe and
 * timestamp preservation stay observable without loading WordPress or
 * redefining global functions.
 */
interface CronGateway
{
    /**
     * The scheduled timestamp of the first matching event, or false when none
     * is queued (mirrors wp_next_scheduled()).
     *
     * @param list<mixed> $args
     */
    public function nextScheduled(string $hook, array $args = []): int|false;

    /**
     * Insert one single event at $timestamp, reporting whether it was accepted
     * (mirrors wp_schedule_single_event(), which may refuse/error).
     *
     * @param list<mixed> $args
     */
    public function scheduleSingleEvent(int $timestamp, string $hook, array $args = []): bool;

    /**
     * Remove every matching single event and report how many were removed
     * (mirrors wp_clear_scheduled_hook()).
     *
     * @param list<mixed> $args
     */
    public function clearScheduledHook(string $hook, array $args = []): int;
}
