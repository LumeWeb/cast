<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

/**
 * The Action Scheduler adapter behind {@see WordPressActionScheduler}: a minimal,
 * typed subset of the public Action Scheduler API
 * (as_schedule_single_action / as_next_scheduled_action /
 * as_has_scheduled_action / as_unschedule_action / as_unschedule_all_actions).
 *
 * Actions are keyed by (hook, args, group), exactly like Action Scheduler.
 * The WordPress adapter delegates to the real as_* functions behind an
 * availability guard; unit tests use an in-memory fake that shares the same
 * identity so dedupe and timestamp preservation stay observable without
 * loading the Action Scheduler library or redefining global functions.
 */
interface ActionSchedulerGateway
{
    /**
     * Whether the Action Scheduler public API is loaded and safe to call. When
     * false every operation degrades to the library's own uninitialized
     * boundary (0 / false / null / no-op) instead of failing loudly. Even when
     * true, the real functions are themselves no-ops before the runtime is
     * initialised, so callers must tolerate a scheduler that declines work.
     */
    public function isAvailable(): bool;

    /**
     * True when a matching action exists, pending or running (mirrors
     * as_has_scheduled_action()).
     *
     * @param list<mixed>|null $args
     */
    public function hasScheduled(string $hook, ?array $args = null, string $group = ''): bool;

    /**
     * The scheduled timestamp of a pending match, `true` when a match is
     * currently running (no timestamp is known), or `false` when none exists
     * (mirrors as_next_scheduled_action()). Callers that dedupe must treat the
     * `true` (running) arm as NOT pending — a rearm from inside the running
     * action must be admitted, not refused.
     *
     * @param list<mixed>|null $args
     */
    public function nextScheduled(string $hook, ?array $args = null, string $group = ''): int|bool;

    /**
     * Schedule one action at $timestamp, returning its action id, or 0 when
     * refused/unavailable (mirrors as_schedule_single_action()).
     *
     * @param list<mixed> $args
     */
    public function scheduleSingle(
        int $timestamp,
        string $hook,
        array $args = [],
        string $group = '',
        bool $unique = false,
        int $priority = 10
    ): int;

    /**
     * Cancel the single next matching action and return its id, or null when
     * none matched (mirrors as_unschedule_action()).
     *
     * @param list<mixed> $args
     */
    public function unscheduleAction(string $hook, array $args = [], string $group = ''): int|null;

    /**
     * Cancel every matching action for the hook/args/group (mirrors
     * as_unschedule_all_actions()); safe to call when none matched.
     *
     * @param list<mixed> $args
     */
    public function unscheduleAll(string $hook, array $args = [], string $group = ''): void;
}
