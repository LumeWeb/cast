<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Admin;

use LumeWeb\Cast\Admin\PermalinkSettings;

/**
 * Test double for {@see PermalinkSettings} that records every call so the
 * PermalinkGuard enforcement/flush/flag logic is exercised without a live
 * WordPress runtime.
 */
final class FakePermalinkSettings implements PermalinkSettings
{
    public string $structure = '';

    public bool $flushed = false;

    public int $flushCount = 0;

    /** @var list<string> */
    public array $writes = [];

    public function structure(): string
    {
        return $this->structure;
    }

    public function setStructure(string $structure): void
    {
        $this->structure = $structure;
        $this->writes[] = $structure;
    }

    public function hasHardFlushed(): bool
    {
        return $this->flushed;
    }

    public function markHardFlushed(): void
    {
        $this->flushed = true;
    }

    public function flushRewriteRules(): void
    {
        $this->flushCount++;
    }
}
