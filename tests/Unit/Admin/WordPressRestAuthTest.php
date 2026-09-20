<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Admin;

use LumeWeb\Cast\Admin\WordPressRestAuth;
use PHPUnit\Framework\TestCase;

/**
 * WordPressRestAuth against the bootstrap shim: current_user_can is driven by
 * the lumeweb_cast_current_user_can global, wp_verify_nonce by the
 * lumeweb_cast_verify_nonce global and the REST nonce is read from the real
 * HTTP_X_WP_NONCE server header — no extra WordPress boundary object needed.
 */
final class WordPressRestAuthTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['lumeweb_cast_current_user_can'] = true;
        $GLOBALS['lumeweb_cast_verify_nonce'] = true;
        unset($_SERVER['HTTP_X_WP_NONCE']);
    }

    public function testCurrentUserCanDefersToWordPressCapability(): void
    {
        $auth = new WordPressRestAuth();

        $GLOBALS['lumeweb_cast_current_user_can'] = true;
        self::assertTrue($auth->currentUserCan('manage_options'));

        $GLOBALS['lumeweb_cast_current_user_can'] = false;
        self::assertFalse($auth->currentUserCan('manage_options'));
    }

    public function testRestNonceValidWhenHeaderPresentAndVerificationPasses(): void
    {
        $_SERVER['HTTP_X_WP_NONCE'] = 'rest-nonce';

        self::assertTrue((new WordPressRestAuth())->hasValidRestNonce());
    }

    public function testRestNonceInvalidWhenHeaderMissing(): void
    {
        self::assertFalse((new WordPressRestAuth())->hasValidRestNonce());
    }

    public function testRestNonceInvalidWhenHeaderEmpty(): void
    {
        $_SERVER['HTTP_X_WP_NONCE'] = '';

        self::assertFalse((new WordPressRestAuth())->hasValidRestNonce());
    }

    public function testRestNonceInvalidWhenVerificationFails(): void
    {
        $_SERVER['HTTP_X_WP_NONCE'] = 'stale-nonce';
        $GLOBALS['lumeweb_cast_verify_nonce'] = false;

        self::assertFalse((new WordPressRestAuth())->hasValidRestNonce());
    }
}
