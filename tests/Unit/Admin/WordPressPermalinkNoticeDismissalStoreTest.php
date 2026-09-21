<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Admin;

use LumeWeb\Cast\Admin\WordPressPermalinkNoticeDismissalStore;
use PHPUnit\Framework\TestCase;

/**
 * The production {@see \LumeWeb\Cast\Admin\PermalinkNoticeDismissalStore}:
 * per-user persistence for the dismissible permalink notice, backed by
 * WordPress user-meta under its own key. These tests pin the user-meta
 * contract — the notice defaults to shown, a dismissal is durable and
 * user-scoped, clearing re-arms it — plus the anonymous-user guard, mirroring
 * the publish-prompt notice store.
 */
final class WordPressPermalinkNoticeDismissalStoreTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['lumeweb_cast_user_meta'] = [];
        $GLOBALS['lumeweb_cast_current_user_id'] = 5;
    }

    public function testDefaultsToNotDismissed(): void
    {
        self::assertFalse((new WordPressPermalinkNoticeDismissalStore())->currentUserDismissed());
    }

    public function testDismissPersistsForTheCurrentUserOnly(): void
    {
        $store = new WordPressPermalinkNoticeDismissalStore();

        $store->dismiss();

        self::assertTrue($store->currentUserDismissed());

        // A different user does not inherit the current user's dismissal.
        $GLOBALS['lumeweb_cast_current_user_id'] = 9;
        self::assertFalse($store->currentUserDismissed());
    }

    public function testClearReArmsTheNotice(): void
    {
        $store = new WordPressPermalinkNoticeDismissalStore();
        $store->dismiss();
        self::assertTrue($store->currentUserDismissed());

        $store->clear();

        self::assertFalse($store->currentUserDismissed());
        self::assertSame([], $GLOBALS['lumeweb_cast_user_meta'][5] ?? []);
    }

    public function testAnonymousVisitorIsNeverWrittenTo(): void
    {
        $GLOBALS['lumeweb_cast_current_user_id'] = 0;
        $store = new WordPressPermalinkNoticeDismissalStore();

        $store->dismiss();
        self::assertFalse($store->currentUserDismissed());
        self::assertSame([], $GLOBALS['lumeweb_cast_user_meta']);

        $store->clear();
        self::assertSame([], $GLOBALS['lumeweb_cast_user_meta']);
    }

    public function testDismissalKeyIsIndependentFromThePublishNoticeKey(): void
    {
        // The publish-prompt dismissal and the permalink dismissal live under
        // different user-meta keys, so dismissing one never hides the other.
        update_user_meta(5, 'cast_publish_notice_dismissed', '1');

        $store = new WordPressPermalinkNoticeDismissalStore();

        self::assertFalse($store->currentUserDismissed());

        // Restore the shared user-meta global: this is the last test in the
        // file and there is no later setUp to reset it, so leave the state
        // clean for downstream suites (each file must not leak between files).
        $GLOBALS['lumeweb_cast_user_meta'] = [];
        $GLOBALS['lumeweb_cast_current_user_id'] = 5;
    }
}
