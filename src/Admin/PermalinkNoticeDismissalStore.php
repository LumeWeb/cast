<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

/**
 * Per-user persistence for the dismissible "Cast needs Day and name
 * permalinks" admin notice.
 *
 * A user who has consciously acknowledged the custom-permalink warning is not
 * nagged on every screen; the dismissal is user-scoped and durable. Keeping
 * the persistence behind this wrapper mirrors the publish-prompt notice store and
 * keeps the WordPress user-meta calls unit-testable and out of the presenter.
 */
interface PermalinkNoticeDismissalStore
{
    /** Whether the current user has dismissed the permalink notice. */
    public function currentUserDismissed(): bool;

    /** Persist that the current user dismissed the notice. */
    public function dismiss(): void;

    /** Clear the current user's dismissal (re-arm the notice). */
    public function clear(): void;
}
