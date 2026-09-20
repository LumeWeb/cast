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

    public function testUpdateIfEqualsDispatchesTheConditionalUpdateOnlyWhenValueMatches(): void
    {
        $wpdb = new FakeWpDb();
        $gateway = new WordPressOptionGateway($wpdb);
        $newLease = ['token' => 'fresh', 'expires_at' => 1060, 'acquired_at' => 1000];
        $expected = ['token' => 'stale', 'expires_at' => 900, 'acquired_at' => 800];

        $wpdb->queryResult = 1;
        self::assertTrue($gateway->updateIfEquals('cast_lease_run', $newLease, $expected));

        self::assertCount(1, $wpdb->updates);
        [$table, $data, $where, $format, $whereFormat] = $wpdb->updates[0];
        self::assertSame('wptests_options', $table);
        self::assertSame(['option_value' => $newLease], $data);
        self::assertSame(['option_name' => 'cast_lease_run', 'option_value' => $expected], $where);
        self::assertSame(['%s'], $format);
        self::assertSame(['%s', '%s'], $whereFormat);
    }

    public function testUpdateIfEqualsReportsFalseWhenNoRowMatched(): void
    {
        $wpdb = new FakeWpDb();
        $gateway = new WordPressOptionGateway($wpdb);

        $wpdb->queryResult = 0;
        self::assertFalse($gateway->updateIfEquals('cast_lease_run', ['token' => 'fresh'], ['token' => 'stale']));

        self::assertCount(1, $wpdb->updates);
    }

    public function testUpdateIfEqualsReportsFalseOnDatabaseError(): void
    {
        $wpdb = new FakeWpDb();
        $gateway = new WordPressOptionGateway($wpdb);

        $wpdb->queryResult = false;
        self::assertFalse($gateway->updateIfEquals('cast_lease_run', ['token' => 'fresh'], ['token' => 'stale']));

        self::assertCount(1, $wpdb->updates);
    }
}
