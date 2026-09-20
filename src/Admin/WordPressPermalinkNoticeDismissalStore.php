<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

/**
 * The production {@see PermalinkNoticeDismissalStore}: per-user persistence
 * for the dismissible permalink notice, backed by WordPress user-meta under
 * its own key (independent from the publish-prompt notice's key, so the two
 * surfaces can be dismissed separately).
 */
final class WordPressPermalinkNoticeDismissalStore implements PermalinkNoticeDismissalStore
{
    private const USER_META_KEY = 'cast_permalink_notice_dismissed';

    public function currentUserDismissed(): bool
    {
        $userId = get_current_user_id();

        return $userId !== 0 && (bool) get_user_meta($userId, self::USER_META_KEY, true);
    }

    public function dismiss(): void
    {
        $userId = get_current_user_id();
        if ($userId !== 0) {
            update_user_meta($userId, self::USER_META_KEY, '1');
        }
    }

    public function clear(): void
    {
        $userId = get_current_user_id();
        if ($userId !== 0) {
            delete_user_meta($userId, self::USER_META_KEY);
        }
    }
}
