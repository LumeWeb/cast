<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

/**
 * In-memory Scheduler that records single events keyed by hook + serialized
 * args. Mirrors WP-Cron's (hook, args) identity so the dedupe contract in
 * {@see Scheduler} is exercised without a live WordPress install.
 *
 * A delivered event moves from pending to running, so {@see self::isScheduled()}
 * (pending-only) refuses a duplicate while a next tick is queued but admits a
 * rearm from inside the currently-executing action — the exact production
 * shape the Action Scheduler adapter must survive.
 */
final class InMemoryScheduler implements Scheduler
{
    /**
     * @var array<string, array{at: int, hook: string, args: list<mixed>}>
     */
    private array $events = [];

    /**
     * Keys of events currently running (delivered to the runner), mirroring
     * WP-Cron removing a single event as it fires.
     *
     * @var list<string>
     */
    private array $running = [];

    public function isScheduled(string $hook, array $args = []): bool
    {
        return isset($this->events[$this->key($hook, $args)]);
    }

    public function scheduleSingle(string $hook, int $at, array $args = []): bool
    {
        $key = $this->key($hook, $args);
        if (isset($this->events[$key])) {
            return false;
        }

        $this->events[$key] = [
            'at' => $at,
            'hook' => $hook,
            'args' => $args,
        ];

        return true;
    }

    public function cancelSingle(string $hook, array $args = []): void
    {
        unset($this->events[$this->key($hook, $args)]);
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

    /**
     * Marks a (hook, args) event as currently running, as WP-Cron does when it
     * delivers a single event: it leaves pending (so a rearm can re-queue) and
     * becomes in-flight until {@see self::stopRunning()}.
     *
     * @param list<mixed> $args
     */
    public function startRunning(string $hook, array $args = []): void
    {
        $key = $this->key($hook, $args);
        unset($this->events[$key]);
        if (!in_array($key, $this->running, true)) {
            $this->running[] = $key;
        }
    }

    /**
     * Concludes a running event, freeing its (hook, args) slot.
     *
     * @param list<mixed> $args
     */
    public function stopRunning(string $hook, array $args = []): void
    {
        $key = $this->key($hook, $args);
        $this->running = array_values(array_filter(
            $this->running,
            static fn (string $runningKey): bool => $runningKey !== $key,
        ));
    }

    /**
     * @return list<array{at: int, hook: string, args: list<mixed>}>
     */
    public function all(): array
    {
        return array_values($this->events);
    }

    /**
     * The number of PENDING events (running events are not queued work).
     */
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
