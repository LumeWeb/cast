<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

use LumeWeb\Cast\Portal\SelfIdentification;

/**
 * Lazy wrapper for the dashboard Connection card: resolves the portal
 * self-identification (account + exactly-one workspace) ONLY when asked —
 * never during plugin boot/activation. Implementations must not throw:
 * a resolution failure is returned as a {@see SelfIdentification} carrying a
 * safe, value-free error state, never credentials.
 */
interface ConnectionResolver
{
    /**
     * The current self-identification, or null when the provider is not wired.
     *
     * Never throws: failures become the SelfIdentification error state.
     */
    public function current(): ?SelfIdentification;
}
