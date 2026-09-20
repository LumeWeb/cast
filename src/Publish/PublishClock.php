<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * The only wall-clock primitive the pure publish orchestration touches. Fakes
 * record sleep() calls so backoff sequences are asserted without real pauses;
 * a future runner adapts it to the real scheduler (e.g. wp_sleep or a yield).
 */
interface PublishClock
{
    public function sleep(int $seconds): void;
}
