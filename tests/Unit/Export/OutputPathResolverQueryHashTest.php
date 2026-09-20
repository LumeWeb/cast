<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\OutputPathResolver;
use LumeWeb\Cast\Export\UrlCanonicalizer;
use LumeWeb\Cast\Export\WorkItemKind;
use PHPUnit\Framework\TestCase;

/**
 * The __qs storage bucket for a query page is keyed on the path plus the
 * query, so two distinct pages that happen to carry the identical query string
 * never collide onto one file while query-order variants keep their shared
 * bucket. Distinct paths must therefore yield distinct __qs hashes.
 */
final class OutputPathResolverQueryHashTest extends TestCase
{
    private OutputPathResolver $resolver;
    private UrlCanonicalizer $canonicalizer;

    protected function setUp(): void
    {
        $this->resolver = new OutputPathResolver();
        $this->canonicalizer = new UrlCanonicalizer();
    }

    public function testSameQueryOnDifferentPathsProducesDifferentOutputPaths(): void
    {
        $search = $this->resolve('https://example.com/search?a=1');
        $product = $this->resolve('https://example.com/product?a=1');

        self::assertNotSame(
            $product,
            $search,
            'identical queries on distinct paths must never overwrite each other',
        );
        self::assertMatchesRegularExpression('#^__qs/[0-9a-f]{12}/index\.html$#', $search);
        self::assertMatchesRegularExpression('#^__qs/[0-9a-f]{12}/index\.html$#', $product);
    }

    public function testSamePathWithDifferentlySortedQuerySharesOneStoragePath(): void
    {
        $sortedForward = $this->resolve('https://example.com/?b=2&a=1');
        $sortedBack = $this->resolve('https://example.com/?a=1&b=2');

        self::assertSame(
            $sortedBack,
            $sortedForward,
            'query-order variants of the same page keep one storage path',
        );
        self::assertStringStartsWith('__qs/', $sortedForward);
    }

    private function resolve(string $raw): string
    {
        $url = $this->canonicalizer->canonicalize($raw);

        return $this->resolver->resolve($url, WorkItemKind::Page);
    }
}
