<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Persistence;

/**
 * Minimal WordPress transients wrapper isolating get_transient()/set_transient()
 * behind an interface, so consumers are testable without loading WordPress.
 *
 * Transients are the cross-request fast TTL store: unlike options they carry a
 * per-entry expiry, which is exactly the contract a memoized portal response
 * needs — fresh within the TTL, without pinning the value for the plugin's
 * lifetime.
 */
interface TransientGateway
{
    public function get(string $key, mixed $default): mixed;

    public function set(string $key, mixed $value, int $ttlSeconds): void;
}
