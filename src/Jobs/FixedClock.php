<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

/**
 * In-memory Clock that only moves when a test tells it to.
 *
 * Used to make lock TTL expiry, debounce windows and timeout policies fully
 * deterministic without sleeping. Also usable by single-process runtimes that
 * want a stable source of "now".
 */
final class FixedClock implements Clock
{
    public function __construct(private int $now = 0)
    {
    }

    public function now(): int
    {
        return $this->now;
    }

    public function set(int $now): void
    {
        $this->now = $now;
    }

    public function advance(int $seconds): void
    {
        $this->now += $seconds;
    }
}
