<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

/**
 * Narrow wrapper around WordPress REST authorization primitives so the publish
 * REST surface stays unit-testable without a live HTTP request.
 *
 * The permission callbacks combine the manage_options capability with a valid
 * wp_rest REST nonce, so a forged or unauthenticated dashboard request can
 * never reach the handler state changes.
 */
interface RestAuth
{
    public function currentUserCan(string $capability): bool;

    /**
     * True when the current request carries a valid WordPress REST nonce for
     * the given action (defaults to core's wp_rest action).
     */
    public function hasValidRestNonce(string $action = 'wp_rest'): bool;
}
