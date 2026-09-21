<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Publish;

use LumeWeb\Cast\Publish\PublishClock;

/**
 * Records every sleep() call instead of blocking, so backoff sequences are
 * asserted deterministically without a real pause.
 */
final class FakePublishClock implements PublishClock
{
    /**
     * @var list<int> Seconds slept, in order.
     */
    public array $slept = [];

    public function sleep(int $seconds): void
    {
        $this->slept[] = $seconds;
    }
}
