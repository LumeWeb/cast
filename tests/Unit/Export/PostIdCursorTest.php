<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\PostIdCursor;
use PHPUnit\Framework\TestCase;

final class PostIdCursorTest extends TestCase
{
    public function testDefaultsAreZeroAndFifty(): void
    {
        $cursor = new PostIdCursor();

        self::assertSame(0, $cursor->lastId());
        self::assertSame(50, $cursor->limit());
    }

    public function testHasMoreDependsDeterministicallyOnBatchSize(): void
    {
        $cursor = new PostIdCursor(50, 50);

        self::assertTrue($cursor->hasMore(50), 'a full page may have more rows');
        self::assertFalse($cursor->hasMore(49), 'a short page is drained');
        self::assertFalse($cursor->hasMore(0), 'an empty page is drained');
    }

    public function testNextAdvanceIsImmutableAndKeepsLimit(): void
    {
        $cursor = new PostIdCursor(0, 50);
        $next = $cursor->next(120);

        self::assertSame(120, $next->lastId());
        self::assertSame(50, $next->limit());
        self::assertSame(0, $cursor->lastId(), 'the source cursor is immutable');
    }

    public function testNextRefusesToGoBackwards(): void
    {
        $cursor = new PostIdCursor(120, 50);

        $this->expectException(\InvalidArgumentException::class);
        $cursor->next(120);
    }

    public function testTokenRoundTripsDeterministically(): void
    {
        $cursor = new PostIdCursor(120, 50);

        self::assertSame('post:120:50', $cursor->toToken());

        $recovered = PostIdCursor::fromToken('post:120:50');
        self::assertSame($cursor->lastId(), $recovered->lastId());
        self::assertSame($cursor->limit(), $recovered->limit());
        self::assertSame($cursor->toToken(), $recovered->toToken());
    }

    public function testFromTokenRejectsMalformedToken(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PostIdCursor::fromToken('post:not-a-number:50');
    }
}
