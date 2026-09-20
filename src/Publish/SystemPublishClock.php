<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * Production {@see PublishClock}: the real wall-clock pause between upload
 * polling observations. The pure publish orchestration only ever touches this
 * one sleep primitive, so the boot composition can hand it the real pause
 * while unit tests inject a recording fake and never actually block. The
 * non-positive guard keeps a degenerate backoff from ever spinning a request.
 */
final class SystemPublishClock implements PublishClock
{
    public function sleep(int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }
    }
}
