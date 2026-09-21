<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\SitemapDocument;
use LumeWeb\Cast\Export\SitemapLimits;
use LumeWeb\Cast\Export\SitemapLocFilter;
use LumeWeb\Cast\Export\UrlCanonicalizer;
use PHPUnit\Framework\TestCase;

final class SitemapLocFilterTest extends TestCase
{
    private UrlCanonicalizer $canonicalizer;
    private Origin $origin;

    protected function setUp(): void
    {
        $this->canonicalizer = new UrlCanonicalizer();
        $this->origin = Origin::fromUrl($this->canonicalizer->canonicalize('https://example.com/'));
    }

    public function testDefaultLimitsMatchThePlan(): void
    {
        $limits = new SitemapLimits();

        self::assertSame(250_000, $limits->maxLocs);
        self::assertSame(5, $limits->maxDepth);
        self::assertSame(5 * 1024 * 1024, $limits->maxBodyBytes);
    }

    public function testIsLocalRejectsOffOriginAndMalformed(): void
    {
        $filter = new SitemapLocFilter();

        self::assertTrue($filter->isLocal('https://example.com/page/', $this->origin));
        self::assertFalse($filter->isLocal('https://evil.example/x', $this->origin));
        self::assertFalse($filter->isLocal('http://example.com/x', $this->origin));
        self::assertFalse($filter->isLocal('relative/loc', $this->origin));
    }

    public function testAcceptsDocumentWithinDepthAndBodyCaps(): void
    {
        $limits = new SitemapLimits(maxDepth: 5, maxBodyBytes: 1024);
        $filter = new SitemapLocFilter($limits);

        self::assertTrue($filter->accepts(new SitemapDocument('https://example.com/sitemap.xml', depth: 1, bytes: 512), $this->origin));
        self::assertTrue($filter->accepts(new SitemapDocument('https://example.com/sitemap-2.xml', depth: 5, bytes: 1024), $this->origin));
    }

    public function testAcceptsRejectsBeyondDepthAndBodyCaps(): void
    {
        $limits = new SitemapLimits(maxDepth: 5, maxBodyBytes: 1024);
        $filter = new SitemapLocFilter($limits);

        self::assertFalse($filter->accepts(new SitemapDocument('https://example.com/deep.xml', depth: 6, bytes: 10), $this->origin));
        self::assertFalse($filter->accepts(new SitemapDocument('https://example.com/big.xml', depth: 1, bytes: 1025), $this->origin));
    }

    public function testAcceptsUnknownBodySize(): void
    {
        $filter = new SitemapLocFilter(new SitemapLimits(maxBodyBytes: 10));

        self::assertTrue($filter->accepts(new SitemapDocument('https://example.com/x.xml', depth: 0, bytes: null), $this->origin));
    }

    public function testAcceptsRejectsOffOriginLoc(): void
    {
        $filter = new SitemapLocFilter();

        self::assertFalse($filter->accepts(new SitemapDocument('https://evil.example/x.xml', depth: 0), $this->origin));
    }

    public function testFilterDocumentsIsLocalOnlyAndCountBounded(): void
    {
        $filter = new SitemapLocFilter(new SitemapLimits(maxLocs: 3));

        $docs = [
            new SitemapDocument('https://example.com/1'),
            new SitemapDocument('https://evil.example/1'),
            new SitemapDocument('https://example.com/2'),
            new SitemapDocument('https://example.com/3'),
            new SitemapDocument('https://example.com/4'),
        ];

        $kept = iterator_to_array($filter->filterDocuments($docs, $this->origin));

        self::assertCount(3, $kept);
        self::assertSame(['https://example.com/1', 'https://example.com/2', 'https://example.com/3'], array_map($this->loc(), $kept));
    }

    public function testFilterDocumentsStopsIteratingAtTheCap(): void
    {
        $filter = new SitemapLocFilter(new SitemapLimits(maxLocs: 2));

        $seen = 0;
        $source = (function () use (&$seen) {
            while (true) {
                ++$seen;
                yield new SitemapDocument('https://example.com/' . $seen);
            }
        })();

        $kept = iterator_to_array($filter->filterDocuments($source, $this->origin));

        self::assertCount(2, $kept);
        self::assertLessThanOrEqual(2, $seen, 'the filter must stop pulling from an unbounded source');
    }

    public function testFilterDocumentsAppliesDepthAndBodyCaps(): void
    {
        $filter = new SitemapLocFilter(new SitemapLimits(maxLocs: 10, maxDepth: 2, maxBodyBytes: 100));

        $docs = [
            new SitemapDocument('https://example.com/a', depth: 3, bytes: 10),
            new SitemapDocument('https://example.com/b', depth: 2, bytes: 100),
            new SitemapDocument('https://example.com/c', depth: 1, bytes: 500),
        ];

        $kept = iterator_to_array($filter->filterDocuments($docs, $this->origin));

        self::assertSame(['https://example.com/b'], array_map($this->loc(), $kept));
    }

    /**
     * @return callable(SitemapDocument): string
     */
    private function loc(): callable
    {
        return static fn (SitemapDocument $doc): string => $doc->loc;
    }
}
