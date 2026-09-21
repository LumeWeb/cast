<?php

declare(strict_types=1);

namespace LumeWeb\Cast;

use ComposePress\Core\PluginUninstall;
use LumeWeb\Cast\Admin\PortalConnectionResolver;
use LumeWeb\Cast\Admin\WordPressPermalinkSettings;
use LumeWeb\Cast\Export\RetentionPolicy;
use LumeWeb\Cast\Export\WordPressPackEnvironment;
use LumeWeb\Cast\Jobs\WordPressIdentityGateway;
use LumeWeb\Cast\Jobs\WordPressLock;
use LumeWeb\Cast\Jobs\WordPressPublishModeStore;
use LumeWeb\Cast\Persistence\WordPressCastExportItemsTable;
use LumeWeb\Cast\Persistence\WordPressRunRepository;
use LumeWeb\Cast\Persistence\WordPressWizardStore;

final class Uninstall implements PluginUninstall
{
    private const VERSION_OPTION = 'cast_version';

    public static function uninstall(): void
    {
        delete_option(self::VERSION_OPTION);
        delete_network_option(null, self::VERSION_OPTION);

        // The single global onboarding option (hard cutover: no per-user
        // user-meta and no legacy formats exist to clean up).
        delete_option(WordPressWizardStore::OPTION_KEY);

        // The publish/export surface owns its global options + custom table;
        // uninstall removes all of them (deactivation preserves them).
        delete_option(WordPressPublishModeStore::OPTION_KEY);   // cast_publish_mode
        delete_option(WordPressRunRepository::OPTION_KEY);      // cast_export_run
        delete_option(WordPressIdentityGateway::OPTION_KEY);    // cast_publish_identity

        // Policy and guard flags the earlier cleanup left behind. The
        // permalink flush flag matters most: surviving it lets a fresh
        // reinstall skip its one-time hard flush, leaving plain permalinks
        // unflushed and the export probe broken.
        delete_option(WordPressPermalinkSettings::FLUSH_FLAG_OPTION);
        delete_option(RetentionPolicy::OPTION);
        delete_option(WordPressPackEnvironment::VALIDATION_MODE_OPTION);
        delete_transient(PortalConnectionResolver::CACHE_KEY);

        // Each lock is one `cast_lease_*` option row; the keys are dynamic, so
        // they are swept by prefix instead of named. esc_like() keeps the
        // underscores in the prefix literal rather than LIKE wildcards.
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . $wpdb->options . ' WHERE option_name LIKE %s',
            $wpdb->esc_like(WordPressLock::OPTION_PREFIX) . '%',
        ));

        // The custom work-item table is plugin-owned: uninstall removes it,
        // while deactivation (a temporary state) deliberately preserves it.
        (new WordPressCastExportItemsTable())->drop();
    }
}
