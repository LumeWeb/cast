<?php

declare(strict_types=1);

namespace LumeWeb\Cast\PageBuilder;

/**
 * Filesystem state of installed plugins, as seen by the guided installer.
 *
 * The provider is the single source of truth the installer uses to decide
 * whether a curated candidate is installed, active, and (when installed) what
 * its plugin basename is — the value WordPress core needs to activate it via
 * wp.updates.activatePlugin(). Kept behind an interface so the installer's
 * allowlist policy can be unit-tested without a live plugin directory.
 */
interface PluginStateProvider
{
    /**
     * The plugin basename (e.g. 'brizy/brizy.php') for a given wp.org slug,
     * or null when no installed plugin directory matches the slug.
     */
    public function basename(string $slug): ?string;

    /** Whether an installed plugin directory matches the slug. */
    public function isInstalled(string $slug): bool;

    /** Whether the installed plugin matching the slug is currently active. */
    public function isActive(string $slug): bool;
}
