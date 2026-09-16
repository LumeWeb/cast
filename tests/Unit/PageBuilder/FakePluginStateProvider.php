<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\PageBuilder;

use LumeWeb\Cast\PageBuilder\PluginStateProvider;

/**
 * Test double for PluginStateProvider with per-slug configurable state so the
 * installer policy can be exercised without a live WordPress plugin directory.
 */
final class FakePluginStateProvider implements PluginStateProvider
{
    /** @var array<string, string> slug => installed basename (or slug absent = not installed) */
    public array $installed = [];

    /** @var list<string> basenames currently active */
    public array $active = [];

    public function basename(string $slug): ?string
    {
        return $this->installed[$slug] ?? null;
    }

    public function isInstalled(string $slug): bool
    {
        return isset($this->installed[$slug]);
    }

    public function isActive(string $slug): bool
    {
        $basename = $this->installed[$slug] ?? null;

        return $basename !== null && in_array($basename, $this->active, true);
    }
}
