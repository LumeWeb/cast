<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Persistence;

/**
 * WordPress option-table gateway.
 *
 * Values are the explicitly serialized aggregate arrays; options are always
 * written with autoload disabled so plugin state never rides along on every
 * page load. updateIfEquals() issues a single conditional SQL UPDATE (through
 * `$wpdb`, not update_option(), which has no compare-and-set) so a stale
 * worker can be refused atomically at the row level.
 */
final class WordPressOptionGateway implements OptionGateway
{
    /**
     * The live database handle (the `$wpdb` global or an injected double).
     */
    private readonly object $db;

    public function __construct(?object $db = null)
    {
        $wpdb = $GLOBALS['wpdb'] ?? null;
        if (!is_object($wpdb)) {
            throw new \RuntimeException('WordPress option CAS requires the WordPress $wpdb global.');
        }

        $this->db = $db ?? $wpdb;
    }

    public function get(string $option, mixed $default): mixed
    {
        return get_option($option, $default);
    }

    public function add(string $option, mixed $value, bool $autoload): bool
    {
        return add_option($option, $value, '', $autoload);
    }

    public function update(string $option, mixed $value, bool $autoload): bool
    {
        return update_option($option, $value, $autoload);
    }

    public function delete(string $option): bool
    {
        return delete_option($option);
    }

    public function updateIfEquals(string $option, mixed $value, mixed $expected): bool
    {
        // Compare-and-set in a single UPDATE: the row is only changed while its
        // current option_value still equals the serialized $expected the caller
        // observed, so a concurrent writer that got there first makes this a
        // no-op. wpdb does not serialize values itself: update_option()
        // serializes before it writes, so both sides of the comparison are
        // maybe_serialize()'d below to match the stored serialized shape. A
        // successful CAS always changes exactly one row; the "value unchanged,
        // 0 rows affected" edge cannot occur here because $value is a fresh
        // lease token that differs from $expected by construction.
        $affected = $this->db()->update(
            $this->db()->prefix . 'options',
            ['option_value' => maybe_serialize($value)],
            ['option_name' => $option, 'option_value' => maybe_serialize($expected)],
            ['%s'],
            ['%s', '%s'],
        );

        if ($affected !== 1) {
            return false;
        }

        // The raw UPDATE bypasses update_option()'s object-cache refresh: a
        // same-request get_option() would otherwise still serve the stale
        // pre-CAS value (e.g. the old lease token), so release() would refuse
        // to delete and the lease would leak a full TTL. Refresh the 'options'
        // cache exactly like update_option() does on success — the cached value
        // is the maybe_serialize()'d form, matching get_option()'s unserialize.
        wp_cache_set($option, maybe_serialize($value), 'options');

        return true;
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
}
