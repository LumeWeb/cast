<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Admin;

use LumeWeb\Cast\Admin\WordPressNoticeDismissalStore;
use PHPUnit\Framework\TestCase;

/**
 * The production {@see NoticeDismissalStore}: per-user persistence for the
 * dismissible "publish to Pinner" notice, backed by WordPress user-meta. These
 * tests pin the user-meta contract — the notice defaults to shown, a dismissal
 * is durable and user-scoped, and clearing re-arms it — plus the anonymous-user
 * guard (a visitor is never written to, so the admin-only notice can never
 * leave an orphan meta row behind).
 */
final class WordPressNoticeDismissalStoreTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['lumeweb_cast_user_meta'] = [];
        $GLOBALS['lumeweb_cast_current_user_id'] = 5;
    }

    public function testDefaultsToNotDismissed(): void
    {
        self::assertFalse((new WordPressNoticeDismissalStore())->currentUserDismissed());
    }

    public function testDismissPersistsForTheCurrentUserOnly(): void
    {
        $store = new WordPressNoticeDismissalStore();

        $store->dismiss();

        self::assertTrue($store->currentUserDismissed());

        // A different user does not inherit the current user's dismissal.
        $GLOBALS['lumeweb_cast_current_user_id'] = 9;
        self::assertFalse($store->currentUserDismissed());
    }

    public function testClearReArmsTheNotice(): void
    {
        $store = new WordPressNoticeDismissalStore();
        $store->dismiss();
        self::assertTrue($store->currentUserDismissed());

        $store->clear();

        self::assertFalse($store->currentUserDismissed());
        self::assertSame([], $GLOBALS['lumeweb_cast_user_meta'][5] ?? []);
    }

    public function testAnonymousVisitorIsNeverWrittenTo(): void
    {
        $GLOBALS['lumeweb_cast_current_user_id'] = 0;
        $store = new WordPressNoticeDismissalStore();

        $store->dismiss();
        self::assertFalse($store->currentUserDismissed());
        self::assertSame([], $GLOBALS['lumeweb_cast_user_meta']);

        $store->clear();
        self::assertSame([], $GLOBALS['lumeweb_cast_user_meta']);
    }
}
