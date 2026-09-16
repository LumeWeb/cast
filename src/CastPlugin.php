<?php

declare(strict_types=1);

namespace LumeWeb\Cast;

use ComposePress\Core\Plugin;
use ComposePress\Core\PluginContext;
use LumeWeb\Cast\Admin\OnboardingAdminSubscriber;
use LumeWeb\Cast\Admin\OnboardingRequestHandler;
use LumeWeb\Cast\Admin\WordPressRequestContext;
use LumeWeb\Cast\Onboarding\WizardService;
use LumeWeb\Cast\PageBuilder\PageBuilderCatalog;
use LumeWeb\Cast\PageBuilder\PageBuilderInstaller;
use LumeWeb\Cast\PageBuilder\WordPressPluginStateProvider;
use LumeWeb\Cast\Persistence\WordPressWizardStore;

final class CastPlugin
{
    private const SLUG = 'cast';
    private const VERSION = '0.1.0';

    public static function boot(string $pluginFile): void
    {
        // First-run onboarding: one global wizard aggregate persisted in the
        // non-autoloaded `cast_onboarding` option (no per-user user-meta) and
        // an admin-only "Getting Started" entrypoint.
        $store = new WordPressWizardStore();
        $wizard = new WizardService(
            $store,
            new PageBuilderCatalog(),
            plugins: new WordPressPluginStateProvider(),
        );
        $context = new WordPressRequestContext();
        $handler = new OnboardingRequestHandler($wizard, $context);
        $catalog = new PageBuilderCatalog();
        $plugins = new WordPressPluginStateProvider();
        $installer = new PageBuilderInstaller($catalog, $plugins);

        $subscribers = [
            // The same server-side provider is shared everywhere so the wizard
            // install rows and the view-model reconciliation always agree about
            // the actual runtime plugin state.
            new OnboardingAdminSubscriber($handler, $wizard, $context, $catalog, $installer, $plugins),
        ];

        $plugin = new Plugin(
            context: new PluginContext($pluginFile, self::SLUG, self::VERSION),
            subscribers: $subscribers,
            activator: new CastActivator(self::VERSION),
            deactivator: new CastDeactivator(),
            uninstaller: Uninstall::class,
        );

        $plugin->boot();
    }
}
