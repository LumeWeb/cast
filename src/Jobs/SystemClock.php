<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

/**
 * Production {@see Clock}: the real wall clock (unix epoch seconds), matching
 * the integer timestamp contract of WP-Cron and the Export\Run aggregate.
 */
final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }
}
