<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

/**
 * Action Scheduler {@see Scheduler} adapter.
 *
 * Backs the pure scheduler adapter with {@see ActionSchedulerGateway}
 * (as_schedule_single_action / as_next_scheduled_action /
 * as_unschedule_all_actions). Events are keyed by (hook, args) within a single
 * Action Scheduler group, mirroring the WP-Cron adapter so a debounce can ask
 * "already queued?" without stacking duplicates.
 *
 * Dedupe is PENDING-only: the loop re-arms from inside the currently-executing
 * action (WP-Cron delivery), so a running action must never occupy the slot or
 * the next tick is silently refused and the pipeline dies. The insert is
 * therefore non-unique — the {@see self::isScheduled()} guard upstream is the
 * only dedupe, and the exact timestamp handed in is preserved, never moved by
 * silently re-scheduling. One pending action per (hook, args) at a time.
 */
final class WordPressActionScheduler implements Scheduler
{
    /**
     * The Action Scheduler group scope Cast jobs are scheduled under, so they
     * stay partitionable from other plugins' actions.
     */
    public const DEFAULT_GROUP = 'cast/export';

    public function __construct(
        private readonly ActionSchedulerGateway $actions,
        private readonly string $group = self::DEFAULT_GROUP,
    ) {
    }

    public function isScheduled(string $hook, array $args = []): bool
    {
        // Pending-only: as_next_scheduled_action() returns int (a scheduled
        // timestamp) for a pending match but `true` for a currently RUNNING
        // one. A running action must not count as "scheduled" — the rearm from
        // inside the in-flight action would otherwise defeat itself.
        return is_int($this->actions->nextScheduled($hook, $args, $this->group));
    }

    public function scheduleSingle(string $hook, int $at, array $args = []): bool
    {
        // Dedupe is decided BEFORE the insert: a PENDING event that already
        // occupies the (hook, args) slot is refused silently (false), exactly
        // like the WP-Cron adapter it replaced. A running action is not a pending
        // slot, so a tick re-arming from inside itself is always admitted.
        if ($this->isScheduled($hook, $args)) {
            return false;
        }

        // Deliberately NOT unique: unique:true would no-op (0) against a
        // running action's unique_key — the same dead end this fixes — while
        // Cast only schedules dated one-shots where the pending guard above is
        // the one dedupe it needs.
        $actionId = $this->actions->scheduleSingle(
            $at,
            $hook,
            $args,
            $this->group,
        );
        if ($actionId > 0) {
            return true;
        }

        // as_schedule_single_action() returned 0 with nothing already queued:
        // Action Scheduler is unavailable or declined the work. Report it
        // loudly and actionably rather than pretending the run is Scheduled.
        throw new SchedulingFailedException(sprintf(
            'Action Scheduler declined to schedule "%s" at %d (group "%s"). Verify woocommerce/action-scheduler is installed and active and its tables are initialised.',
            $hook,
            $at,
            $this->group,
        ));
    }

    public function cancelSingle(string $hook, array $args = []): void
    {
        $this->actions->unscheduleAll($hook, $args, $this->group);
    }
}
