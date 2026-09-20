<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Persistence;

use LumeWeb\Cast\Persistence\WordPressOptionGateway;
use PHPUnit\Framework\TestCase;

final class WordPressOptionGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['lumeweb_cast_options'] = [];
        $GLOBALS['lumeweb_cast_last_option_autoload'] = [];
    }

    public function testGetReturnsTheDefaultWhenOptionMissing(): void
    {
        self::assertNull((new WordPressOptionGateway())->get('cast_export_run', null));
    }

    public function testGetReturnsTheStoredOptionValue(): void
    {
        $GLOBALS['lumeweb_cast_options']['cast_export_run'] = ['schema_version' => 1];

        self::assertSame(
            ['schema_version' => 1],
            (new WordPressOptionGateway())->get('cast_export_run', null),
        );
    }

    public function testAddStoresTheOptionWithAutoloadDisabled(): void
    {
        $gateway = new WordPressOptionGateway();

        self::assertTrue($gateway->add('cast_export_run', ['a' => 1], false));
        self::assertSame(['a' => 1], $GLOBALS['lumeweb_cast_options']['cast_export_run']);
        self::assertFalse($GLOBALS['lumeweb_cast_last_option_autoload']['cast_export_run']);
    }

    public function testUpdateStoresTheOptionWithAutoloadDisabled(): void
    {
        $gateway = new WordPressOptionGateway();

        self::assertTrue($gateway->update('cast_export_run', ['b' => 2], false));
        self::assertSame(['b' => 2], $GLOBALS['lumeweb_cast_options']['cast_export_run']);
        self::assertFalse($GLOBALS['lumeweb_cast_last_option_autoload']['cast_export_run']);
    }

    public function testDeleteRemovesTheOption(): void
    {
        $GLOBALS['lumeweb_cast_options']['cast_export_run'] = ['a' => 1];

        self::assertTrue((new WordPressOptionGateway())->delete('cast_export_run'));
        self::assertArrayNotHasKey('cast_export_run', $GLOBALS['lumeweb_cast_options']);
    }
}
