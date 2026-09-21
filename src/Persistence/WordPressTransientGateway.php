<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Persistence;

/**
 * Production transient gateway over WordPress's get_transient()/set_transient().
 *
 * A missing or expired transient reads as $default, matching both the site
 * (non-multisite) and network transient semantics well enough for the TTL
 * cache contracts built on this gateway.
 */
final class WordPressTransientGateway implements TransientGateway
{
    public function get(string $key, mixed $default): mixed
    {
        $value = \get_transient($key);
        if ($value === false) {
            return $default;
        }

        return $value;
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        \set_transient($key, $value, $ttlSeconds);
    }
}
