<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

/**
 * Per-user persistence for the publish-prompt admin notice dismissal.
 *
 * The "publish to Pinner" admin notice is dismissible, and the dismissal is
 * user-scoped and durable (it survives a reload) so a user who has consciously
 * dismissed the prompt is not nagged on every screen. The store owns exactly
 * three operations: whether the current user has dismissed the notice, marking
 * it dismissed, and clearing it (called by the publish service whenever a new
 * publish is initiated, so the next round of content changes re-arms the
 * prompt). Keeping the persistence behind this wrapper keeps the WordPress
 * user-meta calls unit-testable and out of the presentation layer.
 */
interface NoticeDismissalStore
{
    /** Whether the current user has dismissed the publish-prompt notice. */
    public function currentUserDismissed(): bool;

    /** Persist that the current user dismissed the notice. */
    public function dismiss(): void;

    /** Clear the current user's dismissal (re-arm the notice). */
    public function clear(): void;
}
