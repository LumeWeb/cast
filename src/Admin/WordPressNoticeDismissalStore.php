<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

/**
 * User-meta-backed {@see NoticeDismissalStore} for the publish-prompt notice.
 *
 * Persists the current user's dismissal under a single `cast_publish_notice_dismissed`
 * user-meta key. Anonymous visitors (user id 0) are never written to, and a
 * missing flag reads as not-dismissed, so the notice's default is to be shown.
 */
final class WordPressNoticeDismissalStore implements NoticeDismissalStore
{
    private const USER_META_KEY = 'cast_publish_notice_dismissed';

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
