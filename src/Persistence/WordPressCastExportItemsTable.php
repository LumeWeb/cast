<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Persistence;

/**
 * WordPress adapter that installs and drops {@see CastExportItemsTable}.
 *
 * install() feeds the dbDelta-formatted DDL to the core dbDelta() upgrade
 * function (loading wp-admin/includes/upgrade.php when the runtime has not yet
 * pulled it in, exactly like a conventional plugin's activation hook), so the
 * schema is created idempotently under the live `$wpdb->prefix`. drop() runs a
 * prepared DROP TABLE IF EXISTS so uninstall removes the queue completely.
 *
 * Mirrors the other WordPress persistence adapters: the `$wpdb` global is the
 * default database handle, and an alternative object (a test double) can be
 * injected. The handle is stored as a plain object (so any double can be
 * injected at runtime) and narrowed to the real {@see \wpdb} surface for
 * static analysis through {@see WordPressCastExportItemsTable::db()}.
 */
final class WordPressCastExportItemsTable
{
    /**
     * The live database handle (the `$wpdb` global or an injected double).
     */
    private readonly object $db;

    public function __construct(?object $db = null)
    {
        $wpdb = $GLOBALS['wpdb'] ?? null;
        if (!is_object($wpdb)) {
            throw new \RuntimeException('Cast items table lifecycle requires the WordPress $wpdb global.');
        }

        $this->db = $db ?? $wpdb;
    }

    /**
     * The live handle, narrowed to the wpdb surface Cast talks to.
     *
     * No native return type: the injected handle is only ever a plain object
     * (test doubles are never real `wpdb` instances), so the wpdb narrowing is
     * purely a static-analysis contract and must not be enforced at runtime.
     *
     * @return \wpdb
     */
    private function db()
    {
        /** @var \wpdb $db */
        $db = $this->db;

        return $db;
    }

    /**
     * Create (or bring up to date) the items table through dbDelta.
     *
     * dbDelta is idempotent: an unchanged schema is a no-op on repeat calls,
     * and a later DDL addition alters the existing table in place.
     */
    public function install(): void
    {
        if (!function_exists('dbDelta') && defined('ABSPATH')) {
            $upgrade = ABSPATH . 'wp-admin/includes/upgrade.php';
            if (is_file($upgrade)) {
                require_once $upgrade;
            }
        }

        dbDelta(CastExportItemsTable::createSql(
            CastExportItemsTable::name($this->db()->prefix),
            $this->db()->get_charset_collate(),
        ));
    }

    /**
     * Remove the items table, if present, on uninstall.
     */
    public function drop(): void
    {
        $table = CastExportItemsTable::name($this->db()->prefix);

        $sql = $this->db()->prepare('DROP TABLE IF EXISTS %i', $table);
        if (is_string($sql)) {
            $this->db()->query($sql);
        }
    }

    /**
     * Migrate a pre run-scoping queue table: swap the legacy url_hash-only
     * unique key for (run_id, url_hash); dbDelta cannot replace an existing
     * key, so this step has to. Idempotent: no table, or the run-scoped key
     * already in place, is a no-op.
     */
    public function migrateRunScope(): void
    {
        $db = $this->db();
        $table = CastExportItemsTable::name($db->prefix);

        // The SHOW query is built from the (internally derived, quoted) table
        // name and passed to prepare() as a single literal, so the LIKE
        // pattern is a safe constant.
        $exists = $db->get_var($db->prepare('SHOW TABLES LIKE %s', $db->esc_like($table)));
        if ($exists === null) {
            return;
        }

        // A row back means the legacy url_hash-only unique key is still in
        // place; null means the table is already run-scoped.
        $legacyKey = $db->get_row($db->prepare(
            'SHOW INDEX FROM %i WHERE Key_name = %s',
            $table,
            CastExportItemsTable::LEGACY_UNIQUE_KEY,
        ));
        if ($legacyKey === null) {
            return;
        }

        $this->db()->query(CastExportItemsTable::runScopeKeySql($table));
    }
}
