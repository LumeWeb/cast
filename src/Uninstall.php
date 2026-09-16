<?php

declare(strict_types=1);

namespace LumeWeb\Cast;

use ComposePress\Core\PluginUninstall;
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
    }
}
