<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Jobs;

use LumeWeb\Cast\Jobs\ActionSchedulerGateway;

/**
 * In-memory ActionSchedulerGateway that mirrors the Action Scheduler public
 * API's (hook, args, group) identity and its uninitialized boundary (0 / false
 * / null / no-op), so the WordPressActionScheduler adapter's Scheduler
 * contract stays observable without loading the Action Scheduler library or
 * redefining global functions.
 */
final class FakeActionSchedulerGateway implements ActionSchedulerGateway
{
    /**
     * Pending scheduled actions keyed by (group, hook, serialized args).
     *
     * @var array<string, array{timestamp: int, hook: string, args: list<mixed>, group: string, id: int}>
     */
    public array $actions = [];

    /**
     * Keys of actions currently running; as_next_scheduled_action() reports
     * `true` for these (a timestamp is unknown until they finish).
     *
     * @var list<string>
     */
    public array $running = [];

    /**
     * When false the gateway behaves like an uninitialized / absent Action
     * Scheduler: never available and every call degrades to the documented
     * 0 / false / null / no-op boundary.
     */
    public bool $available = true;

    /**
     * When true the gateway refuses to accept new actions even while
     * available (mirrors as_schedule_single_action() returning 0 on failure).
     */
    public bool $reject = false;

    public int $scheduleCalls = 0;

    public int $unscheduleCalls = 0;

    private int $lastId = 0;

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function hasScheduled(string $hook, ?array $args = null, string $group = ''): bool
    {
        if (!$this->available) {
            return false;
        }

        $key = $this->key($hook, $args ?? [], $group);

        return isset($this->actions[$key]) || in_array($key, $this->running, true);
    }

    public function nextScheduled(string $hook, ?array $args = null, string $group = ''): int|bool
    {
        if (!$this->available) {
            return false;
        }

        $key = $this->key($hook, $args ?? [], $group);

        if (in_array($key, $this->running, true)) {
            return true;
        }

        return $this->actions[$key]['timestamp'] ?? false;
    }

    public function scheduleSingle(
        int $timestamp,
        string $hook,
        array $args = [],
        string $group = '',
        bool $unique = false,
        int $priority = 10
    ): int {
        ++$this->scheduleCalls;

        if (!$this->available || $this->reject) {
            return 0;
        }

        $key = $this->key($hook, $args, $group);

        if ($unique && isset($this->actions[$key])) {
            return 0;
        }

        $this->actions[$key] = [
            'timestamp' => $timestamp,
            'hook' => $hook,
            'args' => $args,
            'group' => $group,
            'id' => ++$this->lastId,
        ];

        return $this->lastId;
    }

    public function unscheduleAction(string $hook, array $args = [], string $group = ''): int|null
    {
        ++$this->unscheduleCalls;

        if (!$this->available) {
            return null;
        }

        $key = $this->key($hook, $args, $group);

        if (!isset($this->actions[$key])) {
            return null;
        }

        $id = $this->actions[$key]['id'];
        unset($this->actions[$key]);

        return $id;
    }

    public function unscheduleAll(string $hook, array $args = [], string $group = ''): void
    {
        ++$this->unscheduleCalls;

        if (!$this->available) {
            return;
        }

        // as_unschedule_all_actions() only cancels *pending* matches (its
        // as_unschedule_action() loop queries STATUS_PENDING): an already
        // running action is deliberately left to run to completion.
        unset($this->actions[$this->key($hook, $args, $group)]);
    }

    /**
     * The scheduled instant for a matching action, or null when none is
     * pending (test probe).
     *
     * @param list<mixed> $args
     */
    public function nextAt(string $hook, array $args = [], string $group = ''): ?int
    {
        return $this->actions[$this->key($hook, $args, $group)]['timestamp'] ?? null;
    }

    public function count(): int
    {
        return count($this->actions) + count($this->running);
    }

    /**
     * Marks a (hook, args, group) action as currently running so tests can
     * exercise as_next_scheduled_action()'s `true` return. Mirrors Action
     * Scheduler's status transition: a running action is no longer pending.
     *
     * @param list<mixed> $args
     */
    public function startRunning(string $hook, array $args = [], string $group = ''): void
    {
        $key = $this->key($hook, $args, $group);
        unset($this->actions[$key]);
        if (!in_array($key, $this->running, true)) {
            $this->running[] = $key;
        }
    }

    /**
     * Concludes a running action (mirrors Action Scheduler's status
     * transition to complete/failed), freeing the (hook, args, group) slot.
     *
     * @param list<mixed> $args
     */
    public function stopRunning(string $hook, array $args = [], string $group = ''): void
    {
        $key = $this->key($hook, $args, $group);
        $this->running = array_values(array_filter(
            $this->running,
            static fn (string $runningKey): bool => $runningKey !== $key,
        ));
    }

    /**
     * @param list<mixed> $args
     */
    private function key(string $hook, array $args, string $group): string
    {
        return $group . '|' . $hook . '|' . serialize($args);
    }
}
