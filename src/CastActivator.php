<?php

declare(strict_types=1);

namespace LumeWeb\Cast;

use ComposePress\Core\PluginActivator;
use LumeWeb\Cast\Persistence\WordPressCastExportItemsTable;

final class CastActivator implements PluginActivator
{
    private const VERSION_OPTION = 'cast_version';

    public function __construct(
        private readonly string $version,
    ) {
    }

    public function activate(bool $networkWide): void
    {
        if ($networkWide) {
            update_network_option(null, self::VERSION_OPTION, $this->version);

            return;
        }

        update_option(self::VERSION_OPTION, $this->version);

        // Schema comes up with the plugin: the durable work-item queue table is
        // installed idempotently (dbDelta) under the site's own prefix the
        // moment the plugin is enabled.
        (new WordPressCastExportItemsTable())->install();

        // Front-end routing comes up with the plugin: hard-flush the rewrite
        // rules so the permalink structure (already enforced to 'Day and name'
        // by PermalinkGuard on admin_init) is actually served to visitors. The
        // flag-based per-request churn guard lives in the runtime enforcer;
        // activation is a one-time event, so flushing here is always safe.
        flush_rewrite_rules();
    }
}
