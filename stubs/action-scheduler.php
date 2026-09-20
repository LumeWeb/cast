<?php

// phpcs:disable
// This file intentionally mirrors the vendored Action Scheduler 4.2.0 runtime
// API exactly (global snake_case functions and global snake_case classes,
// several classes per file) so PHPStan can type-check the Cast boundary against
// the real signal. That is PSR-1-non-compliant BY DESIGN, so PHPCS is disabled
// here — the same reason the real Action Scheduler library disables its own
// sniffs. The project PHPCS scope (composer style: `.phpcs.xml.dist` -> src +
// tests) already excludes stubs; this header keeps the file clean even when
// scanned explicitly.
/**
 * PHPStan stub declaring the public Action Scheduler API surface Cast's
 * scheduler adapter speaks to.
 *
 * Action Scheduler (woocommerce/action-scheduler) is a real composer
 * dependency of this package (pinned ^4.2), but it ships as a WordPress
 * plugin without a composer.json of its own, so Composer installs it under
 * vendor/woocommerce/action-scheduler and does NOT autoload its classes —
 * they only exist once the plugin entry file (required by cast.php before
 * Cast boots) has loaded. The as_* functions are intentionally safe no-ops
 * at their documented boundaries before ActionScheduler::is_initialized()
 * returns true — as_schedule_single_action() returns 0, the query functions
 * return false, as_unschedule_action() returns 0 and as_unschedule_all_actions()
 * returns void.
 *
 * Signatures mirror https://github.com/woocommerce/action-scheduler 4.2.0
 * functions.php and classes/abstracts/ActionScheduler_Store.php so the guarded
 * WordPressActionSchedulerGateway — and the integration tests that count
 * scheduled actions through the real store — stay honest with the runtime.
 */

/**
 * Schedule an action to run one time.
 *
 * @param int    $timestamp When the job will run.
 * @param string $hook      The hook to trigger.
 * @param array  $args      Arguments to pass when the hook triggers.
 * @param string $group     The group to assign this job to.
 * @param bool   $unique    Skip scheduling when another pending or running
 *                          action has the same hook/args/group.
 * @param int    $priority  Lower values take precedence (0-255).
 *
 * @return int The action ID. Zero if there was an error scheduling the action.
 */
function as_schedule_single_action(
    int $timestamp,
    string $hook,
    array $args = array(),
    string $group = '',
    bool $unique = false,
    int $priority = 10
): int {
}

/**
 * Check if there is an existing action in the queue with a given hook, args
 * and group combination.
 *
 * @param string     $hook  Name of the hook to search for.
 * @param array|null $args  Arguments of the action to be searched; null matches
 *                          any args.
 * @param string     $group Group of the action to be searched.
 *
 * @return int|bool The timestamp for the next pending scheduled action, true
 *                  for an async or in-progress action, or false if there is no
 *                  matching action.
 */
function as_next_scheduled_action(
    string $hook = '',
    array|null $args = null,
    string $group = ''
): int|bool {
}

/**
 * Efficiently check whether a matching action is pending or in-progress.
 *
 * @param string     $hook  The hook of the action.
 * @param array|null $args  Args passed to the action; null matches any args.
 * @param string     $group The group the job is assigned to.
 *
 * @return bool True if a matching action is pending or in-progress.
 */
function as_has_scheduled_action(
    string $hook = '',
    array|null $args = null,
    string $group = ''
): bool {
}

/**
 * Cancel the next occurrence of a scheduled (pending) action.
 *
 * @param string $hook  The hook that the job will trigger.
 * @param array  $args  Args that would have been passed to the job.
 * @param string $group The group the job is assigned to.
 *
 * @return int|null The cancelled action ID if found, null if no matching
 *                  action found, or 0 when the runtime is uninitialized.
 */
function as_unschedule_action(
    string $hook,
    array $args = array(),
    string $group = ''
): int|null {
}

/**
 * Cancel all pending occurrences of a scheduled action with the given
 * hook/args/group. Already-running actions are left to complete.
 *
 * @param string $hook  The hook that the job will trigger.
 * @param array  $args  Args that would have been passed to the job.
 * @param string $group The group the job is assigned to.
 */
function as_unschedule_all_actions(
    string $hook,
    array $args = array(),
    string $group = ''
): void {
}

/**
 * The Action Scheduler bootstrap class surface the runner-boundary integration
 * test uses to confirm the data store was initialised. Mirrors the 4.2.0
 * ActionScheduler::is_initialized() contract.
 *
 * @see https://github.com/woocommerce/action-scheduler/blob/4.2.0/classes/abstracts/ActionScheduler.php
 */
abstract class ActionScheduler
{
    /**
     * Whether Action Scheduler has initialised its data store.
     */
    public static function is_initialized(?string $function_name = null): bool
    {
    }
}

/**
 * The Action Scheduler store surface Cast's integration suite counts scheduled
 * actions through: the status constants and public query API of
 * ActionScheduler_Store (the abstract store the DB store implements in 4.2.0).
 *
 * @see https://github.com/woocommerce/action-scheduler/blob/4.2.0/classes/abstracts/ActionScheduler_Store.php
 */
abstract class ActionScheduler_Store
{
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'in-progress';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELED = 'canceled';

    public static function instance(): static
    {
    }

    /**
     * Query for scheduled actions matching $query, returning their action IDs.
     *
     * @param array<string, mixed> $query      Query arguments (hook, group,
     *                                         status, args, per_page, ...).
     * @param string               $query_type 'select' returns action IDs.
     *
     * @return list<int>
     */
    abstract public function query_actions(array $query = array(), string $query_type = 'select'): array;
}
