<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

/**
 * One-shot event scheduling gateway, the pure adapter over the scheduler backend
 * ({@see WordPressActionScheduler}, the Action Scheduler adapter).
 *
 * Events are keyed by (hook, args) within the backend's identity, so the
 * debounce service can ask "is this already queued?" and ask for a single
 * non-duplicate event without touching WordPress internals in tests.
 */
interface Scheduler
{
    /**
     * True when a single PENDING event with this hook/args is already
     * scheduled. A currently running event is not pending (every backend only
     * reports pending events), so a loop re-arming from inside the running
     * action is always admitted.
     *
     * @param list<mixed> $args
     */
    public function isScheduled(string $hook, array $args = []): bool;

    /**
     * Schedule one event at unix $at, unless one with this hook/args already
     * exists. Returns false and stores nothing when already scheduled so
     * callers (and tests) can observe the dedupe.
     *
     * @param list<mixed> $args
     */
    public function scheduleSingle(string $hook, int $at, array $args = []): bool;

    /**
     * Remove every matching single event; safe to call when none is scheduled.
     *
     * @param list<mixed> $args
     */
    public function cancelSingle(string $hook, array $args = []): void;
}
