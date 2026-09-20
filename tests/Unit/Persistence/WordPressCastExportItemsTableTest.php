<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Persistence;

use LumeWeb\Cast\Persistence\WordPressCastExportItemsTable;
use PHPUnit\Framework\TestCase;

/**
 * WordPress adapter for the custom items table: install is a dbDelta CREATE
 * TABLE driven by the live $wpdb prefix/charset, and drop is a prepared
 * DROP TABLE IF EXISTS against the same $wpdb. The adapter runs against the
 * recording {@see FakeWpDb} here; the real, idempotent dbDelta behaviour is
 * proven against MariaDB in the integration suite.
 */
final class WordPressCastExportItemsTableTest extends TestCase
{
    private FakeWpDb $db;

    private WordPressCastExportItemsTable $table;

    protected function setUp(): void
    {
        $this->db = new FakeWpDb();
        $this->table = new WordPressCastExportItemsTable($this->db);
        $GLOBALS['lumeweb_cast_dbdelta_calls'] = [];
    }

    public function testInstallRunsDbDeltaWithTheCreateTableSql(): void
    {
        $this->table->install();

        self::assertCount(1, $GLOBALS['lumeweb_cast_dbdelta_calls']);
        self::assertStringContainsString(
            'CREATE TABLE wptests_cast_export_items',
            $GLOBALS['lumeweb_cast_dbdelta_calls'][0],
        );
        self::assertStringContainsString(
            'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $GLOBALS['lumeweb_cast_dbdelta_calls'][0],
        );
    }

    public function testInstallIsIdempotent(): void
    {
        $this->table->install();
        $this->table->install();

        self::assertCount(2, $GLOBALS['lumeweb_cast_dbdelta_calls']);
        self::assertSame(
            $GLOBALS['lumeweb_cast_dbdelta_calls'][0],
            $GLOBALS['lumeweb_cast_dbdelta_calls'][1],
            'a repeated install must issue the exact same DDL (dbDelta is a no-op when unchanged)',
        );
    }

    public function testDropPreparesAndDispatchesDropTableIfExists(): void
    {
        $this->table->drop();

        self::assertCount(1, $this->db->prepared);
        self::assertSame('DROP TABLE IF EXISTS %i', $this->db->prepared[0][0]);
        self::assertSame(['wptests_cast_export_items'], $this->db->prepared[0][1]);
        self::assertSame(['PREPARED(1)'], $this->db->queries);
    }

    public function testUsesTheInjectedPrefixForTableNames(): void
    {
        $this->db->prefix = 'multisite_';

        $table = new WordPressCastExportItemsTable($this->db);
        $table->install();
        $table->drop();

        self::assertStringContainsString('CREATE TABLE multisite_cast_export_items', $GLOBALS['lumeweb_cast_dbdelta_calls'][0]);
        self::assertSame(['multisite_cast_export_items'], $this->db->prepared[0][1]);
    }
}
