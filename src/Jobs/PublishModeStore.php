<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

/**
 * Read/write wrapper for the persisted publish trigger mode.
 *
 * The scheduler conditions automatic scheduling on {@see mode()}: Manual (the
 * default) records drift only, while On-update preserves the
 * debounce/supersede behavior. Adapters fall back to Manual on an absent or
 * corrupt stored value, and migrate a legacy `scheduled` value to On-update
 * (see {@see PublishMode::fromStored()}).
 */
interface PublishModeStore
{
    public function mode(): PublishMode;

    public function setMode(PublishMode $mode): void;
}
