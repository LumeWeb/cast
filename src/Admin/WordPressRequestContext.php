<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

/**
 * RequestContext backed by live WordPress request/authorization primitives.
 *
 * deny()/redirectToAdminPage() terminate the process in production
 * (via wp_die / wp_safe_redirect + exit), which cannot run in unit tests; those
 * two are covered by manual/browser verification rather than unit assertions.
 */
final class WordPressRequestContext implements RequestContext
{
    public function isCurrentUserAllowed(string $capability): bool
    {
        return current_user_can($capability);
    }

    public function requestNonce(): ?string
    {
        $nonce = $_REQUEST['_wpnonce'] ?? null;

        return is_string($nonce) ? $nonce : null;
    }

    public function requestParam(string $key): mixed
    {
        return $_REQUEST[$key] ?? null;
    }

    public function nonceField(string $action): string
    {
        return wp_nonce_field($action, '_wpnonce', true, false);
    }

    public function createNonce(string $action): string
    {
        return (string) wp_create_nonce($action);
    }

    public function verifyNonce(?string $nonce, string $action): bool
    {
        return $nonce !== null && (bool) wp_verify_nonce($nonce, $action);
    }

    public function currentUserId(): int
    {
        return (int) get_current_user_id();
    }

    public function currentScreenId(): ?string
    {
        $screen = get_current_screen();

        if ($screen === false || $screen === null) {
            return null;
        }

        return $screen->id;
    }

    public function deny(): void
    {
        wp_die(
            'You are not allowed to perform this action.',
            'Forbidden',
            ['response' => 403],
        );
    }

    public function redirectToAdminPage(string $pageSlug): void
    {
        wp_safe_redirect(admin_url('admin.php?page=' . rawurlencode($pageSlug)));
        exit;
    }

    public function redirectBack(string $fallbackUrl = ''): void
    {
        $referer = wp_get_referer();
        $target = is_string($referer) && $referer !== '' ? $referer : $fallbackUrl;
        if ($target === '') {
            $target = admin_url();
        }

        wp_safe_redirect($target);
        exit;
    }
}
