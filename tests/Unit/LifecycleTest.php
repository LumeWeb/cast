<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit;

use LumeWeb\Cast\CastActivator;
use LumeWeb\Cast\CastDeactivator;
use PHPUnit\Framework\TestCase;

final class LifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['lumeweb_cast_options'] = [];
        $GLOBALS['lumeweb_cast_network_options'] = [];
    }

    public function testActivateStoresVersionOption(): void
    {
        (new CastActivator('0.1.0'))->activate(false);

        self::assertSame('0.1.0', get_option('cast_version'));
    }

    public function testNetworkActivateStoresNetworkOption(): void
    {
        (new CastActivator('0.1.0'))->activate(true);

        self::assertSame('0.1.0', get_network_option(null, 'cast_version'));
        self::assertFalse(get_option('cast_version'));
    }

    public function testDeactivateLeavesVersionOptionIntact(): void
    {
        $GLOBALS['lumeweb_cast_options']['cast_version'] = '0.1.0';

        (new CastDeactivator())->deactivate(false);

        self::assertSame('0.1.0', get_option('cast_version'));
    }

    public function testNetworkDeactivateLeavesNetworkOptionIntact(): void
    {
        $GLOBALS['lumeweb_cast_network_options']['cast_version'] = '0.1.0';

        (new CastDeactivator())->deactivate(true);

        self::assertSame('0.1.0', get_network_option(null, 'cast_version'));
    }
}
