<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\DirectoryCursor;
use PHPUnit\Framework\TestCase;

final class DirectoryCursorTest extends TestCase
{
    public function testStartsBeforeFirstEntryWithDefaultLimit(): void
    {
        $cursor = new DirectoryCursor();

        self::assertNull($cursor->lastEntry());
        self::assertSame(500, $cursor->limit());
    }

    public function testHasMoreDependsDeterministicallyOnBatchSize(): void
    {
        $cursor = new DirectoryCursor(500);

        self::assertTrue($cursor->hasMore(500), 'a full batch may have more entries');
        self::assertFalse($cursor->hasMore(499), 'a short batch is drained');
        self::assertFalse($cursor->hasMore(0), 'an empty batch is drained');
    }

    public function testAfterAdvanceIsImmutable(): void
    {
        $cursor = new DirectoryCursor(500, null);
        $next = $cursor->after('wp-content/themes/x/style.css');

        self::assertSame('wp-content/themes/x/style.css', $next->lastEntry());
        self::assertSame(500, $next->limit());
        self::assertNull($cursor->lastEntry(), 'the source cursor is immutable');
    }

    public function testAfterRefusesToGoBackwards(): void
    {
        $cursor = new DirectoryCursor(500, 'b.css');

        $this->expectException(\InvalidArgumentException::class);
        $cursor->after('a.css');
    }

    public function testTokenRoundTripsWithSpecialCharacters(): void
    {
        $cursor = new DirectoryCursor(500, 'wp-content/uploads/2024/a file:with:colons.css');

        $token = $cursor->toToken();
        $recovered = DirectoryCursor::fromToken($token);

        self::assertSame($cursor->lastEntry(), $recovered->lastEntry());
        self::assertSame($cursor->limit(), $recovered->limit());
    }

    public function testEmptyTokenMeansNoResumePointYet(): void
    {
        $cursor = new DirectoryCursor(500, null);

        $recovered = DirectoryCursor::fromToken($cursor->toToken());
        self::assertNull($recovered->lastEntry());
    }

    public function testFromTokenRejectsMalformedToken(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DirectoryCursor::fromToken('dir:@@@not-base64@@@');
    }
}
