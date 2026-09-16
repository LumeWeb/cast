<?php

declare(strict_types=1);

namespace LumeWeb\Cast;

use ComposePress\Core\PluginDeactivator;

/**
 * Keeps the deactivation lifecycle contract while deliberately preserving state.
 *
 * The version option recorded on activation backs future migrations, and
 * deactivation (a temporary, reversible state) must not destroy it. Only the
 * uninstall routine (Uninstall::uninstall()) removes plugin-owned state.
 */
final class CastDeactivator implements PluginDeactivator
{
    public function deactivate(bool $networkWide): void
    {
        // Intentionally a no-op: deactivation preserves persisted plugin state.
    }
}
