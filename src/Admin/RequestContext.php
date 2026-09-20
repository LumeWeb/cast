<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

/**
 * Narrow wrapper around WordPress request/authorization primitives so admin
 * request handling stays unit-testable without a live HTTP request.
 */
interface RequestContext
{
    public function isCurrentUserAllowed(string $capability): bool;

    public function requestNonce(): ?string;

    /** The raw value of a posted/query request parameter, or null. */
    public function requestParam(string $key): mixed;

    public function nonceField(string $action): string;

    /** A fresh nonce value for the given action, localized to scripts. */
    public function createNonce(string $action): string;

    public function verifyNonce(?string $nonce, string $action): bool;

    public function currentUserId(): int;

    /**
     * The current admin screen id (e.g. 'toplevel_page_workspace-getting-started'),
     * or null when no admin screen is loaded.
     */
    public function currentScreenId(): ?string;

    /** Deny the request (production: wp_die + terminate; never returns). */
    public function deny(): void;

    /** Redirect to the admin page (production: wp_safe_redirect + exit). */
    public function redirectToAdminPage(string $pageSlug): void;

    /**
     * Redirect back to the page the request came from, falling back to the
     * given URL when no safe referer exists (production: wp_safe_redirect +
     * exit; never returns).
     */
    public function redirectBack(string $fallbackUrl = ''): void;
}
