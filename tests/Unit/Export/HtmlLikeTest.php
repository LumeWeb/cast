<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\HtmlLike;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HtmlLikeTest extends TestCase
{
    #[DataProvider('contentCases')]
    public function testIsNonGhost(string $content, bool $expected): void
    {
        self::assertSame($expected, HtmlLike::isNonGhost($content));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function contentCases(): array
    {
        $under1k = str_repeat('x', 900);

        return [
            'short but real html is still ghost' => [$under1k . '<html></html>', false],
            'exactly 1024 bytes with html marker is non-ghost' => [str_repeat('x', 1024 - 5) . '<html>', true],
            'large text without html marker is ghost' => [str_repeat('x', 4096), false],
            'large html with uppercase marker is non-ghost' => [str_repeat('x', 2000) . '<HTML>', true],
            'large html is non-ghost' => [str_repeat('x', 2000) . '<html><body>hi</body></html>', true],
            'empty is ghost' => ['', false],
        ];
    }
}
