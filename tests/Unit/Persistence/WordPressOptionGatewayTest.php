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
        $GLOBALS['lumeweb_cast_option_cache'] = [];
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

        // The stored row holds the serialized shape exactly as update_option()
        // wrote it; only then does the CAS fire.
        $wpdb->rows['cast_lease_run'] = maybe_serialize($expected);
        $wpdb->queryResult = 1;
        self::assertTrue($gateway->updateIfEquals('cast_lease_run', $newLease, $expected));

        self::assertCount(1, $wpdb->updates);
        [$table, $data, $where, $format, $whereFormat] = $wpdb->updates[0];
        self::assertSame('wptests_options', $table);
        // wpdb does not serialize: the gateway hands over the already-serialized
        // shapes, so the recorded UPDATE carries maybe_serialize()'d values.
        self::assertSame(['option_value' => maybe_serialize($newLease)], $data);
        self::assertSame(['option_name' => 'cast_lease_run', 'option_value' => maybe_serialize($expected)], $where);
        self::assertSame(['%s'], $format);
        self::assertSame(['%s', '%s'], $whereFormat);
    }

    public function testUpdateIfEqualsIsContentBasedOnTheStoredSerializedValue(): void
    {
        $wpdb = new FakeWpDb();
        $gateway = new WordPressOptionGateway($wpdb);
        $newLease = ['token' => 'fresh', 'expires_at' => 1060, 'acquired_at' => 1000];
        $expected = ['token' => 'stale', 'expires_at' => 900, 'acquired_at' => 800];

        // True content-based comparison: the stored row's option_value equals
        // maybe_serialize($expected), so the CAS fires.
        $wpdb->rows['cast_lease_run'] = maybe_serialize($expected);
        $wpdb->queryResult = 1;
        self::assertTrue($gateway->updateIfEquals('cast_lease_run', $newLease, $expected));

        // A stored row whose serialized value differs ($expected changed since
        // the caller read it) must make the CAS a no-op even though a scripted
        // result of 1 would have claimed a row changed — this pins the repair,
        // not a blindly scripted hit.
        $wpdb->rows['cast_lease_run'] = maybe_serialize(['token' => 'someone-else', 'expires_at' => 99999, 'acquired_at' => 99990]);
        $wpdb->queryResult = 1;
        self::assertFalse($gateway->updateIfEquals('cast_lease_run', $newLease, $expected));

        self::assertCount(2, $wpdb->updates);
    }

    public function testUpdateIfEqualsReportsFalseWhenNoRowMatched(): void
    {
        $wpdb = new FakeWpDb();
        $gateway = new WordPressOptionGateway($wpdb);

        // No stored row matches the serialized expected value: the conditional
        // UPDATE affects zero rows.
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

    public function testSuccessfulCasRefreshesTheCachedSerializedValue(): void
    {
        $wpdb = new FakeWpDb();
        $gateway = new WordPressOptionGateway($wpdb);

        // Prime the cache with the stale lease exactly as a preceding
        // get_option() read would have (serialized, like core stores it), and
        // mirror that same serialized value in the stored row so the CAS has a
        // genuine match to fire on.
        $stale = ['token' => 'stale', 'expires_at' => 900, 'acquired_at' => 800];
        $wpdb->rows['cast_lease_run'] = maybe_serialize($stale);
        wp_cache_set('cast_lease_run', maybe_serialize($stale), 'options');

        $newLease = ['token' => 'fresh', 'expires_at' => 1060, 'acquired_at' => 1000];
        $wpdb->queryResult = 1;
        self::assertTrue($gateway->updateIfEquals('cast_lease_run', $newLease, $stale));

        // The raw conditional UPDATE must refresh the 'options' cache the same
        // way update_option() does (serialized value), so a same-request
        // get_option() observes the fresh lease rather than the stale pre-CAS
        // one.
        self::assertSame(maybe_serialize($newLease), wp_cache_get('cast_lease_run', 'options'));
    }

    public function testCasThatMatchesNoRowLeavesTheStaleCacheEntryIntact(): void
    {
        $wpdb = new FakeWpDb();
        $gateway = new WordPressOptionGateway($wpdb);

        $stale = ['token' => 'stale', 'expires_at' => 900, 'acquired_at' => 800];
        wp_cache_set('cast_lease_run', maybe_serialize($stale), 'options');

        $wpdb->queryResult = 0;
        self::assertFalse($gateway->updateIfEquals('cast_lease_run', ['token' => 'fresh'], $stale));

        // A CAS that matched no row must not touch the cache: it stays stale.
        self::assertSame(maybe_serialize($stale), wp_cache_get('cast_lease_run', 'options'));
    }

    public function testCasFailingOnDatabaseErrorLeavesTheCacheUntouched(): void
    {
        $wpdb = new FakeWpDb();
        $gateway = new WordPressOptionGateway($wpdb);

        $stale = ['token' => 'stale', 'expires_at' => 900, 'acquired_at' => 800];
        wp_cache_set('cast_lease_run', maybe_serialize($stale), 'options');

        $wpdb->queryResult = false;
        self::assertFalse($gateway->updateIfEquals('cast_lease_run', ['token' => 'fresh'], $stale));

        self::assertSame(maybe_serialize($stale), wp_cache_get('cast_lease_run', 'options'));
    }
}
