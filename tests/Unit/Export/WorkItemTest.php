<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\WorkItem;
use LumeWeb\Cast\Export\WorkItemFactory;
use LumeWeb\Cast\Export\WorkItemKind;
use PHPUnit\Framework\TestCase;

final class WorkItemTest extends TestCase
{
    private WorkItemFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new WorkItemFactory();
    }

    public function testK1PageQueryKeepsSortedQueryAndStaysUniqueVersusRoot(): void
    {
        $item = $this->factory->fromString('https://example.com/?s=hello');

        self::assertSame(WorkItemKind::Page, $item->kind());
        self::assertSame('https://example.com/?s=hello', $item->identity());
        self::assertSame(md5('https://example.com/?s=hello'), $item->urlHash());
        self::assertSame('__qs/' . substr(md5('/?s=hello'), 0, 12) . '/index.html', $item->outputPath());

        $root = $this->factory->fromString('https://example.com/');
        self::assertNotSame($root->urlHash(), $item->urlHash());
        self::assertNotSame($root->outputPath(), $item->outputPath());
    }

    public function testK1AssetVerDropsCacheBustingQuery(): void
    {
        $item = $this->factory->fromString('https://example.com/wp-content/themes/x/style.css?ver=6.7.1');

        self::assertSame(WorkItemKind::Asset, $item->kind());
        self::assertSame('https://example.com/wp-content/themes/x/style.css', $item->identity());
        self::assertSame(md5('https://example.com/wp-content/themes/x/style.css'), $item->urlHash());
        self::assertSame('wp-content/themes/x/style.css', $item->outputPath());
    }

    public function testK1FragmentSharesIdentityWithPrettyUrl(): void
    {
        $withFragment = $this->factory->fromString('https://example.com/about/#team');
        $pretty = $this->factory->fromString('https://example.com/about/');

        self::assertSame($pretty->identity(), $withFragment->identity());
        self::assertSame($pretty->urlHash(), $withFragment->urlHash());
        self::assertSame('about/index.html', $withFragment->outputPath());
    }

    public function testK1LongQueryHashDoesNotTruncate(): void
    {
        $longQuery = 'q=' . str_repeat('x', 300);
        $item = $this->factory->fromString('https://example.com/search?' . $longQuery);

        self::assertGreaterThan(255, strlen($item->identity()));
        self::assertSame(32, strlen($item->urlHash()));
        self::assertSame(md5($item->identity()), $item->urlHash());

        $other = $this->factory->fromString('https://example.com/search?' . str_repeat('y', 300));
        self::assertNotSame($item->urlHash(), $other->urlHash());
    }

    public function testSortedQueryOrderProducesIdenticalWorkItem(): void
    {
        $a = $this->factory->fromString('https://example.com/?b=2&a=1');
        $b = $this->factory->fromString('https://example.com/?a=1&b=2');

        self::assertSame($a->identity(), $b->identity());
        self::assertSame($a->urlHash(), $b->urlHash());
        self::assertSame($a->outputPath(), $b->outputPath());
    }

    public function testWorkItemExposesTypedUrlAndKind(): void
    {
        $item = $this->factory->fromString('https://example.com/about/');

        self::assertInstanceOf(WorkItem::class, $item);
        self::assertSame('/about/', $item->url()->path());
        self::assertSame('https', $item->url()->scheme());
        self::assertSame(WorkItemKind::Page, $item->kind());
    }
}
