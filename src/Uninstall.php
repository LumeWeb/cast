<?php

declare(strict_types=1);

namespace LumeWeb\Cast;

use ComposePress\Core\PluginUninstall;
use LumeWeb\Cast\Jobs\WordPressIdentityGateway;
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

        // The publish/export surface owns four global options + one custom
        // table; uninstall removes all of them (deactivation preserves them).
        delete_option(WordPressPublishModeStore::OPTION_KEY);   // cast_publish_mode
        delete_option(WordPressRunRepository::OPTION_KEY);      // cast_export_run
        delete_option(WordPressIdentityGateway::OPTION_KEY);    // cast_publish_identity

        // The custom work-item table is plugin-owned: uninstall removes it,
        // while deactivation (a temporary state) deliberately preserves it.
        (new WordPressCastExportItemsTable())->drop();
    }
}
