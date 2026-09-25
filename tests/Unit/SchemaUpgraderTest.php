<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit;

use LumeWeb\Cast\SchemaUpgrader;
use PHPUnit\Framework\TestCase;

final class SchemaUpgraderTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['lumeweb_cast_options'] = [];
        $GLOBALS['lumeweb_cast_dbdelta_calls'] = [];
    }

    public function testUpgradeInstallsTheItemsTableAndRecordsTheVersionWhenNeverMigrated(): void
    {
        (new SchemaUpgrader('0.2.0'))->upgrade();

        self::assertSame('0.2.0', get_option('cast_version'));
        self::assertNotSame([], $GLOBALS['lumeweb_cast_dbdelta_calls'], 'a missing version must trigger the schema install');
        self::assertStringContainsString(
            'CREATE TABLE wptests_cast_export_items',
            $GLOBALS['lumeweb_cast_dbdelta_calls'][0],
        );
    }

    public function testUpgradeReinstallsWhenTheStoredVersionDiffers(): void
    {
        $GLOBALS['lumeweb_cast_options']['cast_version'] = '0.1.0';

        (new SchemaUpgrader('0.2.0'))->upgrade();

        self::assertSame('0.2.0', get_option('cast_version'));
        self::assertNotSame([], $GLOBALS['lumeweb_cast_dbdelta_calls'], 'a stale version must trigger the schema install');
    }

    public function testUpgradeIsANoOpWhenTheVersionAlreadyMatches(): void
    {
        $GLOBALS['lumeweb_cast_options']['cast_version'] = '0.2.0';

        (new SchemaUpgrader('0.2.0'))->upgrade();

        self::assertSame([], $GLOBALS['lumeweb_cast_dbdelta_calls'], 'a converged install must not re-run dbDelta');
    }
}
