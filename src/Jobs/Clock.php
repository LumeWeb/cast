<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

/**
 * Wall-clock abstraction so WP-Cron job mechanics stay deterministic in tests.
 *
 * Returns unix epoch seconds, matching the integer timestamps used by the
 * Export\\Run aggregate and by WordPress's wp_schedule_single_event()/time().
 */
interface Clock
{
    public function now(): int;
}
