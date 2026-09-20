<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\WorkItem;
use LumeWeb\Cast\Export\WorkItemFactory;
use LumeWeb\Cast\Export\WorkItemKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Closure: two URLs that decode to the same resource must converge to one
 * identity, one 32-char hash, and one deterministic output path through
 * WorkItemFactory, no matter how their raw spellings differ (query order,
 * cache-busting version params, fragment, or over-encoded %25).
 */
final class WorkItemFactoryK1ClosureTest extends TestCase
{
    private WorkItemFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new WorkItemFactory();
    }

    #[DataProvider('convergingPairsProvider')]
    public function testDecodedEquivalentUrlsConvergeOnIdentityHashAndPath(
        string $left,
        string $right,
        WorkItemKind $expectedKind,
    ): void {
        $a = $this->factory->fromString($left);
        $b = $this->factory->fromString($right);

        self::assertSame($expectedKind, $a->kind());
        self::assertSame($expectedKind, $b->kind());
        self::assertSame($a->identity(), $b->identity(), 'identities must converge');
        self::assertSame($a->urlHash(), $b->urlHash(), 'hashes must converge');
        self::assertSame($a->outputPath(), $b->outputPath(), 'output paths must converge');
        self::assertSame(32, strlen($a->urlHash()));
        self::assertSame(md5($a->identity()), $a->urlHash());
    }

    /**
     * @return iterable<string, array{string, string, WorkItemKind}>
     */
    public static function convergingPairsProvider(): iterable
    {
        yield 'page query order variants converge' => [
            'https://example.com/?b=2&a=1',
            'https://example.com/?a=1&b=2',
            WorkItemKind::Page,
        ];

        yield 'page over-encoded query converges with single-encoded' => [
            'https://example.com/search?q=a%2520b',
            'https://example.com/search?q=a%20b',
            WorkItemKind::Page,
        ];

        yield 'long page query over-encoded versus single-encoded converge' => [
            'https://example.com/search?q=' . str_repeat('x', 200) . '%2520tail',
            'https://example.com/search?q=' . str_repeat('x', 200) . '%20tail',
            WorkItemKind::Page,
        ];

        yield 'fragment variants converge on pretty page' => [
            'https://example.com/about/#team',
            'https://example.com/about/',
            WorkItemKind::Page,
        ];

        yield 'asset cache-buster variants converge with query dropped' => [
            'https://example.com/wp-content/themes/x/style.css?ver=6.7.1',
            'https://example.com/wp-content/themes/x/style.css?ver=6.7.2',
            WorkItemKind::Asset,
        ];

        yield 'asset over-encoded filename converges with single-encoded' => [
            'https://example.com/wp-content/themes/x/snow%2520flake.jpg?v=1',
            'https://example.com/wp-content/themes/x/snow%20flake.jpg?ver=2',
            WorkItemKind::Asset,
        ];

        yield 'page with over-encoded query output path stays under query bucket' => [
            'https://example.com/?q=1%2520x',
            'https://example.com/?q=1%20x',
            WorkItemKind::Page,
        ];
    }

    public function testConvergedPageWritesToHashedQueryOutputPath(): void
    {
        $item = $this->factory->fromString('https://example.com/?q=a%2520b');

        self::assertSame('__qs/' . substr(md5('/?q=a%20b'), 0, 12) . '/index.html', $item->outputPath());
    }

    public function testConvergedAssetRetainsCollapsedFilenameInOutputPath(): void
    {
        $item = $this->factory->fromString('https://example.com/snow%2520flake.jpg?ver=1');

        self::assertSame('snow%20flake.jpg', $item->outputPath());
        self::assertNotSame("snow flake.jpg", $item->outputPath(), 'a raw space must never reach the output path');
    }
}
