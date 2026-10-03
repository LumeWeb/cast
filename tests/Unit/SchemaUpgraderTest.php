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
        $GLOBALS['lumeweb_cast_wpdb_queries'] = [];

        (new SchemaUpgrader('0.2.0'))->upgrade();

        self::assertSame([], $GLOBALS['lumeweb_cast_dbdelta_calls'], 'a converged install must not re-run dbDelta');
        self::assertSame([], $GLOBALS['lumeweb_cast_wpdb_queries'] ?? [], 'a converged install must not touch the table at all');
    }

    public function testUpgradeRunsTheRunScopeMigrationOnAStaleInstall(): void
    {
        $GLOBALS['lumeweb_cast_options']['cast_version'] = '0.1.0';
        $GLOBALS['lumeweb_cast_wpdb_queries'] = [];

        (new SchemaUpgrader('0.2.0'))->upgrade();

        // The stale install re-runs the dbDelta install (which adds the new
        // run_id/worker_token/lease_expires_at columns to the existing table)
        // AND the run-scope migration step (which swaps the legacy url_hash
        // unique key); the SHOW TABLES probe proves the step ran.
        self::assertNotSame([], $GLOBALS['lumeweb_cast_dbdelta_calls']);
        self::assertContains(
            'SHOW TABLES LIKE \'wptests\\\\_cast\\\\_export\\\\_items\'',
            $GLOBALS['lumeweb_cast_wpdb_queries'],
            'the run-scope migration step must probe the table on a stale install (esc_like-escaped LIKE pattern)',
        );
    }
}
