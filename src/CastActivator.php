<?php

declare(strict_types=1);

namespace LumeWeb\Cast;

use ComposePress\Core\PluginActivator;

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
    }
}
