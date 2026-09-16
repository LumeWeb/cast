<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit;

use LumeWeb\Cast\Uninstall;
use PHPUnit\Framework\TestCase;

final class UninstallTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['lumeweb_cast_options'] = [];
        $GLOBALS['lumeweb_cast_network_options'] = [];
    }

    public function testUninstallRemovesPersistedOption(): void
    {
        $GLOBALS['lumeweb_cast_options']['cast_version'] = '0.1.0';

        Uninstall::uninstall();

        self::assertSame([], $GLOBALS['lumeweb_cast_options']);
    }

    public function testUninstallRemovesGlobalOnboardingOption(): void
    {
        $GLOBALS['lumeweb_cast_options']['cast_onboarding'] = ['schema_version' => 1];

        Uninstall::uninstall();

        self::assertArrayNotHasKey('cast_onboarding', $GLOBALS['lumeweb_cast_options']);
    }

    public function testUninstallRemovesPersistedNetworkOption(): void
    {
        $GLOBALS['lumeweb_cast_network_options']['cast_version'] = '0.1.0';

        Uninstall::uninstall();

        self::assertSame([], $GLOBALS['lumeweb_cast_network_options']);
    }
}
