<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

/**
 * Scoped lease gateway: exactly one WP-Cron worker may own a named key at a
 * time, and only for a bounded TTL so a crashed tick never blocks the queue.
 *
 * A lease that has passed its TTL is no longer held (`isHeld` is false and a
 * fresh `acquire` succeeds — that is the reclaim path). `leaseExpiresAt`
 * still reports the past expiry timestamp so callers can distinguish "free"
 * from "stale", which lets the stale-lock policy decide whether to reclaim or
 * to no-op. The WordPress adapter can back this with a transient (expiry =
 * TTL) so the same contract holds; no SQL is needed in this module.
 */
interface Lock
{
    /**
     * Try to take the keyed lease for $ttlSeconds.
     *
     * Returns false when a live (unexpired) lease already exists for $key; an
     * expired lease is treated as free and can be reclaimed.
     */
    public function acquire(string $key, int $ttlSeconds): bool;

    /** Drop the lease for $key. Safe to call when not held. */
    public function release(string $key): void;

    /** True only while a live (unexpired) lease exists for $key. */
    public function isHeld(string $key): bool;

    /**
     * Unix timestamp when the current lease for $key expires, or null when
     * the key has never been leased. A timestamp in the past means the lease
     * is stale (expired but not yet reclaimed/detected).
     */
    public function leaseExpiresAt(string $key): ?int;
}
