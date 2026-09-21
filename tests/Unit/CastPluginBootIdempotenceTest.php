<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit;

use LumeWeb\Cast\CastPlugin;
use PHPUnit\Framework\TestCase;

/**
 * Boot idempotence: {@see CastPlugin::boot()} must register every WordPress
 * hook exactly once even when it is invoked twice in the same process — the
 * real-world calling pattern when the plugin entry point is included more than
 * once (or when a test harness boots again for a fresh request). A second boot
 * must be a strict no-op: identical hook registrations and a single set of
 * life-cycle hooks, with no re-registered callbacks.
 */
final class CastPluginBootIdempotenceTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['lumeweb_cast_hooks'] = [];
        $GLOBALS['lumeweb_cast_lifecycle'] = [];
        $GLOBALS['lumeweb_cast_options'] = [];
        CastPlugin::resetBootIdempotence();
    }

    /**
     * The core contract: the recorded hook registrations after a double boot
     * are identical to those after a single boot — nothing is re-registered.
     */
    public function testBootTwiceRegistersEachHookExactlyOnce(): void
    {
        CastPlugin::boot('/plugins/cast/cast.php');

        $singleBootHooks = $GLOBALS['lumeweb_cast_hooks'];
        self::assertNotEmpty($singleBootHooks);

        CastPlugin::boot('/plugins/cast/cast.php');

        self::assertSame(
            $singleBootHooks,
            $GLOBALS['lumeweb_cast_hooks'],
            'A second boot must not re-register any hook callback.',
        );
    }

    /**
     * Life-cycle hooks (activate/deactivate/uninstall) must also be registered
     * a single time, not duplicated, when boot runs twice.
     */
    public function testBootTwiceRegistersLifecycleHooksOnce(): void
    {
        CastPlugin::boot('/plugins/cast/cast.php');

        self::assertSame(
            ['activate', 'deactivate', 'uninstall'],
            array_column($GLOBALS['lumeweb_cast_lifecycle'], 0),
        );

        CastPlugin::boot('/plugins/cast/cast.php');

        self::assertSame(
            ['activate', 'deactivate', 'uninstall'],
            array_column($GLOBALS['lumeweb_cast_lifecycle'], 0),
            'Life-cycle hooks must be registered once even when boot runs twice.',
        );
    }
}
