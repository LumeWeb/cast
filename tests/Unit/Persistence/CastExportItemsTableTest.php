<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Persistence;

use LumeWeb\Cast\Persistence\CastExportItemsTable;
use PHPUnit\Framework\TestCase;

/**
 * Schema contract for the custom `cast_export_items` table.
 *
 * The table is the durable home of {@see SqlWorkItemRepository}: exactly the
 * columns that repository reads and writes (id, url_hash, url, identity, path,
 * kind, priority, status, fetch_attempts, retry_at). It is installed with
 * dbDelta (see {@see \LumeWeb\Cast\Persistence\WordPressCastExportItemsTable}),
 * so the DDL follows dbDelta's strict formatting invariants for a stable,
 * idempotent migration.
 */
final class CastExportItemsTableTest extends TestCase
{
    public function testNameIsTheOwnTableUnderAnyPrefix(): void
    {
        self::assertSame('cast_export_items', CastExportItemsTable::NAME);
        self::assertSame('wptests_cast_export_items', CastExportItemsTable::name('wptests_'));
        self::assertSame('wp_cast_export_items', CastExportItemsTable::name('wp_'));
    }

    public function testCreateSqlNamesTheOwnTable(): void
    {
        $sql = CastExportItemsTable::createSql('wptests_cast_export_items', '');

        self::assertStringContainsString('CREATE TABLE wptests_cast_export_items', $sql);
    }

    public function testCreateSqlDeclaresEveryRepositoryColumn(): void
    {
        $sql = CastExportItemsTable::createSql('wptests_cast_export_items', '');

        self::assertStringContainsString('url_hash CHAR(32) NOT NULL', $sql);
        self::assertStringContainsString('url TEXT NOT NULL', $sql);
        self::assertStringContainsString('identity TEXT NOT NULL', $sql);
        self::assertStringContainsString('path VARCHAR(500) NOT NULL', $sql);
        self::assertStringContainsString('kind VARCHAR(20) NOT NULL', $sql);
        self::assertStringContainsString('priority TINYINT NOT NULL DEFAULT 10', $sql);
        self::assertStringContainsString("status VARCHAR(20) NOT NULL DEFAULT 'queued'", $sql);
        self::assertStringContainsString('fetch_attempts TINYINT NOT NULL DEFAULT 0', $sql);
        self::assertStringContainsString('retry_at BIGINT NOT NULL DEFAULT 0', $sql);
    }

    public function testCreateSqlDeclaresPrimaryAndUniqueKeys(): void
    {
        $sql = CastExportItemsTable::createSql('wptests_cast_export_items', '');

        self::assertStringContainsString('PRIMARY KEY  (id)', $sql);
        self::assertStringContainsString('UNIQUE KEY url_hash (url_hash)', $sql);
    }

    public function testCreateSqlAppendsTheRequestedCharsetCollate(): void
    {
        $sql = CastExportItemsTable::createSql(
            'wptests_cast_export_items',
            'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        );

        self::assertStringContainsString(
            'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $sql,
        );
    }

    public function testCreateSqlDerivesTableNameFromPrefix(): void
    {
        $sql = CastExportItemsTable::createSql(CastExportItemsTable::name('wptests_'));

        self::assertStringContainsString('CREATE TABLE wptests_cast_export_items', $sql);
    }

    public function testDropSqlDropsOnlyTheOwnTableIfItExists(): void
    {
        self::assertSame(
            'DROP TABLE IF EXISTS wptests_cast_export_items',
            CastExportItemsTable::dropSql('wptests_cast_export_items'),
        );
    }
}
