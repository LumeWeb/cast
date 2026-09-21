<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

/**
 * WordPress {@see RestAuth} adapter.
 *
 * Capability checks defer to core's current_user_can(); the REST nonce is read
 * from the X-WP-Nonce header (as sent by wp-api-fetch / wp.data) and verified
 * with wp_verify_nonce against core's wp_rest action. A missing or empty
 * header, a failed verification, or a user without the capability all deny.
 */
final class WordPressRestAuth implements RestAuth
{
    public function currentUserCan(string $capability): bool
    {
        return current_user_can($capability);
    }

    public function hasValidRestNonce(string $action = 'wp_rest'): bool
    {
        $nonce = $_SERVER['HTTP_X_WP_NONCE'] ?? null;
        if (!is_string($nonce) || $nonce === '') {
            return false;
        }

        return (bool) wp_verify_nonce($nonce, $action);
    }
}
