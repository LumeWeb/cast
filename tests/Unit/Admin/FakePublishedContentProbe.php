<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Admin;

use LumeWeb\Cast\Admin\PublishedContentProbe;

/**
 * In-memory PublishedContentProbe driven by a plain boolean so the setup
 * service's readiness decision is testable without any WordPress query.
 */
final class FakePublishedContentProbe implements PublishedContentProbe
{
    public function __construct(public bool $eligible = false)
    {
    }

    public function hasEligibleContent(): bool
    {
        return $this->eligible;
    }
}
