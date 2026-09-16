<?php

/**
 * Plugin Name: Cast
 * Plugin URI: https://github.com/LumeWeb/cast
 * Description: An onboarding companion for WordPress workspaces, built on ComposePress.
 * Version: 0.1.0
 * Author: LumeWeb
 * License: GPL-3.0-or-later
 * Requires PHP: 8.2
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$castAutoload = __DIR__ . '/vendor/autoload.php';

if (!file_exists($castAutoload)) {
    add_action(
        'admin_notices',
        static function (): void {
            echo '<div class="notice notice-error"><p>Cast is missing its dependencies. Run <code>composer install</code> from the plugin directory.</p></div>';
        }
    );

    return;
}

require_once $castAutoload;

/*
 * Boot during plugin-file inclusion so activation, deactivation, and uninstall
 * hooks are registered before WordPress finishes loading the plugin. Lifecycle
 * is wired together with the admin-only onboarding HookSubscriber (capability-
 * and nonce-gated) that composes the PageBuilder catalog/installer. No front-
 * end hooks are registered, so public post content is never rewritten by the
 * plugin.
 */
\LumeWeb\Cast\CastPlugin::boot(__FILE__);
