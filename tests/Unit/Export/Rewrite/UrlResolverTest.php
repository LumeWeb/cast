<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export\Rewrite;

use LumeWeb\Cast\Export\Rewrite\UrlResolution;
use LumeWeb\Cast\Export\Rewrite\UrlResolver;
use LumeWeb\Cast\Export\Url;
use PHPUnit\Framework\TestCase;

final class UrlResolverTest extends TestCase
{
    private UrlResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new UrlResolver();
    }

    private function document(): Url
    {
        return new Url('https', 'example.com', null, '/2013/01/11/page-a/', '');
    }

    public function testFragmentOnlyIsSkippedUnchanged(): void
    {
        $resolution = $this->resolver->resolve('#section-2', $this->document());

        self::assertTrue($resolution->isSkip());
        self::assertSame('#section-2', $resolution->value());
    }

    public function testNonWebSchemesAreSkippedUnchanged(): void
    {
        foreach (['data:image/png;base64,AAA', 'javascript:void(0)', 'mailto:hi@example.com', 'tel:+15551234'] as $raw) {
            $resolution = $this->resolver->resolve($raw, $this->document());
            self::assertTrue($resolution->isSkip(), "expected skip for {$raw}");
            self::assertSame($raw, $resolution->value());
        }
    }

    public function testEmptyValueIsSkipped(): void
    {
        self::assertTrue($this->resolver->resolve('', $this->document())->isSkip());
    }

    public function testUnknownSchemeIsSkippedUnchanged(): void
    {
        $resolution = $this->resolver->resolve('blob:https://example.com/uuid', $this->document());

        self::assertTrue($resolution->isSkip());
        self::assertSame('blob:https://example.com/uuid', $resolution->value());
    }

    public function testProtocolRelativeGetsDocumentScheme(): void
    {
        $resolution = $this->resolver->resolve('//example.com/wp-content/themes/x/style.css', $this->document());

        self::assertFalse($resolution->isSkip());
        self::assertSame('https://example.com/wp-content/themes/x/style.css', $resolution->value());
    }

    public function testRootRelativeJoinsDocumentOrigin(): void
    {
        $resolution = $this->resolver->resolve('/wp-content/themes/x/style.css', $this->document());

        self::assertFalse($resolution->isSkip());
        self::assertSame('https://example.com/wp-content/themes/x/style.css', $resolution->value());
    }

    public function testAbsoluteWebUrlPassesThrough(): void
    {
        $resolution = $this->resolver->resolve('https://example.com/2013/01/10/page-b/', $this->document());

        self::assertFalse($resolution->isSkip());
        self::assertSame('https://example.com/2013/01/10/page-b/', $resolution->value());
    }

    public function testRelativePathJoinsAgainstDocumentDirectory(): void
    {
        // The document URL /2013/01/11/page-a/ is a directory (trailing slash),
        // so ../ pops the page-a segment; RFC 3986 merge yields .../11/10/page-b/.
        $resolution = $this->resolver->resolve('../10/page-b/', $this->document());

        self::assertFalse($resolution->isSkip());
        self::assertSame('https://example.com/2013/01/11/10/page-b/', $resolution->value());
    }

    public function testRootRelativeSiblingTargetUsedByK5Vector(): void
    {
        // This is the reference the offline-sibling vector uses: a root-relative
        // target /2013/01/10/page-b/ from the same page.
        $resolution = $this->resolver->resolve('/2013/01/10/page-b/', $this->document());

        self::assertSame('https://example.com/2013/01/10/page-b/', $resolution->value());
    }

    public function testPageRelativeImageJoinsAgainstDirectory(): void
    {
        $resolution = $this->resolver->resolve('img/hero.jpg', $this->document());

        self::assertFalse($resolution->isSkip());
        self::assertSame('https://example.com/2013/01/11/page-a/img/hero.jpg', $resolution->value());
    }

    public function testDotSegmentsAreRemoved(): void
    {
        $resolution = $this->resolver->resolve('./a/../b/c.css', $this->document());

        self::assertSame('https://example.com/2013/01/11/page-a/b/c.css', $resolution->value());
    }

    public function testQueryOnlyReferenceKeepsDocumentPath(): void
    {
        $resolution = $this->resolver->resolve('?s=hello', $this->document());

        self::assertFalse($resolution->isSkip());
        self::assertSame('https://example.com/2013/01/11/page-a/?s=hello', $resolution->value());
    }

    public function testRootRelativeWithQueryAndFragmentKeepsQueryDropsFragment(): void
    {
        $resolution = $this->resolver->resolve('/wp-content/themes/x/style.css?ver=6.7.1#frag', $this->document());

        self::assertSame('https://example.com/wp-content/themes/x/style.css?ver=6.7.1', $resolution->value());
    }

    public function testSchemeCaseIsLowercased(): void
    {
        $resolution = $this->resolver->resolve('HTTPS://Example.com/about/', $this->document());

        self::assertSame('https://example.com/about/', $resolution->value());
    }

    public function testDeepParentTraversalStopsAtRoot(): void
    {
        $resolution = $this->resolver->resolve('../../../../styles.css', $this->document());

        self::assertSame('https://example.com/styles.css', $resolution->value());
    }
}
