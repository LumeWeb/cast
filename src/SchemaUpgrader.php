<?php

declare(strict_types=1);

namespace LumeWeb\Cast;

use LumeWeb\Cast\Persistence\WordPressCastExportItemsTable;

/**
 * Runtime, version-gated schema migration for Cast's custom tables.
 *
 * The activation hook cannot be relied on to keep the schema current: the
 * platform deployment path (the workspaces WordPress image) swaps updated
 * plugin files into an already-active install on every boot, and the
 * cast-guard must-use plugin makes a deactivate/reactivate transition
 * unreachable — so {@see CastActivator} runs exactly once per install, and
 * only for installs activated after its schema existed. This upgrader closes
 * that gap: on every boot it compares the persisted `cast_version` option
 * with the running plugin version and re-runs the idempotent dbDelta install
 * (plus any later schema steps) whenever they diverge — healing installs
 * upgraded in place, DB restores from older snapshots, and
 * never-yet-activated sites.
 *
 * On a healthy install the cost is one cached get_option() read.
 */
final class SchemaUpgrader
{
    public const VERSION_OPTION = 'cast_version';

    public function __construct(
        private readonly string $version,
    ) {
    }

    /**
     * Bring the schema up to the running plugin version and record it.
     *
     * Safe to call on every request: the install is idempotent (dbDelta is a
     * no-op when the schema already matches) and the version compare makes a
     * converged install skip the work entirely.
     */
    public function upgrade(): void
    {
        if (get_option(self::VERSION_OPTION) === $this->version) {
            return;
        }

        (new WordPressCastExportItemsTable())->install();

        update_option(self::VERSION_OPTION, $this->version);
    }
}
