<?php

declare(strict_types=1);

namespace LumeWeb\Cast\PageBuilder;

/**
 * PluginStateProvider backed by WordPress core get_plugins()/is_plugin_active().
 *
 * The basename for a slug is derived by exact directory match against the
 * basenames get_plugins() reports (e.g. 'brizy' -> 'brizy/brizy.php'). The
 * directory comparison is exact, so a slug never leaks into a similarly named
 * sibling directory ('brizy' does not match 'brizy-premium/brizy-premium.php').
 */
final class WordPressPluginStateProvider implements PluginStateProvider
{
    /** @var list<string>|null Basenames cached per request. */
    private ?array $pluginBasenames = null;

    public function basename(string $slug): ?string
    {
        if ($slug === '') {
            return null;
        }

        foreach ($this->basenames() as $basename) {
            $directory = explode('/', $basename, 2)[0];

            if ($directory === $slug) {
                return $basename;
            }
        }

        return null;
    }

    public function isInstalled(string $slug): bool
    {
        return $this->basename($slug) !== null;
    }

    public function isActive(string $slug): bool
    {
        $basename = $this->basename($slug);

        return $basename !== null && is_plugin_active($basename);
    }

    /**
     * @return list<string>
     */
    private function basenames(): array
    {
        if ($this->pluginBasenames === null) {
            $this->pluginBasenames = array_keys(get_plugins());
        }

        return $this->pluginBasenames;
    }
}
