<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

/**
 * The pure outcome of one bounded tick step, produced by an injected
 * {@see BoundTick} implementation (the export pipeline work itself lives
 * outside this module).
 *
 *  - more()      the step made progress; more ticks are needed;
 *  - done()      the run is finished cleanly;
 *  - fail(...)   the step failed with a safe, non-credential reason;
 *  - stale()     the step signals the run/lease is stuck — the watchdog
 *                reclaim decision boundary, represented here without any SQL.
 */
final class TickResult
{
    private function __construct(
        public readonly bool $finished,
        public readonly ?string $failure,
        public readonly bool $stale,
    ) {
    }

    public static function more(): self
    {
        return new self(false, null, false);
    }

    public static function done(): self
    {
        return new self(true, null, false);
    }

    public static function fail(string $reason): self
    {
        return new self(false, $reason, false);
    }

    public static function stale(): self
    {
        return new self(false, null, true);
    }
}
