<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Admin;

use LumeWeb\Cast\Admin\PermalinkNoticeDismissalStore;

/**
 * Test double for {@see PermalinkNoticeDismissalStore} recording dismissal
 * state so the notice visibility logic is exercised without WordPress user-meta.
 */
final class FakePermalinkNoticeDismissalStore implements PermalinkNoticeDismissalStore
{
    public bool $dismissed = false;

    public int $dismissCount = 0;

    public function currentUserDismissed(): bool
    {
        return $this->dismissed;
    }

    public function dismiss(): void
    {
        $this->dismissed = true;
        $this->dismissCount++;
    }

    public function clear(): void
    {
        $this->dismissed = false;
    }
}
