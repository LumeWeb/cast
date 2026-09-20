<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\WrapupEnvironment;

/**
 * Scripted {@see WrapupEnvironment} fake so the pure wrap-up stage is
 * exercised without any WordPress adapter. Reports a configured uploads jail
 * root that the stage deletes inside of.
 */
final class FakeWrapupEnvironment implements WrapupEnvironment
{
    public function __construct(private readonly string $uploads)
    {
    }

    public function uploadsDirectory(): string
    {
        return $this->uploads;
    }
}
