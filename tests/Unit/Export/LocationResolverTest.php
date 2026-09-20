<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\InvalidUrl;
use LumeWeb\Cast\Export\LocationResolver;
use LumeWeb\Cast\Export\Url;
use LumeWeb\Cast\Export\UrlCanonicalizer;
use PHPUnit\Framework\TestCase;

final class LocationResolverTest extends TestCase
{
    private LocationResolver $resolver;
    private UrlCanonicalizer $canonicalizer;

    protected function setUp(): void
    {
        $this->resolver = new LocationResolver();
        $this->canonicalizer = new UrlCanonicalizer();
    }

    public function testAbsoluteLocationWins(): void
    {
        $base = $this->canonicalize('https://example.com/old/');

        self::assertSame(
            'https://example.com/new/',
            (string) $this->resolver->resolve('https://example.com/new/', $base)
        );
    }

    public function testSchemeSwitchAbsoluteLocation(): void
    {
        $base = $this->canonicalize('https://example.com/old');

        self::assertSame(
            'http://example.com/other',
            (string) $this->resolver->resolve('http://example.com/other', $base)
        );
    }

    public function testRootRelativeLocation(): void
    {
        $base = $this->canonicalize('https://example.com/a/b/c.html');

        self::assertSame(
            'https://example.com/root/x',
            (string) $this->resolver->resolve('/root/x', $base)
        );
    }

    public function testRelativeLocationKeepsBaseDirectory(): void
    {
        // Base with a trailing slash is a directory: the target resolves inside it.
        $base = $this->canonicalize('https://example.com/dir/page-a/');

        self::assertSame(
            'https://example.com/dir/page-a/page-b',
            (string) $this->resolver->resolve('page-b', $base)
        );

        // Base is a file: the relative target replaces the basename.
        $fileBase = $this->canonicalize('https://example.com/dir/a.html');

        self::assertSame(
            'https://example.com/dir/b.html',
            (string) $this->resolver->resolve('b.html', $fileBase)
        );
    }

    public function testParentTraversalIsResolved(): void
    {
        $base = $this->canonicalize('https://example.com/a/b/c.html');

        self::assertSame(
            'https://example.com/a/x/',
            (string) $this->resolver->resolve('../x/', $base)
        );

        self::assertSame(
            'https://example.com/x',
            (string) $this->resolver->resolve('../../x', $base)
        );
    }

    public function testDotSegmentsCollapse(): void
    {
        $base = $this->canonicalize('https://example.com/a/');

        self::assertSame(
            'https://example.com/a/b',
            (string) $this->resolver->resolve('./b', $base)
        );
    }

    public function testQueryOnlyLocationKeepsPath(): void
    {
        $base = $this->canonicalize('https://example.com/path/');

        self::assertSame(
            'https://example.com/path/?s=hello',
            (string) $this->resolver->resolve('?s=hello', $base)
        );
    }

    public function testFragmentOnlyLocationIsSameDocument(): void
    {
        $base = $this->canonicalize('https://example.com/path/');

        self::assertSame(
            'https://example.com/path/',
            (string) $this->resolver->resolve('#section', $base)
        );
    }

    public function testProtocolRelativeUsesBaseScheme(): void
    {
        $base = $this->canonicalize('https://example.com/a/');

        self::assertSame(
            'https://cdn.example.com/lib.js',
            (string) $this->resolver->resolve('//cdn.example.com/lib.js', $base)
        );
    }

    public function testEmptyLocationIsRejected(): void
    {
        $base = $this->canonicalize('https://example.com/a/');

        $this->expectException(InvalidUrl::class);
        $this->resolver->resolve('', $base);
    }

    private function canonicalize(string $raw): Url
    {
        return $this->canonicalizer->canonicalize($raw);
    }
}
