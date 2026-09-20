<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Persistence;

/**
 * The custom `cast_export_items` table: schema, name and DDL.
 *
 * This is the durable home of {@see SqlWorkItemRepository}; the columns are
 * exactly what that repository reads and writes (url-hash unique key, integer
 * retry_at gating and the status/kind vocabulary of the work-item enums). The
 * table is installed (dbDelta) and dropped via the WordPress adapter
 * {@see WordPressCastExportItemsTable}; classes here stay pure so the SQL can
 * be asserted word-for-word in unit tests.
 */
final class CastExportItemsTable
{
    public const NAME = 'cast_export_items';

    /**
     * The fully-prefixed table name, e.g. `wptests_cast_export_items`.
     */
    public static function name(string $prefix): string
    {
        return $prefix . self::NAME;
    }

    /**
     * The dbDelta-formatted CREATE TABLE statement for this table.
     *
     * dbDelta is intentionally used so install is idempotent: re-running the
     * exact same DDL is a no-op, and later schema bumps alter the table in
     * place. The $charsetCollate comes from `$wpdb->get_charset_collate()` so
     * the table always matches the site's own character set.
     */
    public static function createSql(string $table, string $charsetCollate = ''): string
    {
        $collate = $charsetCollate === '' ? '' : ' ' . $charsetCollate;

        return "CREATE TABLE {$table} (\n"
            . "    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
            . "    url_hash CHAR(32) NOT NULL,\n"
            . "    url TEXT NOT NULL,\n"
            . "    identity TEXT NOT NULL,\n"
            . "    path VARCHAR(500) NOT NULL,\n"
            . "    kind VARCHAR(20) NOT NULL,\n"
            . "    priority TINYINT NOT NULL DEFAULT 10,\n"
            . "    status VARCHAR(20) NOT NULL DEFAULT 'queued',\n"
            . "    fetch_attempts TINYINT NOT NULL DEFAULT 0,\n"
            . "    retry_at BIGINT NOT NULL DEFAULT 0,\n"
            . "    PRIMARY KEY  (id),\n"
            . "    UNIQUE KEY url_hash (url_hash)\n"
            . "){$collate};";
    }

    /**
     * Drop the table if it exists; safe to run on uninstall.
     */
    public static function dropSql(string $table): string
    {
        return "DROP TABLE IF EXISTS {$table}";
    }
}
