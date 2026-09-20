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
 * Load Action Scheduler before Cast boots: Action Scheduler is Cast's single
 * scheduling runtime (CastPlugin wires WordPressActionScheduler over the as_*
 * gateway), so requiring its vendored plugin entry file here guarantees the
 * as_* API is registered before any scheduler is composed. Action Scheduler
 * ships as a plugin/library rather than a PSR-4 package, so it is loaded by
 * its own entry file — the same embedding convention WooCommerce and other
 * host plugins use. Its OWN WP-Cron/loopback queue runner is deliberately left
 * enabled (never disabled here): Action Scheduler decides how the scheduled
 * actions fire, and Cast only ever schedules and cancels through the as_* API.
 *
 * When the dependency is missing (e.g. an incomplete composer install) boot
 * still proceeds; the WordPressActionScheduler seam then fails loudly and
 * actionably on the first scheduling attempt instead of silently pretending
 * a run was queued.
 */
$actionSchedulerEntry = __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
if (file_exists($actionSchedulerEntry)) {
    require_once $actionSchedulerEntry;
}

/*
 * Boot during plugin-file inclusion so activation, deactivation, and uninstall
 * hooks are registered before WordPress finishes loading the plugin. Lifecycle
 * is wired together with the admin-only onboarding HookSubscriber (capability-
 * and nonce-gated) that composes the PageBuilder catalog/installer. No front-
 * end hooks are registered, so public post content is never rewritten by the
 * plugin.
 */
\LumeWeb\Cast\CastPlugin::boot(__FILE__);
