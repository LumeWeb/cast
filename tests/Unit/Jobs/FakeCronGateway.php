<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Jobs;

use LumeWeb\Cast\Jobs\CronGateway;

/**
 * In-memory CronGateway that mirrors WP-Cron's (hook, args) event identity,
 * used by unit tests for the WordPress scheduler adapter without redefining
 * global functions. Records the exact timestamp handed to the scheduler so the
 * adapter's "preserve timestamps" contract is observable.
 */
final class FakeCronGateway implements CronGateway
{
    /**
     * @var array<string, array{at: int, hook: string, args: list<mixed>}>
     */
    public array $events = [];

    public int $scheduleCalls = 0;

    public int $clearCalls = 0;

    /**
     * When true, scheduleSingleEvent accepts; when false the event is refused
     * (mirrors WP returning a WP_Error / blocked pre_schedule_event filter).
     */
    public bool $scheduleResult = true;

    public function nextScheduled(string $hook, array $args = []): int|false
    {
        return $this->events[$this->key($hook, $args)]['at'] ?? false;
    }

    public function scheduleSingleEvent(int $timestamp, string $hook, array $args = []): bool
    {
        ++$this->scheduleCalls;

        if (!$this->scheduleResult) {
            return false;
        }

        $key = $this->key($hook, $args);
        if (isset($this->events[$key])) {
            return true;
        }

        $this->events[$key] = [
            'at' => $timestamp,
            'hook' => $hook,
            'args' => $args,
        ];

        return true;
    }

    public function clearScheduledHook(string $hook, array $args = []): int
    {
        ++$this->clearCalls;
        unset($this->events[$this->key($hook, $args)]);

        return 0;
    }

    /**
     * The scheduled instant for a matching event, or null when none is queued.
     *
     * @param list<mixed> $args
     */
    public function nextAt(string $hook, array $args = []): ?int
    {
        return $this->events[$this->key($hook, $args)]['at'] ?? null;
    }

    public function count(): int
    {
        return count($this->events);
    }

    /**
     * @param list<mixed> $args
     */
    private function key(string $hook, array $args): string
    {
        return $hook . '::' . serialize($args);
    }
}
