<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit;

use LumeWeb\Cast\CastPlugin;
use LumeWeb\Cast\Uninstall;
use PHPUnit\Framework\TestCase;

final class CastPluginTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['lumeweb_cast_hooks'] = [];
        $GLOBALS['lumeweb_cast_lifecycle'] = [];
    }

    public function testBootRegistersLifecycleWithoutMutatingContent(): void
    {
        CastPlugin::boot('/plugins/cast/cast.php');

        // Regression: a booted plugin must not register any front-end hook that
        // could rewrite public output (e.g. the_content). Only admin hooks from
        // the onboarding entrypoint are expected.
        self::assertSame([], $this->frontendHookNames());

        self::assertSame(
            ['activate', 'deactivate', 'uninstall'],
            array_column($GLOBALS['lumeweb_cast_lifecycle'], 0),
        );
        self::assertSame(
            [Uninstall::class, 'uninstall'],
            $GLOBALS['lumeweb_cast_lifecycle'][2][2],
        );
    }

    /**
     * @return list<string>
     */
    private function frontendHookNames(): array
    {
        return array_values(array_filter(
            array_column($GLOBALS['lumeweb_cast_hooks'], 1),
            fn (string $hook): bool => !$this->isAdminOnlyHook($hook),
        ));
    }

    /**
     * Admin-only hooks never rewrite public content. Everything the plugin
     * registers is admin (menu/notices/enqueue/admin-post) incl. the dashboard
     * setup hook, which only ever fires inside wp-admin.
     */
    private function isAdminOnlyHook(string $hook): bool
    {
        return str_starts_with($hook, 'admin')
            || $hook === 'wp_dashboard_setup';
    }
}
