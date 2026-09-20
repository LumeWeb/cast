<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

/**
 * In-memory Lock backed by a Clock, used by unit tests and single-process
 * runtimes. A lease is a key → absolute-expiry map; leases naturally expire
 * as the Clock advances and can then be reclaimed.
 */
final class InMemoryLock implements Lock
{
    /**
     * @var array<string, int>
     */
    private array $leases = [];

    public function __construct(private Clock $clock)
    {
    }

    public function acquire(string $key, int $ttlSeconds): bool
    {
        if ($this->isHeld($key)) {
            return false;
        }

        $this->leases[$key] = $this->clock->now() + $ttlSeconds;

        return true;
    }

    public function release(string $key): void
    {
        unset($this->leases[$key]);
    }

    public function isHeld(string $key): bool
    {
        $until = $this->leases[$key] ?? null;

        return $until !== null && $until > $this->clock->now();
    }

    public function leaseExpiresAt(string $key): ?int
    {
        return $this->leases[$key] ?? null;
    }
}
