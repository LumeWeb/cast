<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit;

use LumeWeb\Cast\Admin\PublishAdminSubscriber;
use LumeWeb\Cast\CastPlugin;
use PHPUnit\Framework\TestCase;

/**
 * Production-runtime composition: {@see CastPlugin::boot()} must wire the
 * publish admin subscriber (PublishAdminSubscriber) into the real subscriber
 * list so the Publish admin page, its capability/screen-restricted styles and the
 * admin-bar node are actually registered on a booted plugin. The publish
 * surface registers action-only hooks (admin_menu, admin_enqueue_scripts,
 * admin_bar_menu) — nothing on the front end — and shares one subscriber
 * instance across all three so the booted composition is deliberate, not
 * duplicate. Double boot must keep each publish hook registered exactly once
 * (the boot guard already guarantees idempotent hook registration).
 */
final class CastPluginPublishAdminCompositionTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['lumeweb_cast_hooks'] = [];
        $GLOBALS['lumeweb_cast_lifecycle'] = [];
        $GLOBALS['lumeweb_cast_options'] = [];
        $GLOBALS['lumeweb_cast_network_options'] = [];
        // Each test boots as if a fresh WordPress request arrived; boot is
        // idempotent within a process, so re-arm the guard between tests.
        CastPlugin::resetBootIdempotence();
    }

    public function testBootWiresThePublishAdminSubscriberSurface(): void
    {
        CastPlugin::boot('/plugins/cast/cast.php');

        // admin_menu → registerMenu (onboarding also registers admin_menu; the
        // publish subscriber's own entry must be present exactly once).
        $menu = $this->publishCallbacks('admin_menu', 'registerMenu');
        self::assertCount(1, $menu);

        // admin_enqueue_scripts → registerAssets, capability/screen-restricted.
        $assets = $this->publishCallbacks('admin_enqueue_scripts', 'registerAssets');
        self::assertCount(1, $assets);

        // admin_bar_menu → registerAdminBar at the post-core priority 100.
        $adminBar = $this->publishCallbacks('admin_bar_menu', 'registerAdminBar');
        self::assertCount(1, $adminBar);
        self::assertSame(100, $adminBar[0][2]);

        // One shared subscriber instance is composed across all three hooks —
        // the boot builds a single PublishAdminSubscriber over the shared
        // status service and request context, not a fresh instance per hook.
        self::assertSame($menu[0][4][0], $assets[0][4][0]);
        self::assertSame($menu[0][4][0], $adminBar[0][4][0]);
    }

    public function testDoubleBootRegistersPublishAdminHooksExactlyOnce(): void
    {
        CastPlugin::boot('/plugins/cast/cast.php');

        $singleBootHooks = $GLOBALS['lumeweb_cast_hooks'];

        CastPlugin::boot('/plugins/cast/cast.php');

        self::assertSame(
            $singleBootHooks,
            $GLOBALS['lumeweb_cast_hooks'],
            'A second boot must not re-register any publish admin hook callback.',
        );
        self::assertCount(1, $this->publishCallbacks('admin_menu', 'registerMenu'));
        self::assertCount(1, $this->publishCallbacks('admin_enqueue_scripts', 'registerAssets'));
        self::assertCount(1, $this->publishCallbacks('admin_bar_menu', 'registerAdminBar'));
    }

    /**
     * The booted hook entries whose callback is a PublishAdminSubscriber
     * method, in registration order.
     *
     * @return list<array{
     *     0: string,
     *     1: string,
     *     2: int,
     *     3: int,
     *     4: array{0: PublishAdminSubscriber, 1: string},
     * }>
     */
    private function publishCallbacks(string $hook, string $method): array
    {
        return array_values(array_filter(
            $GLOBALS['lumeweb_cast_hooks'],
            static fn (array $entry): bool => is_array($entry[4] ?? null)
                && ($entry[4][0] ?? null) instanceof PublishAdminSubscriber
                && ($entry[4][1] ?? null) === $method,
        ));
    }
}
